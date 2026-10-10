<?php

namespace App\Tests\Controller;

use App\Account\AccountDeletion;
use App\Background\JobDispatcher;
use App\Background\JobExecutor;
use App\Entity\Application;
use App\Entity\Campaign;
use App\Entity\CampaignConversation;
use App\Entity\CampaignMessage;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\CreatorInquiry;
use App\Entity\CreatorPortfolioMedia;
use App\Entity\InquiryMessage;
use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\Notification;
use App\Entity\NewsletterSubscriber;
use App\Entity\UserActionToken;
use App\Entity\Offer;
use App\Entity\OAuthIdentity;
use App\Entity\SupportTicket;
use App\Entity\SupportTicketMessage;
use App\Entity\User;
use App\Service\ImageUploadProcessor;
use App\Service\MediaStorage;
use App\Service\MediaThumbnails;
use App\Service\StoredFileCleanup;
use App\Support\TicketAttachments;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountDeletionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $directory;
    private MediaStorage $storage;
    private MediaThumbnails $thumbnails;
    private StoredFileCleanup $cleanup;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $container->get('cache.rate_limiter')->clear();
        $this->em = $container->get(EntityManagerInterface::class);
        $schema = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $this->directory = sys_get_temp_dir().'/wave-delete-test-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $processor = new ImageUploadProcessor();
        $this->thumbnails = new MediaThumbnails($this->directory.'/media', $filesystem, $processor);
        $this->storage = new MediaStorage($this->directory.'/media', $filesystem, $processor, $this->thumbnails);
        $tickets = new TicketAttachments($this->directory.'/support', $processor);
        $this->cleanup = new StoredFileCleanup($this->em->getConnection(), $this->storage, $tickets, new NullLogger());
        $container->set(StoredFileCleanup::class, $this->cleanup);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public static function deletionPaths(): iterable
    {
        yield 'creator self-service' => ['creator', false];
        yield 'company self-service' => ['company', false];
        yield 'creator admin deletion' => ['creator', true];
        yield 'company admin deletion' => ['company', true];
    }

    #[DataProvider('deletionPaths')]
    public function testDeletionErasesOwnedFilesAndPersonalDataButPreservesSharedHistory(string $type, bool $adminDeletion): void
    {
        $creatorUser = $this->user('creator');
        $companyUser = $this->user('company');
        $deleted = $type === 'creator' ? $creatorUser : $companyUser;
        $other = $type === 'creator' ? $companyUser : $creatorUser;
        $creator = $creatorUser->getCreator();
        $company = $companyUser->getCompany();
        $campaign = new Campaign('historic-campaign', 'Keep this campaign', 'A shared brief', 'Campaign description', 'Travel', ['instagram'], [], 100, 500, 'Sarajevo', 2, new \DateTimeImmutable('+1 month'), new \DateTimeImmutable(), $company, true);
        $application = new Application($campaign, $creator, 'My original application');
        $application->setStatus('accepted');
        $offer = new Offer($application, 200, 'Original offer terms');
        $conversation = new CampaignConversation($campaign, $creator, $deleted);
        $message = new CampaignMessage($conversation, $deleted, 'Our agreement remains in history.');
        $inquiry = new CreatorInquiry($creator, $company, null, null, null, null, 'An earlier direct request');
        $inquiry->respond('accepted');
        $inquiryMessage = new InquiryMessage($inquiry, $deleted, 'An earlier direct message');
        foreach ([$campaign, $application, $offer, $conversation, $message, $inquiry, $inquiryMessage] as $entity) $this->em->persist($entity);
        $this->em->persist(new NewsletterSubscriber($deleted->getEmail(), 'en'));
        $this->em->persist(new UserActionToken($deleted, 'verify_email', bin2hex(random_bytes(32)), new \DateTimeImmutable('+1 day')));
        $this->em->persist(new OAuthIdentity($deleted, 'google', 'old-google-subject'));
        $this->em->persist(new Notification($deleted, 'account_approved'));
        $this->em->persist(new Notification($other, 'application_received', $deleted, $campaign));
        $this->em->flush();
        $owned = $this->image($deleted, 'profile.webp');
        $unused = $this->image($deleted, 'unused.webp');
        $otherImage = $this->image($other, 'keep.webp');
        if ($type === 'creator') {
            $creator->setAvatarMedia($owned);
            $creator->addPortfolioMedia(new CreatorPortfolioMedia($creator, $unused, 0, 'Portfolio', 'instagram'));
        } else {
            $company->setLogoMedia($owned);
            $company->setCoverMedia($unused);
            $campaign->setCoverMedia($owned);
        }
        $token = bin2hex(random_bytes(32));
        $ticket = new SupportTicket(['name' => 'Private Name', 'email' => $deleted->getEmail(), 'phone' => '', 'kind' => 'problem', 'category' => 'account', 'title' => 'Private ticket', 'description' => 'Private details here.'], 'en', $token);
        $token = $ticket->getTrackingToken();
        $ticket->setOwner($deleted);
        $guest = new SupportTicket(['name' => 'Guest report', 'email' => $deleted->getEmail(), 'phone' => '', 'kind' => 'problem', 'category' => 'account', 'title' => 'Guest ticket', 'description' => 'Private guest details.'], 'en', bin2hex(random_bytes(32)));
        $differentOwner = new SupportTicket(['name' => 'Other owner', 'email' => $deleted->getEmail(), 'phone' => '', 'kind' => 'problem', 'category' => 'account', 'title' => 'Another user’s report', 'description' => 'Keep another user’s report.'], 'en', bin2hex(random_bytes(32)));
        $differentOwner->setOwner($other);
        $this->em->persist($guest); $this->em->persist($differentOwner);
        $reply = new SupportTicketMessage($ticket, $other, 'An attachment in this private ticket', false, bin2hex(random_bytes(32)));
        $this->em->persist($ticket); $this->em->persist($reply); $this->em->flush();
        $files = new Filesystem();
        $files->dumpFile($this->directory.'/support/'.$token.'/initial.webp', 'private');
        $files->dumpFile($this->directory.'/support/'.$token.'/reply.pdf', 'private');
        $files->dumpFile($this->directory.'/support/'.$guest->getTrackingToken().'/guest.webp', 'private');
        $files->dumpFile($this->directory.'/support/'.$differentOwner->getTrackingToken().'/keep.webp', 'keep');
        $jobs = static::getContainer()->get(JobDispatcher::class);
        $jobId = $jobs->enqueue('SendEmailMessage', ['recipients' => [$deleted->getEmail()], 'raw' => 'private']);
        $otherJobId = $jobs->enqueue('SendEmailMessage', ['recipients' => [$other->getEmail()], 'raw' => 'keep']);
        $oldId = $deleted->getId();
        $oldEmail = $deleted->getEmail();
        $oldSlug = $type === 'creator' ? $creator->getSlug() : $company->getSlug();
        $conversationId = $conversation->getId(); $inquiryId = $inquiry->getId(); $ticketId = $ticket->getId(); $guestId = $guest->getId(); $otherTicketId = $differentOwner->getId(); $offerId = $offer->getId();
        $ownedPath = $owned->getStoragePath(); $unusedPath = $unused->getStoragePath(); $otherPath = $otherImage->getStoragePath();
        $this->client->loginUser($adminDeletion ? $this->user('admin') : $deleted, 'main');
        $url = $adminDeletion ? '/api/admin/'.($type === 'creator' ? 'creators' : 'companies').'/bulk-delete' : '/api/me/account';
        $this->write('DELETE', $url, $adminDeletion ? ['ids' => [$type === 'creator' ? $creator->getId() : $company->getId()]] : ['confirmed' => true]);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        $anonymous = $this->em->find(User::class, $oldId);
        self::assertTrue($anonymous->isDeleted());
        self::assertNotSame($oldEmail, $anonymous->getEmail());
        self::assertFalse($anonymous->isApproved()); self::assertFalse($anonymous->isEmailVerified());
        self::assertTrue($anonymous->isHideMyAccount()); self::assertFalse($anonymous->isNotificationsEnabled());
        self::assertNull($anonymous->getPhone()); self::assertNull($anonymous->getCity());
        self::assertSame(['ROLE_DELETED'], $anonymous->getRoles());
        self::assertSame(0, $this->em->getRepository(Media::class)->count(['owner' => $anonymous]));
        self::assertSame(0, $this->em->getRepository(OAuthIdentity::class)->count(['user' => $anonymous]));
        self::assertSame(0, $this->em->getRepository(Notification::class)->count(['recipient' => $anonymous]));
        self::assertNull($this->em->find(SupportTicket::class, $ticketId));
        self::assertNull($this->em->find(SupportTicket::class, $guestId));
        self::assertNotNull($this->em->find(SupportTicket::class, $otherTicketId));
        self::assertSame(0, $this->em->getRepository(NewsletterSubscriber::class)->count(['email' => $oldEmail]));
        self::assertSame(0, $this->em->getRepository(UserActionToken::class)->count(['user' => $anonymous]));
        foreach ([$ownedPath, $unusedPath] as $path) {
            self::assertFileDoesNotExist($this->storage->absolutePath($path));
            self::assertSame([], glob($this->directory.'/media/thumbnails/*/'.hash('sha256', $path)));
        }
        self::assertDirectoryDoesNotExist($this->directory.'/support/'.$token);
        self::assertDirectoryDoesNotExist($this->directory.'/support/'.$guest->getTrackingToken());
        self::assertFileExists($this->directory.'/support/'.$differentOwner->getTrackingToken().'/keep.webp');
        self::assertFileExists($this->storage->absolutePath($otherPath));
        self::assertSame(1, $this->em->getRepository(Application::class)->count([]));
        self::assertSame(1, $this->em->getRepository(CampaignMessage::class)->count([]));
        self::assertSame(1, $this->em->getRepository(InquiryMessage::class)->count([]));
        $db = $this->em->getConnection();
        self::assertSame('', $db->fetchOne('SELECT payload FROM background_job WHERE id = ?', [$jobId]));
        self::assertNotSame('', $db->fetchOne('SELECT payload FROM background_job WHERE id = ?', [$otherJobId]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM stored_file_deletion'));
        if ($type === 'company') self::assertSame('closed', $this->em->getRepository(Campaign::class)->findOneBy(['slug' => 'historic-campaign'])->getStatus());
        else self::assertSame('open', $this->em->getRepository(Campaign::class)->findOneBy(['slug' => 'historic-campaign'])->getStatus());
        self::assertSame('pending', $this->em->find(Offer::class, $offerId)->getStatus());
        // A reminder queued before deletion must be skipped even for the surviving recipient.
        static::getContainer()->get(JobExecutor::class)->execute('SendEmailMessage', [
            'reminder' => ['conversationId' => $conversationId, 'recipientId' => $other->getId(), 'firstId' => $message->getId()],
            'raw' => 'invalid base64 that must never reach the mail transport',
        ], 'test-reminder');
        // The remaining participant retains both kinds of thread, but cannot send.
        $other = $this->em->find(User::class, $other->getId());
        $this->client->loginUser($other, 'main');
        $this->client->request('GET', '/api/me/inbox?locale=en');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->payload()['data']);
        foreach ($this->payload()['data'] as $thread) {
            self::assertTrue($thread['readOnly']);
            self::assertSame('Deleted account', $type === 'creator' ? $thread['creator']['displayName'] : $thread['company']['name']);
        }
        foreach (['conversations' => $conversationId, 'inquiries' => $inquiryId] as $kind => $id) {
            $this->client->request('GET', '/api/me/'.$kind.'/'.$id.'/messages?locale=en');
            self::assertResponseIsSuccessful(); self::assertNotEmpty($this->payload()['data']);
            $this->write('POST', '/api/me/'.$kind.'/'.$id.'/messages', ['body' => 'Must be rejected']);
            self::assertResponseStatusCodeSame(409);
        }
        if ($type === 'company') {
            $this->write('POST', '/api/me/offers/'.$offerId.'/respond', ['decision' => 'accept']);
            self::assertResponseStatusCodeSame(409);
        } else {
            $this->write('POST', '/api/company/applications/'.$application->getId().'/reject', []);
            self::assertResponseStatusCodeSame(409);
        }
        $this->client->request('GET', '/api/'.($type === 'creator' ? 'creators' : 'companies').'/'.$oldSlug.'?locale=en');
        self::assertResponseStatusCodeSame(404);
        if ($type === 'company') {
            $this->client->request('GET', '/api/campaigns/historic-campaign?locale=en');
            self::assertResponseIsSuccessful();
            self::assertTrue($this->payload()['data']['readOnly']);
            self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
            $this->client->restart();
            $this->client->request('GET', '/api/campaigns/historic-campaign?locale=en');
            self::assertResponseStatusCodeSame(404);
        }
        $this->client->restart();
        $this->write('POST', '/api/auth/login', ['email' => $oldEmail, 'password' => 'old-password-12345']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRequiresExplicitConfirmationAndCsrfAndAllowsPendingAccounts(): void
    {
        $user = $this->user('creator'); $user->setApproved(false); $user->setEmailVerified(false); $this->em->flush();
        $this->client->loginUser($user, 'main');
        $this->client->request('DELETE', '/api/me/account?locale=en', server: ['CONTENT_TYPE' => 'application/json'], content: '{"confirmed":true}');
        self::assertResponseStatusCodeSame(403);
        $this->write('DELETE', '/api/me/account', ['confirmed' => 'true']); self::assertResponseStatusCodeSame(400);
        $this->write('DELETE', '/api/me/account', ['confirmed' => true]); self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/auth/me?locale=en');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/auth/session?locale=en');
        self::assertResponseIsSuccessful();
        self::assertNull($this->payload()['data']);
    }

    public function testCannotDeleteOperatorAccountOrUseAnotherUsersId(): void
    {
        $admin = $this->user('admin'); $target = $this->user('creator');
        $this->client->loginUser($admin, 'main');
        $this->write('DELETE', '/api/me/account', ['confirmed' => true]); self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($target, 'main');
        $this->write('DELETE', '/api/me/account', ['confirmed' => true, 'id' => $admin->getId()]); self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertFalse($this->em->find(User::class, $admin->getId())->isDeleted());
        self::assertTrue($this->em->find(User::class, $target->getId())->isDeleted());
    }

    public function testAdminDeletionRevokesAnExistingSession(): void
    {
        $user = $this->user('creator');
        $this->client->loginUser($user, 'main');
        $cookies = clone $this->client->getCookieJar();
        $this->client->request('GET', '/api/auth/me?locale=en');
        self::assertSame($user->getId(), $this->payload()['data']['id']);
        $this->client->restart();
        $this->client->loginUser($this->user('admin'), 'main');
        $this->write('DELETE', '/api/admin/registrations/bulk-delete', ['ids' => [$user->getId()]]);
        self::assertResponseIsSuccessful();
        $this->client->restart();
        foreach ($cookies->all() as $cookie) $this->client->getCookieJar()->set($cookie);
        $this->client->request('GET', '/api/auth/me?locale=en');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/auth/session?locale=en');
        self::assertResponseIsSuccessful();
        self::assertNull($this->payload()['data']);
    }

    public function testRollbackDoesNotDeleteFilesAndCleanupIsRetryable(): void
    {
        $user = $this->user('creator'); $media = $this->image($user, 'rollback.webp');
        $db = $this->em->getConnection();
        $db->beginTransaction();
        static::getContainer()->get(AccountDeletion::class)->delete([$user]);
        self::assertSame(0, $this->cleanup->run());
        self::assertFileExists($this->storage->absolutePath($media->getStoragePath()));
        $db->rollBack(); $this->em->clear();
        self::assertFalse($this->em->find(User::class, $user->getId())->isDeleted());
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM stored_file_deletion'));
        // Refuse an invalid path, preserve the instruction, then retry an already-missing file.
        $this->cleanup->schedule('media', '../unsafe');
        self::assertSame(0, $this->cleanup->run());
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM stored_file_deletion'));
        $db->executeStatement('DELETE FROM stored_file_deletion');
        $this->cleanup->schedule('media', 'uploads/already-missing.webp');
        self::assertSame(1, $this->cleanup->run());
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM stored_file_deletion'));
    }

    private function user(string $type): User
    {
        $user = new User($type.'-'.bin2hex(random_bytes(5)).'@example.test', $type === 'creator' ? 'ROLE_CREATOR' : 'ROLE_COMPANY');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'old-password-12345'));
        $user->setApproved(true); $user->setEmailVerified(true); $user->setHideMyAccount(false);
        $user->setPhone('+38761123456'); $user->setCity('Sarajevo'); $user->setCountryCode('BA');
        if ($type === 'creator') $user->setCreator(new Creator('private-'.bin2hex(random_bytes(5)), 'Private Creator', 'Travel', 'Sarajevo', 'My private biography', [], []));
        else $user->setCompany(new Company('private-'.bin2hex(random_bytes(5)), 'Private Company', 'Travel', about: 'My private company description'));
        if ($type === 'admin') $user->setAdmin(true);
        $this->em->persist($user); $this->em->flush();

        return $user;
    }

    private function image(User $owner, string $name): Media
    {
        $folder = $this->em->getRepository(MediaFolder::class)->findOneBy(['slug' => 'profile']);
        if ($folder === null) { $folder = new MediaFolder('profile', 'Profile'); $this->em->persist($folder); $this->em->flush(); }
        $source = $this->directory.'/source.png';
        (new Filesystem())->mkdir($this->directory);
        $image = imagecreatetruecolor(64, 64); imagepng($image, $source); imagedestroy($image);
        $stored = $this->storage->store(new UploadedFile($source, 'source.png', null, null, true), $folder, $owner);
        $media = new Media($folder, $owner, $name, $stored['path'], $stored['mimeType'], $stored['fileSize']);
        $this->em->persist($media); $this->em->flush();
        // Include a derivative from an older version in cleanup coverage.
        (new Filesystem())->dumpFile($this->directory.'/media/thumbnails/legacy/'.hash('sha256', $stored['path']).'/96.webp', 'old');

        return $media;
    }

    private function write(string $method, string $url, array $body): void
    {
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $csrf = $this->payload()['csrfToken'];
        $this->client->request($method, $url.'?locale=en', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
