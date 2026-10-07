<?php

namespace App\Tests\Controller;

use App\Entity\Campaign;
use App\Entity\Application as CampaignApplication;
use App\Entity\CampaignConversation;
use App\Entity\CampaignMessage;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\CreatorInquiry;
use App\Entity\InquiryMessage;
use App\Entity\Media;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserPushSubscription;
use App\Service\UnreadInboxCounter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MarketplaceWorkflowTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;
    private array $uploadedMediaIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get('cache.rate_limiter')->clear();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $entityManager = static::getContainer()->get(EntityManagerInterface::class);
            $storage = static::getContainer()->get(\App\Service\MediaStorage::class);
            foreach ($this->uploadedMediaIds as $id) {
                $media = $entityManager->getRepository(Media::class)->find($id);
                if ($media instanceof Media) {
                    $storage->remove($media);
                }
            }
        }

        parent::tearDown();
    }

    public function testAuthenticationSessionCookiePersistsForNinetyDays(): void
    {
        $sessionOptions = static::getContainer()->getParameter('session.storage.options');
        self::assertSame(7776000, $sessionOptions['cookie_lifetime']);
        self::assertSame(7776000, $sessionOptions['gc_maxlifetime']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User('session-persistence@example.test', 'ROLE_CREATOR');
        $user->setPassword($passwordHasher->hashPassword($user, 'a-long-passphrase-for-wave'));
        $entityManager->persist($user);
        $entityManager->flush();

        $csrf = $this->csrfToken();
        $sessionCookies = array_values(array_filter(
            $this->client->getResponse()->headers->getCookies(),
            static fn (Cookie $cookie): bool => $cookie->getName() === 'MOCKSESSID',
        ));
        self::assertCount(1, $sessionCookies);
        self::assertGreaterThanOrEqual(time() + (89 * 24 * 60 * 60), $sessionCookies[0]->getExpiresTime());
        self::assertTrue($sessionCookies[0]->isHttpOnly());

        $this->jsonRequest('POST', '/api/auth/login', [
            'email' => 'session-persistence@example.test',
            'password' => 'a-long-passphrase-for-wave',
        ], $csrf);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/auth/me?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame('session-persistence@example.test', $this->payload()['data']['email']);
    }

    public function testRegistrationCreatesSessionAndProfileCanBeUpdated(): void
    {
        $csrf = $this->csrfToken();
        $this->jsonRequest('POST', '/api/auth/register', [
            'accountType' => 'creator',
            'email' => 'creator@example.test',
            'password' => 'a-long-passphrase-for-wave',
            'firstName' => 'Avery',
            'lastName' => 'Creator',
            'birthday' => '2000-02-29',
            'phone' => '+387 61 123 456',
            'category' => 'Travel',
            'categories' => ['Travel', 'Lifestyle'],
            'city' => 'Sarajevo',
            'country' => 'BA',
        ], $csrf);

        self::assertResponseStatusCodeSame(201);
        $registrationResponse = $this->payload();
        $csrf = $registrationResponse['csrfToken'];
        $registered = $registrationResponse['data'];
        self::assertSame('creator', $registered['accountType']);
        self::assertSame('Avery Creator', $registered['profile']['displayName']);
        self::assertArrayNotHasKey('password', $registered);
        self::assertSame('', $registered['profile']['bio']);
        self::assertSame(['Travel', 'Lifestyle'], $registered['profile']['categories']);
        self::assertSame('2000-02-29', $registered['profile']['birthday']);
        $registeredUser = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)->findOneBy(['email' => 'creator@example.test']);
        self::assertInstanceOf(User::class, $registeredUser);
        self::assertSame('+387 61 123 456', $registeredUser->getPhone());
        self::assertSame('Sarajevo', $registeredUser->getCity());
        self::assertSame('BA', $registeredUser->getCountryCode());

        $this->client->request('GET', '/api/auth/me?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame('creator@example.test', $this->payload()['data']['email']);
        self::assertFalse($registered['approved']);
        $this->client->request('GET', '/api/creators/'.$registered['profile']['slug'].'?locale=en');
        self::assertResponseStatusCodeSame(404);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused-test-hash');
        $admin->setAdmin(true);
        $entityManager->persist($admin);
        $entityManager->flush();
        $this->client->loginUser($admin, 'main');
        $this->jsonRequest('POST', '/api/admin/registrations/'.$registered['id'].'/approve', [], $this->csrfToken());
        self::assertResponseStatusCodeSame(400);
        $registeredUser = $entityManager->getRepository(User::class)->findOneBy(['email' => 'creator@example.test']);
        $registeredUser->setEmailVerified(true);
        $entityManager->flush();
        $this->jsonRequest('POST', '/api/admin/registrations/'.$registered['id'].'/approve', [], $this->csrfToken());
        self::assertResponseIsSuccessful();
        $this->client->loginUser($registeredUser, 'main');
        $csrf = $this->csrfToken();

        $this->jsonRequest('PUT', '/api/me/profile', [
            'displayName' => 'Avery Creator',
            'birthday' => '1999-05-08',
            'category' => 'Food',
            'categories' => ['Food', 'Lifestyle'],
            'bio' => 'I make thoughtful guides for slower, more curious travel.',
            'avatarUrl' => null,
            'tags' => ['Slow travel', 'Photography'],
            'socialProfiles' => [['platform' => 'Instagram', 'handle' => '@avery', 'followers' => 12500]],
            'faqs' => [[
                'question' => 'Can you create a custom travel itinerary?',
                'answer' => 'Yes, I can plan a custom itinerary based on your campaign and destination.',
            ]],
        ], $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame('Prijavio/la kreator/ica', $this->payload()['data']['socialProfiles'][0]['source']);
        self::assertSame((new DateTimeImmutable('today'))->format('Y-m-d'), $this->payload()['data']['socialProfiles'][0]['lastUpdated']);
        self::assertSame(['Food', 'Lifestyle'], $this->payload()['data']['categories']);
        self::assertSame('1999-05-08', $this->payload()['data']['birthday']);
        self::assertSame('Can you create a custom travel itinerary?', $this->payload()['data']['faqs'][0]['question']);

        $this->client->request('GET', '/api/creators/'.$registered['profile']['slug'].'?locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame('Avery Creator', $this->payload()['data']['displayName']);
        self::assertArrayNotHasKey('birthday', $this->payload()['data']);
        self::assertSame(['Food', 'Lifestyle'], $this->payload()['data']['categories']);
        self::assertSame('Can you create a custom travel itinerary?', $this->payload()['data']['faqs'][0]['question']);

        $this->jsonRequest('POST', '/api/auth/logout', [], $csrf);
        self::assertResponseIsSuccessful();
        $csrf = $this->csrfToken();
        $this->jsonRequest('POST', '/api/auth/login', [
            'email' => 'creator@example.test',
            'password' => 'a-long-passphrase-for-wave',
        ], $csrf);
        self::assertResponseIsSuccessful();
        $csrf = $this->payload()['csrfToken'];
        $this->client->request('GET', '/api/auth/me?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame('creator@example.test', $this->payload()['data']['email']);
        $this->jsonRequest('POST', '/api/auth/logout', [], $csrf);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('POST', '/api/auth/login', [
            'email' => 'creator@example.test',
            'password' => 'wrong-password',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(401);
    }

    public function testThumbnailsEnforceVisibilityBeforeServingCachedOrConditionalResponses(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('thumbnail-owner@example.test', 'ROLE_CREATOR');
        $owner->setPassword('unused-test-hash');
        $owner->setApproved(true);
        $owner->setCreator(new Creator('thumbnail-owner', 'Thumbnail Owner', 'Lifestyle', 'Sarajevo', '', []));
        $entityManager->persist($owner);
        $entityManager->flush();
        $ownerId = $owner->getId();
        $this->client->loginUser($owner, 'main');
        $uploaded = $this->uploadImage('creator-avatar', $this->csrfToken(), 'thumbnail.png');
        self::assertResponseStatusCodeSame(201);
        $mediaId = $uploaded['data']['id'];
        $this->uploadedMediaIds[] = $mediaId;
        self::assertSame(1, $uploaded['data']['width']);
        $url = $uploaded['data']['image']['src'];
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame('image/webp', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $privateEtag = $this->client->getResponse()->getEtag();
        $this->client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $privateEtag]);
        self::assertResponseStatusCodeSame(200);
        $this->client->restart();
        $this->client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $privateEtag]);
        self::assertResponseStatusCodeSame(404);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $entityManager->find(User::class, $ownerId);
        $media = $entityManager->find(Media::class, $mediaId);
        $owner->getCreator()->setAvatarMedia($media);
        $entityManager->flush();
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        $response = $this->client->getResponse();
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame(300, $response->getMaxAge());
        self::assertContains('Cookie', $response->getVary());
        $etag = $response->getEtag();
        self::assertNotNull($etag);
        self::assertNotNull($response->getLastModified());
        $this->client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
        $this->client->request('GET', '/api/media/'.$mediaId.'/thumbnail/v1/10000');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/media/'.$mediaId.'/thumbnail/v0/320');
        self::assertResponseStatusCodeSame(404);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $entityManager->find(User::class, $ownerId);
        $this->client->loginUser($owner, 'main');
        $this->client->request('GET', $url);
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertSame(300, $this->client->getResponse()->getMaxAge());
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $entityManager->find(User::class, $ownerId);
        $owner->setHideMyAccount(true);
        $entityManager->flush();
        $this->client->restart();
        $this->client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(404);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $entityManager->find(User::class, $ownerId);
        $this->client->loginUser($owner, 'main');
        $this->jsonRequest('PUT', '/api/me/profile', [
            'displayName' => 'Thumbnail Owner', 'category' => 'Lifestyle', 'categories' => ['Lifestyle'],
            'location' => 'Sarajevo', 'bio' => '', 'tagline' => '', 'avatarMediaId' => null,
            'avatarUrl' => null, 'tags' => [], 'socialProfiles' => [], 'portfolio' => [], 'packages' => [], 'faqs' => [],
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();
        $storage = static::getContainer()->get(\App\Service\MediaStorage::class);
        $media = static::getContainer()->get(EntityManagerInterface::class)->find(Media::class, $mediaId);
        $thumbnailPath = static::getContainer()->get(\App\Service\MediaThumbnails::class)->path($media, 320);
        $sourcePath = $storage->absolutePath($media->getStoragePath());
        $this->jsonRequest('DELETE', '/api/media/'.$mediaId, [], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertFileDoesNotExist($thumbnailPath);
        self::assertFileDoesNotExist($sourcePath);
    }

    public function testEveryUploadFolderStoresResizedWebpAndRejectsBrokenImages(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $creator = new User('webp-creator@example.test', 'ROLE_CREATOR');
        $company = new User('webp-company@example.test', 'ROLE_COMPANY');
        foreach ([$creator, $company] as $user) {
            $user->setPassword('unused-test-hash');
            $entityManager->persist($user);
        }
        $entityManager->flush();

        foreach ([[$creator, 'creator-avatar'], [$creator, 'creator-portfolio'], [$company, 'company-logo'], [$company, 'company-cover'], [$company, 'campaign-cover']] as [$owner, $folder]) {
            $this->client->loginUser($owner, 'main');
            $csrf = $this->csrfToken();
            $path = tempnam(sys_get_temp_dir(), 'wave-upload-');
            self::assertNotFalse($path);
            imagepng(imagecreatetruecolor(1200, 800), $path);
            try {
                $this->client->request('POST', '/api/media?folder='.$folder.'&locale=en', [], [
                    'file' => new UploadedFile($path, 'large.png', 'image/png', null, true),
                ], ['HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_ACCEPT' => 'application/json']);
                self::assertResponseStatusCodeSame(201);
                $uploaded = $this->payload()['data'];
                $this->uploadedMediaIds[] = $uploaded['id'];
                self::assertSame('image/webp', $uploaded['mimeType']);
                $entityManager = static::getContainer()->get(EntityManagerInterface::class);
                $media = $entityManager->find(Media::class, $uploaded['id']);
                $storage = static::getContainer()->get(\App\Service\MediaStorage::class);
                $storedPath = $storage->absolutePath($media->getStoragePath());
                self::assertSame([600, 400, IMAGETYPE_WEBP], array_slice(getimagesize($storedPath), 0, 3));
                self::assertSame(filesize($storedPath), $uploaded['fileSize']);
                $this->client->request('GET', $uploaded['url']);
                self::assertResponseIsSuccessful();
                self::assertSame('image/webp', $this->client->getResponse()->headers->get('Content-Type'));

                file_put_contents($path, substr(file_get_contents($path), 0, 33));
                $this->client->request('POST', '/api/media?folder='.$folder.'&locale=en', [], [
                    'file' => new UploadedFile($path, 'broken.png', 'image/png', null, true),
                ], ['HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_ACCEPT' => 'application/json']);
                self::assertResponseStatusCodeSame(400);
                self::assertArrayHasKey('error', $this->payload());
            } finally {
                unlink($path);
            }
        }
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(5, $entityManager->getRepository(Media::class)->count([]));
    }

    public function testUploadedMediaIsOwnedAndCreatorProfileStoresMediaReferences(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $creatorUser = new User('media-creator@example.test', 'ROLE_CREATOR');
        $creatorUser->setPassword('unused-test-hash');
        $creatorUser->setCreator(new Creator(
            'media-creator',
            'Media Creator',
            'Lifestyle',
            'Sarajevo',
            'A creator who shares thoughtful everyday stories.',
            [],
        ));
        $otherUser = new User('other-media-creator@example.test', 'ROLE_CREATOR');
        $otherUser->setPassword('unused-test-hash');
        $otherUser->setCreator(new Creator(
            'other-media-creator',
            'Other Media Creator',
            'Travel',
            'Zagreb',
            'A creator who shares thoughtful travel stories.',
            [],
        ));
        $entityManager->persist($creatorUser);
        $entityManager->persist($otherUser);
        $entityManager->flush();

        $this->client->loginUser($creatorUser, 'main');
        $csrf = $this->csrfToken();
        $uploaded = $this->uploadImage('creator-portfolio', $csrf, 'portfolio.png');
        self::assertResponseStatusCodeSame(201);
        $mediaId = $uploaded['data']['id'];
        $this->uploadedMediaIds[] = $mediaId;
        self::assertSame('creator-portfolio', $uploaded['data']['folder']['slug']);
        self::assertSame('/api/media/'.$mediaId.'/file', $uploaded['data']['url']);
        $this->client->request('GET', '/api/media?folder=creator-portfolio&locale=bs');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload()['data']);

        $this->jsonRequest('PUT', '/api/me/profile', [
            'displayName' => 'Media Creator',
            'category' => 'Lifestyle',
            'categories' => ['Lifestyle'],
            'location' => 'Sarajevo',
            'bio' => 'A creator who shares thoughtful everyday stories.',
            'tagline' => 'Small stories, made with care.',
            'avatarMediaId' => null,
            'avatarUrl' => null,
            'tags' => [],
            'socialProfiles' => [],
            'portfolio' => [[
                'id' => 'uploaded-portfolio',
                'type' => 'image',
                'mediaId' => $mediaId,
                'title' => 'A local portfolio image',
                'platform' => 'Instagram',
            ]],
            'packages' => [],
            'faqs' => [],
        ], $csrf);
        self::assertResponseIsSuccessful();
        $portfolioItem = $this->payload()['data']['portfolio'][0];
        self::assertSame($mediaId, $portfolioItem['mediaId']);
        self::assertSame('/api/media/'.$mediaId.'/file', $portfolioItem['url']);
        self::assertSame('A local portfolio image', $portfolioItem['title']);

        $this->client->request('GET', $portfolioItem['url']);
        self::assertResponseIsSuccessful();
        self::assertSame('image/webp', $this->client->getResponse()->headers->get('Content-Type'));

        $privateUpload = $this->uploadImage('creator-portfolio', $csrf, 'private.png');
        self::assertResponseStatusCodeSame(201);
        $privateMediaId = $privateUpload['data']['id'];
        $this->uploadedMediaIds[] = $privateMediaId;

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $creatorUser = $entityManager->getRepository(User::class)->find($creatorUser->getId());
        self::assertInstanceOf(User::class, $creatorUser);
        $creatorUser->setApproved(false);
        $entityManager->flush();
        self::assertFalse($creatorUser->isApproved());
        $this->client->loginUser($otherUser, 'main');
        $otherCsrf = $this->csrfToken();
        $this->client->request('GET', '/api/media?folder=creator-portfolio&locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']);
        $this->client->request('GET', '/api/media/'.$privateMediaId.'/file');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/media/'.$mediaId.'/file');
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('PUT', '/api/me/profile', [
            'displayName' => 'Other Media Creator',
            'category' => 'Travel',
            'categories' => ['Travel'],
            'location' => 'Zagreb',
            'bio' => 'A creator who shares thoughtful travel stories.',
            'tagline' => 'Stories from the road.',
            'avatarMediaId' => null,
            'avatarUrl' => null,
            'tags' => [],
            'socialProfiles' => [],
            'portfolio' => [[
                'id' => 'stolen-portfolio',
                'type' => 'image',
                'mediaId' => $mediaId,
                'title' => 'Someone else’s image',
                'platform' => 'All',
            ]],
            'packages' => [],
            'faqs' => [],
        ], $otherCsrf);
        self::assertResponseStatusCodeSame(400);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $creatorUser = $entityManager->getRepository(User::class)->find($creatorUser->getId());
        self::assertInstanceOf(User::class, $creatorUser);
        $creatorUser->setApproved(true);
        $entityManager->flush();
        $this->client->loginUser($creatorUser, 'main');
        $this->jsonRequest('DELETE', '/api/media/'.$mediaId, [], $this->csrfToken());
        self::assertResponseStatusCodeSame(409);

        $companyUser = new User('media-company@example.test', 'ROLE_COMPANY');
        $companyUser->setPassword('unused-test-hash');
        $companyUser->setCompany(new Company('media-company', 'Media Company', 'Food'));
        $entityManager->persist($companyUser);
        $entityManager->flush();
        $this->client->loginUser($companyUser, 'main');
        $companyCsrf = $this->csrfToken();
        $logo = $this->uploadImage('company-logo', $companyCsrf, 'logo.png');
        self::assertResponseStatusCodeSame(201);
        $this->uploadedMediaIds[] = $logo['data']['id'];
        $this->jsonRequest('PUT', '/api/me/profile', [
            'name' => 'Media Company',
            'industry' => 'Food',
            'about' => '<p>We make <strong>thoughtful</strong> food products.</p>',
            'phone' => '+38761123456',
            'city' => 'Sarajevo',
            'countryCode' => 'ba',
            'logoMediaId' => $logo['data']['id'],
            'logoUrl' => null,
        ], $companyCsrf);
        self::assertResponseIsSuccessful();
        self::assertSame($logo['data']['id'], $this->payload()['data']['profile']['logoMediaId']);
        self::assertSame($logo['data']['url'], $this->payload()['data']['profile']['logoUrl']);
        self::assertSame('<p>We make <strong>thoughtful</strong> food products.</p>', $this->payload()['data']['profile']['about']);
        self::assertSame('+38761123456', $this->payload()['data']['phone']);
        self::assertSame('Sarajevo', $this->payload()['data']['city']);
        self::assertSame('BA', $this->payload()['data']['countryCode']);

        $this->client->request('GET', '/api/companies/media-company?locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame('Sarajevo', $this->payload()['data']['city']);
        self::assertSame('BA', $this->payload()['data']['countryCode']);
        self::assertSame('<p>We make <strong>thoughtful</strong> food products.</p>', $this->payload()['data']['about']);
        self::assertArrayNotHasKey('phone', $this->payload()['data']);

        $this->jsonRequest('PUT', '/api/me/profile', [
            'name' => 'Media Company',
            'industry' => 'Food',
            'about' => '',
            'logoMediaId' => null,
            'logoUrl' => null,
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();
        $this->jsonRequest('DELETE', '/api/media/'.$logo['data']['id'], [], $this->csrfToken());
        self::assertResponseIsSuccessful();
    }

    public function testCompanyCoverIsOwnedPublicOnlyWhenVisibleAndCannotBeDeletedWhileUsed(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('cover-owner@example.test', 'ROLE_COMPANY');
        $other = new User('cover-other@example.test', 'ROLE_COMPANY');
        foreach ([$owner, $other] as $user) {
            $user->setPassword('unused');
            $user->setCompany(new Company(str_replace('@example.test', '', $user->getEmail()), 'Cover company', 'Food'));
            $em->persist($user);
        }
        $em->flush();
        $this->client->loginUser($owner, 'main');
        $csrf = $this->csrfToken();
        $cover = $this->uploadImage('company-cover', $csrf, 'cover.png');
        self::assertResponseStatusCodeSame(201);
        $id = $cover['data']['id'];
        $this->uploadedMediaIds[] = $id;
        $body = ['name' => 'Cover company', 'industries' => ['Food', 'Technology'], 'coverMediaId' => $id];
        $this->jsonRequest('PUT', '/api/me/profile', $body, $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame(['Food', 'Technology'], $this->payload()['data']['profile']['industries']);
        self::assertSame($id, $this->payload()['data']['profile']['coverMediaId']);
        $this->jsonRequest('DELETE', '/api/media/'.$id, [], $csrf);
        self::assertResponseStatusCodeSame(409);
        $this->client->loginUser($other, 'main');
        $this->client->request('GET', $cover['data']['url']);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('PUT', '/api/me/profile', $body, $this->csrfToken());
        self::assertResponseStatusCodeSame(400);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(User::class)->find($owner->getId())->setHideMyAccount(true);
        $em->flush();
        $this->client->request('GET', $cover['data']['url']);
        self::assertResponseStatusCodeSame(404);
        $this->client->loginUser($owner, 'main');
        $this->jsonRequest('PUT', '/api/me/profile', ['name' => 'Cover company', 'industries' => ['Technology', 'Travel'], 'coverMediaId' => null], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertNull($this->payload()['data']['profile']['coverMediaId']);
        self::assertSame(['Technology', 'Travel'], $this->payload()['data']['profile']['industries']);
        $this->jsonRequest('DELETE', '/api/media/'.$id, [], $this->csrfToken());
        self::assertResponseIsSuccessful();
        $this->jsonRequest('PUT', '/api/me/profile', ['name' => 'Cover company', 'industries' => []], $this->csrfToken());
        self::assertResponseStatusCodeSame(400);
    }

    public function testAccountsCanHideTheirPublicProfilesAndCompanyCampaigns(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $creatorUser = new User('hidden-creator@example.test', 'ROLE_CREATOR');
        $creatorUser->setPassword('unused-test-hash');
        $creator = new Creator('hidden-creator', 'Hidden Creator', 'Travel', 'Sarajevo', 'A creator profile.', [], []);
        $creatorUser->setCreator($creator);

        $companyUser = new User('hidden-company@example.test', 'ROLE_COMPANY');
        $companyUser->setPassword('unused-test-hash');
        $company = new Company('hidden-company', 'Hidden Company', 'Travel');
        $companyUser->setCompany($company);
        $campaign = new Campaign(
            'hidden-company-campaign',
            'A hidden company campaign',
            'A campaign by a hidden company.',
            'A full campaign brief from a company that opted out of public discovery.',
            'Travel',
            ['Instagram'],
            ['1 post'],
            400,
            800,
            'Sarajevo',
            2,
            new DateTimeImmutable('+20 days'),
            new DateTimeImmutable('today'),
            $company,
        );

        $entityManager->persist($creatorUser);
        $entityManager->persist($companyUser);
        $entityManager->persist($campaign);
        $entityManager->flush();

        $this->client->request('GET', '/api/creators?locale=bs');
        self::assertSame(
            ['hidden-creator'],
            array_column($this->payload()['data'], 'slug'),
        );

        $this->client->loginUser($creatorUser, 'main');
        $this->jsonRequest('PUT', '/api/me/account-visibility', ['hide_my_account' => 1], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['data']['hide_my_account']);

        $this->client->request('GET', '/api/creators?locale=bs');
        self::assertSame([], array_column($this->payload()['data'], 'slug'));
        $this->client->request('GET', '/api/creators/hidden-creator?locale=bs');
        self::assertResponseIsSuccessful();

        $this->client->loginUser($companyUser, 'main');
        $this->client->request('GET', '/api/creators/hidden-creator?locale=bs');
        self::assertResponseStatusCodeSame(404);

        $this->jsonRequest('PUT', '/api/me/account-visibility', ['hide_my_account' => 1], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['data']['hide_my_account']);

        $this->client->request('GET', '/api/companies?locale=bs');
        self::assertSame([], array_column($this->payload()['data'], 'slug'));
        $this->client->loginUser($creatorUser, 'main');
        $this->client->request('GET', '/api/companies/hidden-company?locale=bs');
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($companyUser, 'main');
        $this->client->request('GET', '/api/campaigns?locale=bs');
        self::assertSame([], array_column($this->payload()['data'], 'slug'));
        $this->client->request('GET', '/api/campaigns/hidden-company-campaign?locale=bs');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/homepage?locale=bs');
        self::assertSame([], $this->payload()['data']['creators']);
        self::assertSame([], $this->payload()['data']['campaigns']);
        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('hidden-creator', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('hidden-company', $this->client->getResponse()->getContent());

        $this->client->loginUser($creatorUser, 'main');
        $this->jsonRequest('PUT', '/api/me/account-visibility', ['hide_my_account' => 0], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->payload()['data']['hide_my_account']);
        $this->client->request('GET', '/api/creators?locale=bs');
        self::assertSame(
            ['hidden-creator'],
            array_column($this->payload()['data'], 'slug'),
        );

        $this->client->loginUser($companyUser, 'main');
        $this->jsonRequest('PUT', '/api/me/account-visibility', ['hide_my_account' => 0], $this->csrfToken());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/campaigns?locale=bs');
        self::assertSame(
            ['hidden-company-campaign'],
            array_column($this->payload()['data'], 'slug'),
        );
    }

    public function testCreatorDirectoryMatchesSecondaryCategoriesWithoutTreatingWildcardsAsPatterns(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $creator = new Creator(
            'category-search-creator',
            'Category Search Creator',
            'Travel',
            'Sarajevo',
            'A creator profile for category search.',
            [],
            categories: ['Travel', 'Food'],
        );
        $otherCreator = new Creator(
            'category-search-other',
            'Another Category Creator',
            'Fashion',
            'Zagreb',
            'A profile in another category.',
            [],
            categories: ['Fashion'],
        );
        $entityManager->persist($creator);
        $entityManager->persist($otherCreator);
        $entityManager->flush();

        $this->client->request('GET', '/api/creators?category=Food&locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame(['category-search-creator'], array_column($this->payload()['data'], 'slug'));

        $this->client->request('GET', '/api/creators?category=Food%25&locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']);

        $this->client->request('GET', '/api/creators?category=Food_&locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']);
    }

    public function testApplicationOfferAndAcceptanceRespectOwnershipAndCampaignSpots(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $companyUser = new User('brand@example.test', 'ROLE_COMPANY');
        $companyUser->setPassword('unused-test-hash');
        $company = new Company('maker-brand', 'Maker Brand', 'Food');
        $companyUser->setCompany($company);
        $creatorUser = new User('artist@example.test', 'ROLE_CREATOR');
        $creatorUser->setPassword('unused-test-hash');
        $creator = new Creator('creator-avery', 'Avery Creator', 'Food', 'Zagreb, Croatia', 'Food stories.', [], ['Cooking']);
        $creatorUser->setCreator($creator);
        $campaign = new Campaign(
            'small-table',
            'A Small Table',
            'Make a simple dinner feel special.',
            'Share a warm story about cooking at home with good ingredients.',
            'Food',
            ['Instagram'],
            ['1 short video'],
            300,
            700,
            'Croatia',
            1,
            new DateTimeImmutable('+20 days'),
            new DateTimeImmutable('today'),
            $company,
        );
        $entityManager->persist($companyUser);
        $entityManager->persist($creatorUser);
        $entityManager->persist($campaign);
        $otherCompanyUser = new User('other-brand@example.test', 'ROLE_COMPANY');
        $otherCompanyUser->setPassword('unused-test-hash');
        $otherCompanyUser->setCompany(new Company('other-brand', 'Other Brand', 'Fashion'));
        $entityManager->persist($otherCompanyUser);
        $entityManager->flush();

        $this->client->loginUser($companyUser, 'main');
        $csrf = $this->csrfToken();
        $this->client->request('GET', '/api/me/campaigns?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload()['data']);
        $this->client->request('GET', '/api/company/campaigns/small-table/applications?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']);
        $this->jsonRequest('PUT', '/api/me/profile', [
            'name' => 'Maker Brand Studio',
            'industry' => 'Food & drink',
            'logoUrl' => null,
        ], $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame('Maker Brand Studio', $this->payload()['data']['profile']['name']);
        $campaignFields = [
            'title' => 'A New Table',
            'summary' => 'A thoughtful campaign for a good meal.',
            'description' => '<p>Create a <strong>warm story</strong> about a meal shared with friends.</p><script>alert(1)</script>',
            'category' => 'Food',
            'channels' => ['Instagram'],
            'deliverables' => ['1 short video'],
            'budgetMin' => 250,
            'budgetMax' => 600,
            'currency' => 'EUR',
            'city' => 'Zagreb',
            'countryCode' => 'HR',
            'categories' => ['Food', 'Travel'],
            'creatorCount' => 2,
            'closesAt' => (new DateTimeImmutable('+35 days'))->format('Y-m-d'),
        ];
        $invalidFields = $campaignFields;
        $invalidFields['budgetMax'] = $invalidFields['budgetMin'] - 1;
        $invalidFields['closesAt'] = 'not-a-date';
        $this->jsonRequest('POST', '/api/company/campaigns', $invalidFields, $csrf);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(['budgetMax', 'closesAt'], $this->payload()['fields']);

        $this->jsonRequest('POST', '/api/company/campaigns', [...$campaignFields, 'description' => '<p><br></p>', 'categories' => [], 'countryCode' => 'ZZ'], $csrf);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(['description', 'categories', 'countryCode'], $this->payload()['fields']);

        $this->jsonRequest('POST', '/api/company/campaigns', $campaignFields, $csrf);
        self::assertResponseStatusCodeSame(201);
        $managedCampaign = $this->payload()['data'];
        self::assertSame('open', $managedCampaign['status']);
        self::assertSame('EUR', $managedCampaign['currency']);
        self::assertSame('Zagreb', $managedCampaign['city']);
        self::assertSame('HR', $managedCampaign['countryCode']);
        self::assertSame(['Food', 'Travel'], $managedCampaign['categories']);
        self::assertStringContainsString('<strong>warm story</strong>', $managedCampaign['description']);
        self::assertStringNotContainsString('<script', $managedCampaign['description']);
        self::assertArrayNotHasKey('location', $managedCampaign);
        self::assertArrayNotHasKey('moderationStatus', $managedCampaign);
        $this->client->request('GET', '/api/campaigns/'.$managedCampaign['slug'].'?locale=bs');
        self::assertResponseIsSuccessful();
        $this->jsonRequest('PUT', '/api/company/campaigns/'.$managedCampaign['id'], [...$campaignFields, 'status' => 'closed'], $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame('closed', $this->payload()['data']['status']);
        $this->client->request('GET', '/api/campaigns/'.$managedCampaign['slug'].'?locale=bs');
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('PUT', '/api/company/campaigns/'.$managedCampaign['id'], [...$campaignFields, 'status' => 'open'], $csrf);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/campaigns/'.$managedCampaign['slug'].'?locale=bs');
        self::assertResponseIsSuccessful();
        $this->client->loginUser($otherCompanyUser, 'main');
        $this->client->request('GET', '/api/company/campaigns/small-table/applications?locale=bs');
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('PUT', '/api/company/campaigns/'.$managedCampaign['id'], [], $this->csrfToken());
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($creatorUser, 'main');
        $csrf = $this->csrfToken();
        $this->jsonRequest('POST', '/api/campaigns/small-table/applications', [
            'message' => 'I create welcoming recipes and this campaign fits my community.',
        ], $csrf);
        self::assertResponseStatusCodeSame(201);
        $applicationId = $this->payload()['data']['id'];
        self::assertEmailCount(1);
        $applicationEmail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $applicationEmail);
        self::assertSame('brand@example.test', $applicationEmail->getTo()[0]->getAddress());
        self::assertEmailHtmlBodyContains($applicationEmail, 'Avery Creator');
        self::assertEmailHtmlBodyContains($applicationEmail, 'I create welcoming recipes');

        $this->jsonRequest('POST', '/api/campaigns/small-table/applications', [
            'message' => 'I create welcoming recipes and this campaign fits my community.',
        ], $csrf);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Već si se prijavio/la na ovu kampanju.', $this->payload()['error']);
        self::assertEmailCount(0);

        $this->client->request('GET', '/api/me/applications?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload()['data']);
        self::assertNull($this->payload()['data'][0]['conversationId']);

        $this->client->loginUser($companyUser, 'main');
        $csrf = $this->csrfToken();
        $this->client->request('GET', '/api/company/campaigns/small-table/applications?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame('Avery Creator', $this->payload()['data'][0]['creator']['displayName']);
        $this->jsonRequest('POST', '/api/company/applications/'.$applicationId.'/shortlist', [], $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame('shortlisted', $this->payload()['data']['status']);
        $conversationId = $this->payload()['conversationId'];
        $this->jsonRequest('POST', '/api/company/applications/'.$applicationId.'/offer', [
            'amount' => 500,
            'message' => 'We would love to work with you on this campaign.',
        ], $csrf);
        self::assertResponseStatusCodeSame(201);
        $offerId = $this->payload()['data']['id'];

        $this->client->loginUser($creatorUser, 'main');
        $csrf = $this->csrfToken();
        $this->jsonRequest('POST', '/api/me/offers/'.$offerId.'/respond', ['decision' => 'accept'], $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame('accepted', $this->payload()['data']['status']);
        self::assertEmailCount(1);
        $hiredEmail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $hiredEmail);
        self::assertSame('artist@example.test', $hiredEmail->getTo()[0]->getAddress());
        self::assertEmailHtmlBodyContains($hiredEmail, 'Maker Brand');
        self::assertEmailHtmlBodyContains($hiredEmail, 'A Small Table');

        $this->client->request('GET', '/api/me/applications?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame('accepted', $this->payload()['data'][0]['status']);
        self::assertSame($conversationId, $this->payload()['data'][0]['conversationId']);

        $this->client->loginUser($companyUser, 'main');
        $this->jsonRequest('POST', '/api/company/applications/'.$applicationId.'/reject', [], $this->csrfToken());
        self::assertResponseStatusCodeSame(409);
    }

    public function testInvalidCsrfAndDuplicateEmailAreRejected(): void
    {
        $this->jsonRequest('POST', '/api/auth/register', [
            'accountType' => 'company',
            'email' => 'brand@example.test',
            'password' => 'a-long-passphrase-for-wave',
            'name' => 'Small Brand',
            'phone' => '+387 61 123 456',
            'city' => 'Sarajevo',
            'country' => 'BA',
            'industry' => 'Food',
        ], 'invalid-token');
        self::assertResponseStatusCodeSame(403);

        $csrf = $this->csrfToken();
        $this->jsonRequest('POST', '/api/auth/register', [
            'accountType' => 'company',
            'email' => 'brand@example.test',
            'password' => 'a-long-passphrase-for-wave',
            'name' => 'Small Brand',
            'phone' => '+387 61 123 456',
            'city' => 'Sarajevo',
            'country' => 'BA',
            'industry' => 'Food',
        ], $csrf);
        self::assertResponseStatusCodeSame(201);
        $registeredCompany = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)->findOneBy(['email' => 'brand@example.test']);
        self::assertInstanceOf(User::class, $registeredCompany);
        self::assertSame('+387 61 123 456', $registeredCompany->getPhone());
        self::assertSame('Sarajevo', $registeredCompany->getCity());
        self::assertSame('BA', $registeredCompany->getCountryCode());

        $this->jsonRequest('POST', '/api/auth/register', [
            'accountType' => 'company',
            'email' => 'brand@example.test',
            'password' => 'another-long-passphrase',
            'name' => 'Second Brand',
            'phone' => '+387 61 123 456',
            'city' => 'Sarajevo',
            'country' => 'BA',
            'industry' => 'Food',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(409);
    }

    public function testRegistrationRequiresTypeSpecificAndSharedFields(): void
    {
        $creatorPayload = [
            'accountType' => 'creator',
            'email' => 'required-fields@example.test',
            'password' => 'a-long-passphrase-for-wave',
            'firstName' => 'Avery',
            'lastName' => 'Creator',
            'phone' => '+387 61 123 456',
            'category' => 'Travel',
            'categories' => ['Travel'],
            'city' => 'Sarajevo',
            'country' => 'BA',
            'location' => 'Sarajevo, Bosnia and Herzegovina',
        ];

        foreach (['firstName', 'lastName', 'phone', 'city', 'country'] as $requiredField) {
            $payload = $creatorPayload;
            unset($payload[$requiredField]);
            $this->jsonRequest('POST', '/api/auth/register', $payload, $this->csrfToken());
            self::assertResponseStatusCodeSame(400, sprintf('Missing creator field: %s', $requiredField));
        }

        $companyPayload = [
            'accountType' => 'company',
            'email' => 'required-company-fields@example.test',
            'password' => 'a-long-passphrase-for-wave',
            'name' => 'Required Company',
            'industry' => 'Food',
            'phone' => '+387 61 123 456',
            'city' => 'Sarajevo',
            'country' => 'BA',
        ];

        foreach (['phone', 'city', 'country'] as $requiredField) {
            $payload = $companyPayload;
            unset($payload[$requiredField]);
            $this->jsonRequest('POST', '/api/auth/register', $payload, $this->csrfToken());
            self::assertResponseStatusCodeSame(400, sprintf('Missing company field: %s', $requiredField));
        }
    }

    public function testRepeatedLoginAttemptsAreRateLimited(): void
    {
        $csrf = $this->csrfToken();
        $email = 'throttle-'.bin2hex(random_bytes(4)).'@example.test';
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => 'wrong-password'], $csrf);
            self::assertResponseStatusCodeSame(401);
        }
        $this->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => 'wrong-password'], $csrf);
        self::assertResponseStatusCodeSame(429);
        self::assertNotEmpty($this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testCampaignModerationEndpointsAreRemoved(): void
    {
        $this->client->request('GET', '/api/moderation/campaigns?locale=en');
        self::assertResponseStatusCodeSame(404);
    }

    public function testConsoleCanGrantAndRevokeModeratorAccessWithoutChangingAccountType(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('moderator-toggle@example.test', 'ROLE_CREATOR');
        $user->setPassword('unused-test-hash');
        $entityManager->persist($user);
        $entityManager->flush();

        $commandApp = new Application(static::$kernel);
        $command = new CommandTester($commandApp->find('app:moderator'));
        self::assertSame(0, $command->execute(['email' => $user->getEmail(), 'decision' => 'grant']));
        self::assertTrue($user->hasRole('ROLE_MODERATOR'));
        self::assertTrue($user->hasRole('ROLE_CREATOR'));

        self::assertSame(0, $command->execute(['email' => $user->getEmail(), 'decision' => 'revoke']));
        self::assertFalse($user->hasRole('ROLE_MODERATOR'));
        self::assertTrue($user->hasRole('ROLE_CREATOR'));
        self::assertSame(Command::INVALID, $command->execute(['email' => $user->getEmail(), 'decision' => 'promote']));
    }

    public function testCreatorCanSaveAndRemoveCampaignBookmarks(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $creatorUser = new User('bookmark-creator@example.test', 'ROLE_CREATOR');
        $creatorUser->setPassword('unused-test-hash');
        $creatorUser->setCreator(new Creator('bookmark-creator', 'Bookmark Creator', 'Travel', 'Sarajevo', '', [], []));
        $company = new Company('bookmark-brand', 'Bookmark Brand', 'Travel');
        $campaign = new Campaign(
            'bookmarkable-campaign',
            'A campaign to save',
            'A campaign worth returning to.',
            'A complete brief for a campaign that can be saved.',
            'Travel',
            ['Instagram'],
            ['1 post'],
            400,
            800,
            'Sarajevo',
            2,
            new DateTimeImmutable('+20 days'),
            new DateTimeImmutable('today'),
            $company,
            currency: 'RSD',
        );
        $entityManager->persist($creatorUser);
        $entityManager->persist($company);
        $entityManager->persist($campaign);
        $entityManager->flush();

        $this->client->loginUser($creatorUser, 'main');
        $this->jsonRequest('POST', '/api/campaigns/bookmarkable-campaign/bookmark', [], $this->csrfToken());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('RSD', $this->payload()['data']['currency']);

        $this->jsonRequest('POST', '/api/campaigns/bookmarkable-campaign/bookmark', [], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame('bookmarkable-campaign', $this->payload()['data']['slug']);

        $this->client->request('GET', '/api/me/bookmarks?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame(['bookmarkable-campaign'], array_column($this->payload()['data'], 'slug'));

        $this->jsonRequest('DELETE', '/api/campaigns/bookmarkable-campaign/bookmark', [], $this->csrfToken());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/me/bookmarks?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']);
    }

    public function testClosedCampaignChatsRejectNewMessagesButRetainReadableHistory(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $companyUser = new User('closed-chat-company@example.test', 'ROLE_COMPANY');
        $companyUser->setPassword('unused-test-hash');
        $company = new Company('closed-chat-company', 'Chat Company', 'Food');
        $companyUser->setCompany($company);
        $creatorUser = new User('closed-chat-creator@example.test', 'ROLE_CREATOR');
        $creatorUser->setPassword('unused-test-hash');
        $creator = new Creator('closed-chat-creator', 'Chat Creator', 'Food', 'Sarajevo', 'A creator profile.', [], []);
        $creatorUser->setCreator($creator);
        $campaign = new Campaign(
            'closed-chat', 'Closed campaign', 'Summary', 'Campaign brief', 'Food', ['Instagram'], ['1 post'],
            300, 700, 'Sarajevo', 2, new DateTimeImmutable('+20 days'), new DateTimeImmutable('today'), $company,
        );
        $campaign->setStatus('closed');
        $application = new CampaignApplication($campaign, $creator, 'An application for this campaign.');
        $application->setStatus('shortlisted');
        $conversation = new CampaignConversation($campaign, $creator, $companyUser);
        $message = new CampaignMessage($conversation, $companyUser, 'Existing message remains readable');
        foreach ([$companyUser, $creatorUser, $campaign, $application, $conversation, $message] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $conversationId = $conversation->getId();
        $creatorId = $creator->getId();

        foreach ([$creatorUser, $companyUser] as $participant) {
            $this->client->loginUser($participant, 'main');
            $this->jsonRequest('POST', '/api/me/conversations/'.$conversationId.'/messages', ['body' => 'Must not be sent'], $this->csrfToken());
            self::assertResponseStatusCodeSame(409);
            self::assertStringContainsString('zatvorena', $this->payload()['error']);
            $this->client->request('GET', '/api/me/conversations/'.$conversationId.'/messages?locale=bs');
            self::assertResponseIsSuccessful();
            self::assertCount(1, $this->payload()['data']);
            self::assertSame('Existing message remains readable', $this->payload()['data'][0]['body']);
        }
        $this->jsonRequest('POST', '/api/company/campaigns/closed-chat/conversations', [
            'creatorId' => $creatorId, 'message' => 'Must not start or append a conversation',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(409);
    }

    public function testCampaignConversationsAreScopedAndGenerateNotifications(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $companyUser = new User('chat-company@example.test', 'ROLE_COMPANY');
        $companyUser->setPassword('unused-test-hash');
        $company = new Company('chat-company', 'Chat Company', 'Food');
        $companyUser->setCompany($company);
        $creatorUser = new User('chat-creator@example.test', 'ROLE_CREATOR');
        $creatorUser->setPassword('unused-test-hash');
        $creator = new Creator('chat-creator', 'Chat Creator', 'Food', 'Sarajevo', 'A creator profile.', [], []);
        $creatorUser->setCreator($creator);
        $unverifiedCreatorUser = new User('unverified-chat-creator@example.test', 'ROLE_CREATOR');
        $unverifiedCreatorUser->setPassword('unused-test-hash');
        $unverifiedCreatorUser->setEmailVerified(false);
        $unverifiedCreatorUser->setCreator(new Creator(
            'unverified-chat-creator',
            'Unverified Chat Creator',
            'Food',
            'Sarajevo',
            'A public profile that cannot receive campaign invitations.',
            [],
            [],
        ));
        $otherCompanyUser = new User('other-chat-company@example.test', 'ROLE_COMPANY');
        $otherCompanyUser->setPassword('unused-test-hash');
        $otherCompanyUser->setCompany(new Company('other-chat-company', 'Other Chat Company', 'Retail'));
        $campaignOne = new Campaign(
            'chat-campaign-one',
            'Campaign One',
            'A campaign for a first chat.',
            'A full campaign brief for a private creator conversation.',
            'Food',
            ['Instagram'],
            ['1 post'],
            300,
            700,
            'Sarajevo',
            2,
            new DateTimeImmutable('+20 days'),
            new DateTimeImmutable('today'),
            $company,
        );
        $campaignTwo = new Campaign(
            'chat-campaign-two',
            'Campaign Two',
            'A campaign for a separate chat.',
            'A second full campaign brief for a separate creator conversation.',
            'Food',
            ['TikTok'],
            ['1 video'],
            300,
            700,
            'Sarajevo',
            2,
            new DateTimeImmutable('+20 days'),
            new DateTimeImmutable('today'),
            $company,
        );
        $entityManager->persist($companyUser);
        $entityManager->persist($creatorUser);
        $entityManager->persist($unverifiedCreatorUser);
        $entityManager->persist($otherCompanyUser);
        $entityManager->persist($campaignOne);
        $entityManager->persist($campaignTwo);
        $entityManager->flush();

        $this->client->loginUser($creatorUser, 'main');
        $this->jsonRequest('POST', '/api/campaigns/chat-campaign-one/applications', [
            'message' => 'I would love to create an original food story for this campaign.',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(201);
        $application = $this->payload()['data'];

        $this->client->loginUser($companyUser, 'main');
        $this->client->request('GET', '/api/creators/chat-creator?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['data']['canReceiveCampaignInvitations']);
        $this->client->request('GET', '/api/creators/unverified-chat-creator?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['data']['canReceiveCampaignInvitations']);

        $this->client->request('GET', '/api/me/notifications?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame('application_received', $this->payload()['data'][0]['type']);

        $this->jsonRequest('POST', '/api/company/campaigns/chat-campaign-one/conversations', [
            'creatorId' => $creator->getId(),
            'message' => 'Your application looks like a great fit. Could we discuss the brief?',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(404);

        $this->jsonRequest('POST', '/api/company/applications/'.$application['id'].'/shortlist', [], $this->csrfToken());
        self::assertResponseIsSuccessful();
        $firstConversationId = $this->payload()['conversationId'];
        $this->jsonRequest('POST', '/api/company/campaigns/chat-campaign-one/conversations', [
            'creatorId' => $creator->getId(),
            'message' => 'Your application looks like a great fit. Could we discuss the brief?',
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();
        $firstConversation = $this->payload()['data'];
        self::assertSame($firstConversationId, $firstConversation['id']);

        $this->jsonRequest('POST', '/api/company/campaigns/chat-campaign-two/invitations', [
            'creatorId' => $creator->getId(),
            'message' => 'We would love to invite you to create a short video for this campaign.',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(201);
        $invitation = $this->payload()['data'];
        self::assertSame('pending', $invitation['status']);
        self::assertArrayNotHasKey('conversationId', $invitation);

        $this->client->request('GET', '/api/me/campaigns?locale=bs');
        self::assertResponseIsSuccessful();
        $campaignsBySlug = array_column($this->payload()['data'], null, 'slug');
        self::assertContains($creator->getId(), $campaignsBySlug['chat-campaign-two']['invitedCreatorIds']);
        self::assertContains($creator->getId(), $campaignsBySlug['chat-campaign-one']['appliedCreatorIds']);

        $this->jsonRequest('POST', '/api/company/campaigns/chat-campaign-two/invitations', [
            'creatorId' => $creator->getId(),
            'message' => 'We would love to invite you again.',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(409);

        $this->jsonRequest('POST', '/api/company/campaigns/chat-campaign-one/conversations', [
            'creatorId' => $creator->getId(),
            'message' => 'Here is one more detail about the campaign timeline.',
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame($firstConversation['id'], $this->payload()['data']['id']);

        $this->client->loginUser($otherCompanyUser, 'main');
        $this->client->request('GET', '/api/me/conversations/'.$firstConversation['id'].'/messages?locale=bs');
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($creatorUser, 'main');
        $this->client->request('GET', '/api/me/conversations?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload()['data']);
        self::assertSame('A full campaign brief for a private creator conversation.', $this->payload()['data'][0]['campaign']['description']);
        self::assertSame('Chat Company', $this->payload()['data'][0]['company']['name']);
        self::assertArrayHasKey('avatarUrl', $this->payload()['data'][0]['creator']);
        $this->client->request('GET', '/api/me/conversations/'.$firstConversation['id'].'/messages?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->payload()['data']);
        $throughId = $this->payload()['data'][array_key_last($this->payload()['data'])]['id'];
        $this->jsonRequest('POST', '/api/me/conversations/'.$firstConversation['id'].'/read', ['throughId' => $throughId], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->payload()['conversation']['unreadCount']);
        $this->client->request('GET', '/api/me/notifications?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertNotContains('chat_message', array_column($this->payload()['data'], 'type'));

        $this->jsonRequest('POST', '/api/me/conversations/'.$firstConversation['id'].'/messages', [
            'body' => 'Thanks, I would be happy to discuss the campaign.',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(201);

        $this->client->loginUser($companyUser, 'main');
        $this->client->request('GET', '/api/me/conversations?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame('Thanks, I would be happy to discuss the campaign.', $this->payload()['data'][0]['lastMessage']);
        self::assertSame(1, $this->payload()['data'][0]['unreadCount']);
        $this->client->request('GET', '/api/me/notifications?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertNotContains('chat_message', array_column($this->payload()['data'], 'type'));

        $this->client->loginUser($creatorUser, 'main');
        $this->client->request('GET', '/api/me/invitations?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload()['data']);
        self::assertSame($invitation['id'], $this->payload()['data'][0]['id']);
        $this->jsonRequest('POST', '/api/me/invitations/'.$invitation['id'].'/respond', [
            'decision' => 'accept',
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame('accepted', $this->payload()['data']['status']);
        $secondConversation = $this->payload()['conversation'];
        self::assertNotSame($firstConversation['id'], $secondConversation['id']);
        $this->client->request('GET', '/api/me/conversations?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->payload()['data']);

        $this->jsonRequest('POST', '/api/company/campaigns/chat-campaign-one/conversations', [
            'creatorId' => $creator->getId(),
            'message' => 'Creators cannot initiate a company conversation.',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($companyUser, 'main');
        $this->jsonRequest('POST', '/api/company/applications/'.$application['id'].'/offer', [
            'amount' => 500,
            'message' => 'We would like to work together on this campaign.',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(201);
        $offerId = $this->payload()['data']['id'];

        $this->client->loginUser($creatorUser, 'main');
        $this->client->request('GET', '/api/me/notifications?locale=bs');
        self::assertResponseIsSuccessful();
        $creatorNotificationTypes = array_column($this->payload()['data'], 'type');
        self::assertContains('campaign_invitation', $creatorNotificationTypes);
        self::assertNotContains('chat_message', $creatorNotificationTypes);
        self::assertContains('application_shortlisted', $creatorNotificationTypes);
        self::assertContains('offer_received', $creatorNotificationTypes);

        $this->jsonRequest('POST', '/api/me/offers/'.$offerId.'/respond', [
            'decision' => 'accept',
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();

        $this->client->loginUser($companyUser, 'main');
        $this->client->request('GET', '/api/me/notifications?locale=bs');
        self::assertResponseIsSuccessful();
        $companyNotificationTypes = array_column($this->payload()['data'], 'type');
        self::assertContains('application_received', $companyNotificationTypes);
        self::assertNotContains('chat_message', $companyNotificationTypes);
        self::assertContains('invitation_accepted', $companyNotificationTypes);
        self::assertContains('offer_accepted', $companyNotificationTypes);
        $this->jsonRequest('POST', '/api/me/notifications/read-all', [], $this->csrfToken());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/me/notifications?locale=bs');
        self::assertResponseIsSuccessful();
        foreach ($this->payload()['data'] as $notification) {
            self::assertNotNull($notification['readAt']);
        }
        self::assertNotEmpty($entityManager->getRepository(Notification::class)->findBy([
            'recipient' => $companyUser,
            'type' => 'chat_message',
            'readAt' => null,
        ]));
        $this->client->request('GET', '/api/me/conversations/'.$firstConversation['id'].'/messages?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame(
            'Thanks, I would be happy to discuss the campaign.',
            $this->payload()['data'][2]['body'],
        );
        $throughId = $this->payload()['data'][array_key_last($this->payload()['data'])]['id'];
        $this->jsonRequest('POST', '/api/me/conversations/'.$firstConversation['id'].'/read', ['throughId' => $throughId], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->payload()['conversation']['unreadCount']);
    }

    public function testUnreadBadgesCountAllNotificationsAndOnlyIncomingParticipantMessages(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $creatorUser = new User('badge-creator@example.test', 'ROLE_CREATOR');
        $creatorUser->setPassword('unused-test-hash');
        $creator = new Creator('badge-creator', 'Badge Creator', 'Food', 'Sarajevo', 'A creator.', [], []);
        $creatorUser->setCreator($creator);
        $companyUser = new User('badge-company@example.test', 'ROLE_COMPANY');
        $companyUser->setPassword('unused-test-hash');
        $company = new Company('badge-company', 'Badge Company', 'Food');
        $companyUser->setCompany($company);
        $otherUser = new User('badge-other@example.test', 'ROLE_COMPANY');
        $otherUser->setPassword('unused-test-hash');
        $otherUser->setCompany(new Company('badge-other', 'Other Company', 'Food'));
        $campaign = new Campaign(
            'badge-campaign', 'Badge Campaign', 'A campaign.', 'A full campaign brief.',
            'Food', ['Instagram'], ['1 post'], 300, 700, 'Sarajevo', 2,
            new DateTimeImmutable('+20 days'), new DateTimeImmutable('today'), $company,
        );
        $conversation = new CampaignConversation($campaign, $creator, $companyUser);
        $incoming = new CampaignMessage($conversation, $companyUser, 'An unread incoming message');
        $outgoing = new CampaignMessage($conversation, $creatorUser, 'An unread outgoing message');
        $alreadyRead = new CampaignMessage($conversation, $companyUser, 'Already read');
        $alreadyRead->markRead();
        foreach ([$creatorUser, $companyUser, $otherUser, $campaign, $conversation, $incoming, $outgoing, $alreadyRead] as $entity) {
            $entityManager->persist($entity);
        }
        for ($index = 0; $index < 32; ++$index) {
            $entityManager->persist(new Notification($creatorUser, 'offer_received', $companyUser));
        }
        $readNotification = new Notification($creatorUser, 'offer_received', $companyUser);
        $readNotification->markRead();
        $entityManager->persist($readNotification);
        $entityManager->persist(new Notification($creatorUser, 'chat_message', $companyUser, $campaign, $conversation));
        $entityManager->persist(new Notification($companyUser, 'chat_message', $creatorUser, $campaign, $conversation));
        $entityManager->persist(new Notification($otherUser, 'offer_received', $creatorUser));
        $entityManager->flush();

        $counter = static::getContainer()->get(UnreadInboxCounter::class);
        self::assertSame(32, $counter->notifications($creatorUser));
        self::assertSame(33, $counter->total($creatorUser));
        self::assertSame(1, $counter->total($companyUser));
        self::assertSame(1, $counter->total($otherUser));

        $this->client->loginUser($creatorUser, 'main');
        $this->client->request('GET', '/api/me/notifications?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertCount(30, $this->payload()['data']);
        self::assertSame(32, $this->payload()['unreadCount']);
        $this->client->request('GET', '/api/me/conversations/'.$conversation->getId().'/messages?locale=bs');
        self::assertResponseIsSuccessful();
        $throughId = $this->payload()['data'][array_key_last($this->payload()['data'])]['id'];
        $this->jsonRequest('POST', '/api/me/conversations/'.$conversation->getId().'/read', ['throughId' => $throughId], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame(32, static::getContainer()->get(UnreadInboxCounter::class)->total($creatorUser));
        $this->jsonRequest('POST', '/api/me/notifications/read-all', [], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame(0, static::getContainer()->get(UnreadInboxCounter::class)->total($creatorUser));
        self::assertSame(1, static::getContainer()->get(UnreadInboxCounter::class)->total($companyUser));
    }

    public function testChatHistoryUsesBoundedCursorsAndExplicitParticipantReadReceipts(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $creatorUser = new User('history-creator@example.test', 'ROLE_CREATOR');
        $creatorUser->setPassword('unused-test-hash');
        $creator = new Creator('history-creator', 'History Creator', 'Food', 'Sarajevo', 'Bio', [], []);
        $creatorUser->setCreator($creator);
        $companyUser = new User('history-company@example.test', 'ROLE_COMPANY');
        $companyUser->setPassword('unused-test-hash');
        $company = new Company('history-company', 'History Company', 'Food');
        $companyUser->setCompany($company);
        $outsider = new User('history-outsider@example.test', 'ROLE_COMPANY');
        $outsider->setPassword('unused-test-hash');
        $outsider->setCompany(new Company('history-outsider', 'Outsider', 'Food'));
        $campaign = new Campaign(
            'history-campaign', 'History Campaign', 'Summary', 'Brief', 'Food', ['Instagram'],
            ['1 post'], 300, 700, 'Sarajevo', 2, new DateTimeImmutable('+20 days'), new DateTimeImmutable(), $company,
        );
        $conversation = new CampaignConversation($campaign, $creator, $companyUser);
        $inquiry = new CreatorInquiry($creator, $company, null, null, null, null, 'Initial inquiry');
        $inquiry->respond('accepted');
        foreach ([$creatorUser, $companyUser, $outsider, $campaign, $conversation, $inquiry] as $entity) $em->persist($entity);
        $messages = [];
        $inquiryMessages = [];
        for ($index = 1; $index <= 125; ++$index) {
            $messages[] = $message = new CampaignMessage($conversation, $companyUser, 'Message '.$index);
            $inquiryMessages[] = $direct = new InquiryMessage($inquiry, $companyUser, 'Message '.$index);
            $em->persist($message);
            $em->persist($direct);
        }
        $notification = new Notification($creatorUser, 'chat_message', $companyUser, $campaign, $conversation);
        $em->persist($notification);
        $em->flush();
        $this->client->loginUser($creatorUser, 'main');
        foreach ([['conversations', $conversation->getId(), $messages], ['inquiries', $inquiry->getId(), $inquiryMessages]] as [$kind, $id, $history]) {
            $base = '/api/me/'.$kind.'/'.$id;
            $this->client->request('GET', $base.'/messages?locale=en');
            self::assertResponseIsSuccessful();
            self::assertCount(50, $this->payload()['data']);
            self::assertSame('Message 76', $this->payload()['data'][0]['body']);
            self::assertSame($history[0]->getId(), $this->payload()['meta']['firstUnreadId']);
            self::assertTrue($this->payload()['meta']['hasMoreOlder']);
            self::assertNull($this->payload()['data'][0]['readAt']);
            $this->client->request('GET', $base.'/messages?locale=en&before='.$history[75]->getId());
            self::assertCount(50, $this->payload()['data']);
            self::assertSame('Message 26', $this->payload()['data'][0]['body']);
            $this->client->request('GET', $base.'/messages?locale=en&before='.$history[25]->getId());
            self::assertCount(25, $this->payload()['data']);
            self::assertFalse($this->payload()['meta']['hasMoreOlder']);
            foreach (['limit=0', 'limit=101', 'before=-1', 'before=1&after=2'] as $query) {
                $this->client->request('GET', $base.'/messages?locale=en&'.$query);
                self::assertResponseStatusCodeSame(400);
            }
            $this->client->request('GET', $base.'/messages?locale=en&after='.$history[24]->getId());
            self::assertCount(50, $this->payload()['data']);
            self::assertSame('Message 26', $this->payload()['data'][0]['body']);
            self::assertTrue($this->payload()['meta']['hasMoreNewer']);
            $this->jsonRequest('POST', $base.'/read', ['throughId' => $history[74]->getId()], $this->csrfToken());
            self::assertResponseIsSuccessful();
            if ($kind === 'conversations') {
                self::assertNull(static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT read_at FROM wave_notification WHERE id = ?', [$notification->getId()]));
            }
            $this->client->request('GET', $base.'/messages?locale=en&after='.$history[73]->getId());
            self::assertNotNull($this->payload()['data'][0]['readAt']);
            self::assertNull($this->payload()['data'][1]['readAt']);
            self::assertSame($history[75]->getId(), $this->payload()['meta']['firstUnreadId']);
            $this->client->loginUser($companyUser, 'main');
            $this->client->request('GET', $base.'/messages?locale=en');
            self::assertSame($history[74]->getId(), $this->payload()['readReceipt']['throughId']);
            self::assertNull($this->payload()['meta']['firstUnreadId']);
            $this->client->loginUser($outsider, 'main');
            $this->jsonRequest('POST', $base.'/read', ['throughId' => $history[124]->getId()], $this->csrfToken());
            self::assertResponseStatusCodeSame($kind === 'conversations' ? 404 : 403);
            $this->client->loginUser($creatorUser, 'main');
            $this->jsonRequest('POST', $base.'/read', ['throughId' => PHP_INT_MAX], $this->csrfToken());
            self::assertResponseStatusCodeSame(400);
            $this->jsonRequest('POST', $base.'/read', ['throughId' => $history[124]->getId()], 'invalid-token');
            self::assertResponseStatusCodeSame(403);
            $this->jsonRequest('POST', $base.'/read', ['throughId' => $history[124]->getId()], $this->csrfToken());
            self::assertResponseIsSuccessful();
            $this->client->request('GET', $base.'/messages?locale=en');
            self::assertNotNull($this->payload()['data'][49]['readAt']);
            self::assertNull($this->payload()['meta']['firstUnreadId']);
            if ($kind === 'conversations') {
                self::assertNotNull(static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT read_at FROM wave_notification WHERE id = ?', [$notification->getId()]));
            }
        }
    }

    public function testRealtimeAuthorizationAndBrowserPushSubscriptionsAreUserScoped(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('realtime-creator@example.test', 'ROLE_CREATOR');
        $user->setPassword('unused-test-hash');
        $user->setCreator(new Creator(
            'realtime-creator',
            'Realtime Creator',
            'Lifestyle',
            'Sarajevo',
            'A creator profile for realtime tests.',
            [],
            [],
        ));
        $entityManager->persist($user);
        $entityManager->flush();
        $this->client->loginUser($user, 'main');

        $this->client->request('GET', 'http://127.0.0.1/api/me/realtime?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame('https://wave.local/users/'.$user->getId(), $this->payload()['data']['topic']);
        self::assertSame('http://127.0.0.1:3000/.well-known/mercure', $this->payload()['data']['hubUrl']);
        $cookies = $this->client->getResponse()->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('mercureAuthorization', $cookies[0]->getName());
        self::assertTrue($cookies[0]->isHttpOnly());

        $csrf = $this->csrfToken();
        $this->jsonRequest('POST', '/api/me/push-subscriptions', [
            'endpoint' => 'http://127.0.0.1:9999/not-a-push-service',
            'keys' => ['p256dh' => 'B'.str_repeat('a', 40), 'auth' => 'B'.str_repeat('b', 20)],
        ], $csrf);
        self::assertResponseStatusCodeSame(400);

        $subscriptionPayload = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
            'keys' => ['p256dh' => 'B'.str_repeat('a', 40), 'auth' => 'B'.str_repeat('b', 20)],
        ];
        $this->jsonRequest('POST', '/api/me/push-subscriptions', $subscriptionPayload, $this->csrfToken());
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $entityManager->getRepository(UserPushSubscription::class)->count(['user' => $user]));

        $this->jsonRequest('DELETE', '/api/me/push-subscriptions', [
            'endpoint' => $subscriptionPayload['endpoint'],
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame(0, $entityManager->getRepository(UserPushSubscription::class)->count(['user' => $user]));
    }

    private function csrfToken(): string
    {
        $this->client->request('GET', '/api/auth/csrf?locale=bs');
        self::assertResponseIsSuccessful();

        return $this->payload()['csrfToken'];
    }

    private function uploadImage(string $folder, string $csrf, string $name): array
    {
        $path = tempnam(sys_get_temp_dir(), 'wave-upload-');
        self::assertNotFalse($path);
        imagepng(imagecreatetruecolor(1, 1), $path);
        $this->client->request(
            'POST',
            '/api/media?folder='.$folder.'&locale=bs',
            [],
            ['file' => new UploadedFile($path, $name, 'image/png', null, true)],
            ['HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_ACCEPT' => 'application/json'],
        );
        if (is_file($path)) {
            unlink($path);
        }

        return $this->payload();
    }

    private function jsonRequest(string $method, string $path, array $body, string $csrf): void
    {
        $this->client->request($method, $path.'?locale=bs', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

}
