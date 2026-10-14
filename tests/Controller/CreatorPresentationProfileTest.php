<?php

namespace App\Tests\Controller;

use App\Entity\Creator;
use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\User;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CreatorPresentationProfileTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    private function creator(string $slug): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User($slug.'@example.test', 'ROLE_CREATOR');
        $user->setPassword('unused');
        $user->setApproved(true);
        $user->setEmailVerified(true);
        $user->setHideMyAccount(false);
        $user->setCreator(new Creator($slug, $slug, 'Beauty', 'Sarajevo', '', []));
        $em->persist($user);
        $em->flush();
        return $user;
    }

    private function payload(): array { return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }
    private function token(): string { $this->client->request('GET', '/api/auth/csrf'); return $this->payload()['csrfToken']; }
    private function update(array $body): void
    {
        $csrf = $this->token();
        $this->client->request('PUT', '/api/me/profile', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testExtendedFieldsSurviveAndForgedReferencesCannotOverwriteTheProfile(): void
    {
        $owner = $this->creator('studio-owner');
        $this->client->loginUser($owner, 'main');
        $body = [
            'displayName' => 'Studio Owner', 'categories' => ['Beauty'], 'bio' => '', 'avatarUrl' => null, 'tags' => [],
            'presentation' => ['languages' => ['bs', 'en'], 'availability' => 'from', 'availableFrom' => '2026-10-20'],
            'socialProfiles' => [['platform' => 'Instagram', 'handle' => '@studio', 'followers' => 2000, 'averageViews' => 500, 'lastUpdated' => '2026-09-01', 'audienceCountries' => [['countryCode' => 'BA', 'percent' => 80]]]],
            'portfolio' => [['type' => 'video', 'title' => 'My project', 'url' => 'https://youtu.be/abcdefghijk', 'source' => 'external', 'client' => 'A brand', 'publicPermission' => false, 'results' => [['metric' => 'Views', 'value' => 100, 'period' => '7 days']]]],
            'packages' => [['title' => 'Custom content', 'description' => 'Product video for the brand channels.', 'platform' => 'All', 'price' => 999, 'priceMode' => 'agreement', 'mode' => 'content', 'deliveryDays' => 5, 'revisions' => 0, 'usage' => 'ads', 'usageDays' => 30]],
        ];
        $this->update($body);
        self::assertResponseIsSuccessful();
        self::assertSame(['bs', 'en'], $this->payload()['data']['presentation']['languages']);
        self::assertNull($this->payload()['data']['packages'][0]['price']);
        self::assertSame(0, $this->payload()['data']['packages'][0]['revisions']);
        $this->client->request('GET', '/api/creators/studio-owner');
        self::assertSame([], $this->payload()['data']['portfolio']);
        $this->client->request('GET', '/api/creators');
        self::assertCount(1, $this->payload()['data']);
        self::assertSame([], $this->payload()['data'][0]['portfolio']);
        unset($body['socialProfiles'][0]['lastUpdated']);
        $body['bio'] = 'Updated introduction';
        $this->update($body);
        self::assertResponseIsSuccessful();
        self::assertSame('2026-09-01', $this->payload()['data']['socialProfiles'][0]['lastUpdated']);
        $body['portfolio'][0]['source'] = 'wave';
        $body['portfolio'][0]['campaignId'] = 999999;
        $this->update($body);
        self::assertResponseStatusCodeSame(400);
        $body['portfolio'][0]['source'] = 'external';
        $body['portfolio'][0]['coverMediaId'] = 999999;
        $this->update($body);
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/auth/me');
        self::assertSame('external', $this->payload()['data']['profile']['portfolio'][0]['source']);
    }

    public function testVisitsExcludeOwnersDeduplicateSessionsAndStatisticsAreOwnerScoped(): void
    {
        $owner = $this->creator('visit-owner');
        $other = $this->creator('visit-other');
        $this->client->loginUser($owner, 'main');
        $csrf = $this->token();
        $this->client->request('POST', '/api/creators/visit-owner/view', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertFalse($this->payload()['recorded']);
        $this->client->restart();
        $csrf = $this->token();
        foreach ([true, false] as $recorded) {
            $this->client->request('POST', '/api/creators/visit-owner/view', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseIsSuccessful();
            self::assertSame($recorded, $this->payload()['recorded']);
        }
        $this->client->request('GET', '/api/me/profile-statistics');
        self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($owner, 'main');
        $this->client->request('GET', '/api/me/profile-statistics?days=30');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['data']['counts']['views']);
        $this->client->loginUser($other, 'main');
        $this->client->request('GET', '/api/me/profile-statistics?creator='.$owner->getCreator()->getId());
        self::assertSame(0, $this->payload()['data']['counts']['views']);
        self::assertSame($other->getId(), $this->payload()['userId']);
    }

    public function testExistingImageWorksCanBeSavedRepeatedlyAndDraftFilesArePrivate(): void
    {
        $owner = $this->creator('image-owner');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $storage = self::getContainer()->get(MediaStorage::class);
        $folder = new MediaFolder('creator-portfolio', 'Portfolio');
        $em->persist($folder);
        $em->flush();
        $file = new UploadedFile(dirname(__DIR__, 2).'/public/images/home-faq-creator.webp', 'work.webp', test: true);
        $media = [];
        try {
            for ($index = 0; $index < 2; ++$index) {
                $stored = $storage->store($file, $folder, $owner);
                $image = new Media($folder, $owner, 'work.webp', $stored['path'], $stored['mimeType'], $stored['fileSize']);
                $em->persist($image);
                $media[] = $image;
            }
            $em->flush();
            $this->client->loginUser($owner, 'main');
            $body = [
                'displayName' => 'Image Owner', 'categories' => ['Beauty'], 'bio' => '', 'avatarUrl' => null, 'tags' => [],
                'portfolio' => [[
                    'id' => 'image-work-one', 'type' => 'image', 'title' => 'Private work', 'mediaId' => $media[0]->getId(),
                    'coverMediaId' => $media[1]->getId(), 'publicPermission' => false,
                ]],
            ];
            foreach ([1, 2] as $attempt) {
                $this->update($body);
                self::assertResponseIsSuccessful('Saving the same images twice must not conflict with unique positions.');
                self::assertSame($media[1]->getId(), $this->payload()['data']['portfolio'][0]['coverMediaId']);
            }
            $this->client->request('GET', $media[0]->getUrl());
            self::assertResponseIsSuccessful();
            $this->client->restart();
            foreach ($media as $image) {
                $this->client->request('GET', $image->getUrl());
                self::assertResponseStatusCodeSame(404);
            }
            $this->client->loginUser($owner, 'main');
            $body['portfolio'][0]['publicPermission'] = true;
            $this->update($body);
            self::assertResponseIsSuccessful();
            $this->client->restart();
            foreach ($media as $image) {
                $this->client->request('GET', $image->getUrl());
                self::assertResponseIsSuccessful();
            }
        } finally {
            foreach ($media as $image) {
                $storage->remove($image);
            }
        }
    }
}
