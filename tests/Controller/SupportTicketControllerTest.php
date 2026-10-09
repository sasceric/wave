<?php

namespace App\Tests\Controller;

use App\Entity\SupportTicket;
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
            if (is_dir(dirname($path))) rmdir(dirname($path));
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
        self::assertSame('received', $this->payload()['data']['status']);
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
        $this->client->request('GET', '/api/admin/support-tickets?page=1&pageSize=25');
        self::assertResponseIsSuccessful();
        $payload = $this->payload();
        self::assertSame(28, $payload['meta']['total']);
        self::assertCount(25, $payload['data']);
        self::assertGreaterThan($payload['data'][24]['id'], $payload['data'][0]['id']);
        self::assertArrayNotHasKey('trackingToken', $payload['data'][0]);
        $this->client->request('GET', '/api/admin/support-tickets?page=99&pageSize=25');
        self::assertSame(2, $this->payload()['meta']['page']);
        self::assertCount(3, $this->payload()['data']);
        foreach (['page=0', 'pageSize=5', 'locale=xx'] as $query) {
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
