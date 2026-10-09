<?php

namespace App\Tests\Controller;

use App\Entity\SupportTicket;
use App\Entity\SupportTicketMessage;
use App\Entity\Notification;
use App\Entity\User;
use App\Support\TicketAttachments;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

final class SupportTicketControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;
    private array $storedPaths = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get('cache.rate_limiter')->clear();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        foreach ($this->storedPaths as $path) {
            if (is_file($path)) unlink($path);
            if (is_dir(dirname($path)) && count(scandir(dirname($path))) === 2) rmdir(dirname($path));
        }
        parent::tearDown();
    }

    public function testGuestSubmissionReceiptTrackingAndIdempotentRetry(): void
    {
        $data = $this->data();
        $csrf = $this->csrf();
        $this->post('/api/support/tickets?locale=en', $data, $csrf);
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload()['data'];
        self::assertSame('T-0001', $created['number']);
        self::assertTrue($created['receiptSent']);
        self::assertMatchesRegularExpression('~/en/support/ticket#token=[a-f0-9]{64}$~', $created['trackingUrl']);
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('alex@example.test', $email->getTo()[0]->getAddress());
        self::assertEmailTextBodyContains($email, $created['trackingUrl']);
        self::assertEmailHtmlBodyContains($email, 'Track ticket');
        self::assertStringNotContainsString($data['description'], $email->getTextBody());
        $this->post('/api/support/tickets?locale=en', $data, $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame($created, $this->payload()['data']);
        self::assertEmailCount(0);
        self::assertSame(1, static::getContainer()->get(EntityManagerInterface::class)->getRepository(SupportTicket::class)->count([]));
        $token = substr($created['trackingUrl'], strpos($created['trackingUrl'], '#token=') + 7);
        $this->post('/api/support/tickets/track?locale=en', ['token' => $token], $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame('open', $this->payload()['data']['status']);
        self::assertArrayNotHasKey('email', $this->payload()['data']);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $this->post('/api/support/tickets/track?locale=en', ['token' => str_repeat('a', 64)], $csrf);
        self::assertResponseStatusCodeSame(404);
    }

    public function testSubmissionValidationCsrfAndRateLimit(): void
    {
        $this->post('/api/support/tickets?locale=en', $this->data(), '');
        self::assertResponseStatusCodeSame(403);
        $csrf = $this->csrf();
        foreach ([['email' => 'bad'], ['kind' => 'unknown'], ['description' => 'short'], ['category' => 'payments'], ['trap' => 'bot']] as $changes) {
            $this->post('/api/support/tickets?locale=en', array_replace($this->data(), $changes), $csrf);
            self::assertResponseStatusCodeSame(400);
        }
        $this->post('/api/support/tickets?locale=en', $this->data(), $csrf);
        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
        self::assertEmailCount(0);
        $this->post('/api/support/tickets?locale=xx', $this->data(), $csrf);
        self::assertResponseStatusCodeSame(400);
    }

    public function testPrivateAttachmentsAndAdminOnlyPaginatedList(): void
    {
        $csrf = $this->csrf();
        $path = tempnam(sys_get_temp_dir(), 'wave-support-test-');
        $image = imagecreatetruecolor(3200, 1600);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 80, 70));
        imagepng($image, $path, 1);
        unset($image);
        $originalSize = filesize($path);
        $file = new UploadedFile($path, '../proof.png', 'image/png', null, true);
        $this->client->request('POST', '/api/support/tickets?locale=bs', $this->data(), ['attachments' => [$file]], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $ticket = $em->getRepository(SupportTicket::class)->findOneBy([]);
        $storage = static::getContainer()->get(TicketAttachments::class);
        $storedPath = $storage->path($ticket, $ticket->getAttachments()[0]);
        $this->storedPaths[] = $storedPath;
        self::assertFileExists($storedPath);
        self::assertStringNotContainsString('/public/', $storedPath);
        self::assertSame('image/webp', $ticket->getAttachments()[0]['mime']);
        self::assertSame([2560, 1280], array_slice(getimagesize($storedPath), 0, 2));
        self::assertLessThan($originalSize, $ticket->getAttachments()[0]['size']);
        self::assertStringEndsWith('.webp', $ticket->getAttachments()[0]['name']);
        $download = '/api/admin/support-tickets/'.$ticket->getId().'/attachments/0';
        foreach (['/api/admin/support-tickets', $download] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(401);
        }
        foreach ([false, true] as $moderator) {
            $user = $this->user();
            $user->setModerator($moderator);
            $em->flush();
            $this->client->loginUser($user, 'main');
            foreach (['/api/admin/support-tickets', $download] as $url) {
                $this->client->request('GET', $url);
                self::assertResponseStatusCodeSame(403);
            }
        }
        $this->client->loginUser($this->user(true), 'main');
        for ($index = 0; $index < 27; ++$index) $em->persist(new SupportTicket($this->data(), 'en', bin2hex(random_bytes(32))));
        $em->flush();
        $this->client->request('GET', '/api/admin/support-tickets?limit=25');
        self::assertResponseIsSuccessful();
        $payload = $this->payload();
        self::assertSame(28, $payload['meta']['counts']['all']);
        self::assertCount(25, $payload['data']);
        self::assertGreaterThan($payload['data'][24]['id'], $payload['data'][0]['id']);
        self::assertArrayNotHasKey('trackingToken', $payload['data'][0]);
        $this->client->request('GET', '/api/admin/support-tickets?limit=25&cursor='.urlencode($payload['meta']['nextCursor']));
        self::assertFalse($this->payload()['meta']['hasMore']);
        self::assertCount(3, $this->payload()['data']);
        foreach (['limit=0', 'limit=51', 'locale=xx', 'cursor=garbage', 'status=bad'] as $query) {
            $this->client->request('GET', '/api/admin/support-tickets?'.$query);
            self::assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', $download);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment;', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame('application/octet-stream', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        $this->client->request('GET', '/api/admin/support-tickets/999/attachments/0');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnsafeOversizedAndExcessAttachmentsAreRejectedWithoutSavingTicket(): void
    {
        $csrf = $this->csrf();
        $path = tempnam(sys_get_temp_dir(), 'wave-support-invalid-');
        file_put_contents($path, '<script>alert(1)</script>');
        $this->client->request('POST', '/api/support/tickets?locale=en', $this->data(), ['attachments' => [new UploadedFile($path, 'image.png', 'image/png', null, true)]], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);
        unlink($path);
        $path = tempnam(sys_get_temp_dir(), 'wave-support-large-');
        file_put_contents($path, "%PDF-1.4\n".str_repeat('a', TicketAttachments::MAX_BYTES));
        $this->client->request('POST', '/api/support/tickets?locale=en', $this->data(), ['attachments' => [new UploadedFile($path, 'large.pdf', 'application/pdf', null, true)]], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);
        unlink($path);
        $files = [];
        for ($index = 0; $index < 4; ++$index) {
            $path = tempnam(sys_get_temp_dir(), 'wave-support-many-');
            file_put_contents($path, "%PDF-1.4\nTest");
            $files[] = new UploadedFile($path, 'test.pdf', 'application/pdf', null, true);
        }
        $this->client->request('POST', '/api/support/tickets?locale=en', $this->data(), ['attachments' => $files], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);
        foreach ($files as $file) unlink($file->getPathname());
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(SupportTicket::class)->count([]));
    }

    public function testReceiptCopyAndRoutesExistInEveryLocale(): void
    {
        foreach (['bs', 'hr', 'sr', 'cnr', 'sl', 'en'] as $locale) {
            static::getContainer()->get('cache.rate_limiter')->clear();
            $this->post('/api/support/tickets?locale='.$locale, $this->data(), $this->csrf());
            self::assertResponseStatusCodeSame(201);
            self::assertTrue($this->payload()['data']['receiptSent']);
            self::assertEmailCount(1);
        }
    }

    public function testLoggedInOwnerOverridesContactEmailAndGuestMatchesExistingAccount(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $this->client->loginUser($owner, 'main');
        $this->post('/api/support/tickets?locale=en', array_replace($this->data(), ['email' => $other->getEmail()]), $this->csrf());
        self::assertResponseStatusCodeSame(201);
        $id = $this->payload()['data']['id'];
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame($owner->getId(), $em->find(SupportTicket::class, $id)->getOwner()->getId());
        $this->client->request('GET', '/api/me/support-tickets');
        self::assertCount(1, $this->payload()['data']);
        $this->client->loginUser($other, 'main');
        foreach (['', '/messages', '/attachments/0'] as $suffix) {
            $this->client->request('GET', '/api/me/support-tickets/'.$id.$suffix);
            self::assertResponseStatusCodeSame(404);
        }
        $this->post('/api/me/support-tickets/'.$id.'/messages', ['body' => 'Trying another account', 'submissionKey' => bin2hex(random_bytes(32))], $this->csrf());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/me/support-tickets');
        self::assertCount(0, $this->payload()['data']);
        $this->client->request('POST', '/api/auth/logout', server: ['HTTP_X_CSRF_TOKEN' => $this->csrf()]);
        $this->post('/api/support/tickets?locale=en', array_replace($this->data(), ['email' => strtoupper($other->getEmail())]), $this->csrf());
        self::assertResponseStatusCodeSame(201);
        $guestId = $this->payload()['data']['id'];
        self::assertSame($other->getId(), $em->find(SupportTicket::class, $guestId)->getOwner()->getId());
    }

    public function testOnlyVerifiedAccountCanClaimEarlierUnlinkedReports(): void
    {
        $owner = $this->user();
        $owner->setApproved(false);
        $owner->setEmailVerified(false);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $ticket = new SupportTicket(array_replace($this->data(), ['email' => $owner->getEmail()]), 'en', bin2hex(random_bytes(32)));
        $em->persist($ticket); $em->flush();
        $this->client->loginUser($owner, 'main');
        $this->client->request('GET', '/api/me/support-tickets');
        self::assertCount(0, $this->payload()['data']);
        $em->find(User::class, $owner->getId())->setEmailVerified(true); $em->flush();
        $this->client->request('GET', '/api/me/support-tickets');
        self::assertCount(1, $this->payload()['data']);
        $ticket = $em->find(SupportTicket::class, $ticket->getId());
        self::assertSame($owner->getId(), $ticket->getOwner()->getId());
        $this->client->request('GET', '/api/me/support-tickets/'.$ticket->getId());
        self::assertResponseIsSuccessful();
    }

    public function testPublicRepliesNotifyAndEmailButInternalNotesRemainPrivate(): void
    {
        $owner = $this->user();
        $admin = $this->user(true);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $ticket = new SupportTicket(array_replace($this->data(), ['email' => 'different-contact@example.test']), 'en', bin2hex(random_bytes(32)));
        $ticket->setOwner($owner); $em->persist($ticket); $em->flush();
        $id = $ticket->getId();
        $this->client->loginUser($admin, 'main');
        $key = bin2hex(random_bytes(32));
        $reply = ['body' => 'Please retry now. The bug is fixed.', 'submissionKey' => $key];
        $this->post('/api/admin/support-tickets/'.$id.'/messages?locale=en', $reply, $this->csrf());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('in_progress', $this->payload()['status']);
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame($owner->getEmail(), $email->getTo()[0]->getAddress());
        self::assertStringContainsString('?ticket='.$id, $email->getTextBody());
        self::assertStringNotContainsString($reply['body'], $email->getTextBody());
        self::assertSame(1, $em->getRepository(Notification::class)->count(['recipient' => $owner, 'supportTicket' => $ticket]));
        $this->post('/api/admin/support-tickets/'.$id.'/messages?locale=en', $reply, $this->csrf());
        self::assertResponseIsSuccessful(); self::assertEmailCount(0);
        self::assertSame(2, $em->getRepository(SupportTicketMessage::class)->count(['ticket' => $ticket]));
        self::assertSame('in_progress', $this->payload()['statusEvent']['eventStatus']);
        $this->post('/api/admin/support-tickets/'.$id.'/messages?locale=en', ['body' => 'Private debugging credentials must not be exposed', 'submissionKey' => bin2hex(random_bytes(32)), 'internal' => true], $this->csrf());
        self::assertResponseStatusCodeSame(201); self::assertEmailCount(0);
        $noteId = $this->payload()['data']['id'];
        self::assertSame($reply['body'], $em->find(SupportTicket::class, $id)->getLastReplyPreview());
        $this->client->loginUser($owner, 'main');
        $this->client->request('GET', '/api/me/support-tickets/'.$id.'/messages');
        self::assertCount(2, $this->payload()['data']);
        self::assertStringNotContainsString('Private debugging', $this->client->getResponse()->getContent());
        $this->client->request('GET', '/api/me/support-tickets/'.$id.'/messages/'.$noteId.'/attachments/0');
        self::assertResponseStatusCodeSame(404);
        $this->post('/api/me/support-tickets/'.$id.'/messages', ['body' => 'Sneaky internal note', 'submissionKey' => bin2hex(random_bytes(32)), 'internal' => true], $this->csrf());
        self::assertResponseStatusCodeSame(403);
        $this->post('/api/me/support-tickets/'.$id.'/messages', ['body' => 'Thanks. It works now.', 'submissionKey' => bin2hex(random_bytes(32))], $this->csrf());
        self::assertResponseStatusCodeSame(201); self::assertEmailCount(1);
        self::assertSame(1, $em->getRepository(Notification::class)->count(['recipient' => $admin, 'supportTicket' => $ticket]));
        $this->post('/api/support/tickets/track', ['token' => $ticket->getTrackingToken()], $this->csrf());
        self::assertCount(4, $this->payload()['data']['messages']);
        self::assertStringNotContainsString('Private debugging', $this->client->getResponse()->getContent());
    }

    public function testCursorListsHistoryAndReadWatermarkDoNotLeakOrClearNewerAlerts(): void
    {
        $owner = $this->user(); $admin = $this->user(true);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tickets = [];
        for ($i = 0; $i < 7; ++$i) {
            $ticket = new SupportTicket($this->data(), 'en', bin2hex(random_bytes(32)));
            $ticket->setOwner($owner); $em->persist($ticket); $tickets[] = $ticket;
        }
        $em->flush();
        $ticket = $tickets[0];
        $messageIds = [];
        for ($i = 0; $i < 7; ++$i) {
            $message = new SupportTicketMessage($ticket, $owner, 'Reply '.$i, false, bin2hex(random_bytes(32)));
            $em->persist($message); $em->flush(); $messageIds[] = $message->getId();
        }
        $this->client->loginUser($owner, 'main');
        $ids = []; $cursor = '';
        do {
            $this->client->request('GET', '/api/me/support-tickets?limit=3'.($cursor ? '&cursor='.urlencode($cursor) : ''));
            self::assertResponseIsSuccessful();
            $page = $this->payload(); $ids = [...$ids, ...array_column($page['data'], 'id')]; $cursor = $page['meta']['nextCursor'];
        } while ($page['meta']['hasMore']);
        self::assertCount(7, array_unique($ids)); self::assertCount(7, $ids);
        $history = []; $before = 0;
        do {
            $this->client->request('GET', '/api/me/support-tickets/'.$ticket->getId().'/messages?limit=3'.($before ? '&before='.$before : ''));
            $page = $this->payload(); $history = [...array_column($page['data'], 'id'), ...$history]; $before = $page['meta']['nextCursor'];
        } while ($page['meta']['hasMore']);
        self::assertSame($messageIds, $history);
        $owner = $em->find(User::class, $owner->getId()); $admin = $em->find(User::class, $admin->getId()); $ticket = $em->find(SupportTicket::class, $ticket->getId());
        $first = new Notification($owner, 'support_ticket_reply', $admin, supportTicket: $ticket); $em->persist($first); $em->flush();
        $this->client->request('GET', '/api/me/support-tickets/'.$ticket->getId());
        $through = $this->payload()['data']['throughNotificationId'];
        $firstId = $first->getId();
        $owner = $em->find(User::class, $owner->getId()); $admin = $em->find(User::class, $admin->getId()); $ticket = $em->find(SupportTicket::class, $ticket->getId());
        $second = new Notification($owner, 'support_ticket_reply', $admin, supportTicket: $ticket); $em->persist($second); $em->flush();
        $this->post('/api/me/support-tickets/'.$ticket->getId().'/read', ['throughNotificationId' => $through], $this->csrf());
        self::assertResponseIsSuccessful(); $first = $em->find(Notification::class, $firstId); $second = $em->find(Notification::class, $second->getId());
        self::assertNotNull($first->getReadAt()); self::assertNull($second->getReadAt());
    }

    public function testAdminUpdatesAreCsrfProtectedAndRestrictedToSupportStaff(): void
    {
        $owner = $this->user(); $admin = $this->user(true);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $ticket = new SupportTicket($this->data(), 'en', bin2hex(random_bytes(32))); $ticket->setOwner($owner); $em->persist($ticket); $em->flush();
        $url = '/api/admin/support-tickets/'.$ticket->getId();
        $this->client->loginUser($owner, 'main');
        $this->client->jsonRequest('PATCH', $url, ['status' => 'resolved'], ['HTTP_X_CSRF_TOKEN' => $this->csrf()]);
        self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($admin, 'main');
        $this->client->jsonRequest('PATCH', $url, ['status' => 'resolved']); self::assertResponseStatusCodeSame(403);
        $csrf = $this->csrf();
        foreach ([['status' => 'bad'], ['category' => 'payments'], ['owner' => $admin->getId()], ['assignedToId' => $owner->getId()]] as $changes) {
            $this->client->jsonRequest('PATCH', $url, $changes, ['HTTP_X_CSRF_TOKEN' => $csrf]); self::assertResponseStatusCodeSame(400);
        }
        $this->client->jsonRequest('PATCH', $url, ['status' => 'resolved', 'priority' => 'high', 'assignedToId' => $admin->getId()], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful(); self::assertEmailCount(1);
        self::assertSame('resolved', $this->payload()['data']['status']); self::assertSame($admin->getId(), $this->payload()['data']['assignedToId']);
        $event = $this->payload()['statusEvent'];
        self::assertSame('resolved', $event['eventStatus']);
        self::assertSame('Status changed to: Resolved', $event['body']);
        self::assertFalse($event['internal']);
        $this->client->jsonRequest('PATCH', $url, ['status' => 'resolved'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful(); self::assertNull($this->payload()['statusEvent']); self::assertEmailCount(0);
        $this->client->loginUser($owner, 'main');
        $historyUrl = '/api/me/support-tickets/'.$ticket->getId().'/messages';
        $this->client->request('GET', $historyUrl);
        self::assertResponseIsSuccessful(); self::assertSame([$event['id']], array_column($this->payload()['data'], 'id'));
        $key = bin2hex(random_bytes(32));
        $this->post($historyUrl, ['body' => 'It is still happening.', 'submissionKey' => $key], $this->csrf());
        self::assertResponseStatusCodeSame(201); self::assertSame('open', $this->payload()['status']);
        $reply = $this->payload();
        self::assertSame('open', $reply['statusEvent']['eventStatus']);
        $this->post($historyUrl, ['body' => 'It is still happening.', 'submissionKey' => $key], $this->csrf());
        self::assertResponseIsSuccessful(); self::assertSame($reply['statusEvent']['id'], $this->payload()['statusEvent']['id']);
        $this->client->request('GET', $historyUrl);
        self::assertSame([$event['id'], $reply['data']['id'], $reply['statusEvent']['id']], array_column($this->payload()['data'], 'id'));
    }

    public function testReplyUploadsAreCompressedAndProtectedForBothAccountsAndInternalNotes(): void
    {
        $owner = $this->user(); $other = $this->user(); $admin = $this->user(true);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $ticket = new SupportTicket($this->data(), 'en', bin2hex(random_bytes(32))); $ticket->setOwner($owner); $em->persist($ticket); $em->flush();
        $this->client->loginUser($owner, 'main'); $csrf = $this->csrf();
        $path = tempnam(sys_get_temp_dir(), 'wave-reply-image-');
        $image = imagecreatetruecolor(3000, 1500); imagefill($image, 0, 0, imagecolorallocate($image, 25, 80, 60)); imagepng($image, $path, 1); unset($image);
        $original = filesize($path);
        $this->client->request('POST', '/api/me/support-tickets/'.$ticket->getId().'/messages', ['body' => 'Screenshot of the bug', 'submissionKey' => bin2hex(random_bytes(32))], ['attachments' => [new UploadedFile($path, 'proof.png', 'image/png', null, true)]], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        $messageId = $this->payload()['data']['id']; $url = $this->payload()['data']['attachments'][0]['url'];
        self::assertSame($url.'/preview', $this->payload()['data']['attachments'][0]['previewUrl']);
        $message = $em->find(SupportTicketMessage::class, $messageId); $file = $message->getAttachments()[0];
        $stored = static::getContainer()->get(TicketAttachments::class)->path($em->find(SupportTicket::class, $ticket->getId()), $file); $this->storedPaths[] = $stored;
        self::assertSame('image/webp', $file['mime']); self::assertLessThan($original, $file['size']); self::assertSame([2560, 1280], array_slice(getimagesize($stored), 0, 2));
        $this->client->request('GET', $url); self::assertResponseIsSuccessful();
        $this->client->request('GET', $url.'/preview'); self::assertResponseIsSuccessful();
        self::assertSame('image/webp', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('inline;', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $this->client->loginUser($other, 'main'); $this->client->request('GET', $url); self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $url.'/preview'); self::assertResponseStatusCodeSame(404);
        $this->client->loginUser($admin, 'main'); $this->client->request('GET', str_replace('/me/', '/admin/', $url)); self::assertResponseIsSuccessful();
        $notePath = tempnam(sys_get_temp_dir(), 'wave-reply-note-'); file_put_contents($notePath, "%PDF-1.4\nPrivate note");
        $this->client->request('POST', '/api/admin/support-tickets/'.$ticket->getId().'/messages', ['body' => 'Private staff attachment', 'internal' => '1', 'submissionKey' => bin2hex(random_bytes(32))], ['attachments' => [new UploadedFile($notePath, 'note.pdf', 'application/pdf', null, true)]], ['HTTP_X_CSRF_TOKEN' => $this->csrf()]);
        self::assertResponseStatusCodeSame(201); $noteId = $this->payload()['data']['id'];
        self::assertNull($this->payload()['data']['attachments'][0]['previewUrl']);
        $note = $em->find(SupportTicketMessage::class, $noteId); $this->storedPaths[] = static::getContainer()->get(TicketAttachments::class)->path($em->find(SupportTicket::class, $ticket->getId()), $note->getAttachments()[0]);
        $this->client->request('GET', '/api/admin/support-tickets/'.$ticket->getId().'/messages/'.$noteId.'/attachments/0/preview'); self::assertResponseStatusCodeSame(404);
        $this->client->loginUser($owner, 'main'); $this->client->request('GET', '/api/me/support-tickets/'.$ticket->getId().'/messages/'.$noteId.'/attachments/0'); self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/me/support-tickets/'.$ticket->getId().'/messages/'.$noteId.'/attachments/0/preview'); self::assertResponseStatusCodeSame(404);
    }

    public function testNewTicketAlertsHaveDeepLinksAndReplyRateLimitIsApplied(): void
    {
        $owner = $this->user(); $admin = $this->user(true);
        $this->client->loginUser($owner, 'main');
        $this->post('/api/support/tickets?locale=en', $this->data(), $this->csrf());
        self::assertResponseStatusCodeSame(201); self::assertEmailCount(2); $id = $this->payload()['data']['id'];
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $notification = $em->getRepository(Notification::class)->findOneBy(['recipient' => $admin]);
        self::assertSame('support_ticket_created', $notification->getType());
        $resource = \App\Api\NotificationResource::fromEntity($notification, 'en');
        self::assertSame($id, $resource['supportTicketId']); self::assertTrue($resource['supportAdmin']);
        $csrf = $this->csrf();
        $this->post('/api/me/support-tickets/'.$id.'/messages', ['body' => 'Hello', 'submissionKey' => bin2hex(random_bytes(32))], ''); self::assertResponseStatusCodeSame(403);
        static::getContainer()->get('limiter.wave_support_reply')->create((string) $owner->getId())->consume(30);
        $this->post('/api/me/support-tickets/'.$id.'/messages', ['body' => 'Hello', 'submissionKey' => bin2hex(random_bytes(32))], $csrf); self::assertResponseStatusCodeSame(429);
    }

    private function data(): array
    {
        return ['name' => 'Alex Creator', 'email' => 'alex@example.test', 'phone' => '', 'kind' => 'problem', 'category' => 'account', 'title' => 'Unable to log in', 'description' => 'I cannot log in after entering my email and password. Please help.', 'submissionKey' => bin2hex(random_bytes(32)), 'trap' => ''];
    }

    private function csrf(): string
    {
        $this->client->request('GET', '/api/auth/csrf');
        return $this->payload()['csrfToken'];
    }

    private function post(string $path, array $data, string $csrf): void
    {
        $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function user(bool $admin = false): User
    {
        $user = new User(bin2hex(random_bytes(6)).'@example.test', 'ROLE_COMPANY');
        $user->setAdmin($admin);
        $user->setApproved(true);
        $user->setNotificationsEnabled(false);
        $user->setPassword('unused-test-hash');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        return $user;
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
