<?php

namespace App\Tests\Controller;

use App\Entity\ListingAd;
use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\User;
use App\Service\UnusedMediaCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ListingAdTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $adminId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $admin = new User('ad-admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused');
        $admin->setAdmin(true);
        $admin->setApproved(true);
        $em->persist($admin);
        $em->flush();
        $this->adminId = $admin->getId();
    }

    public function testAdminPermissionsCsrfDraftsAndActiveAdsStaySeparatePerDirectory(): void
    {
        $this->client->request('GET', '/api/listing-ads/creators');
        self::assertSame([], $this->payload()['data']);
        $this->client->request('GET', '/api/admin/listing-ads/creators');
        self::assertResponseStatusCodeSame(401);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $regular = new User('ordinary@example.test', 'ROLE_CREATOR');
        $regular->setPassword('unused');
        $regular->setApproved(true);
        $em->persist($regular); $em->flush();
        $this->client->loginUser($regular);
        $this->client->request('GET', '/api/admin/listing-ads/creators');
        self::assertResponseStatusCodeSame(403);
        $this->admin();
        $this->client->jsonRequest('POST', '/api/admin/listing-ads/creators', $this->ad());
        self::assertResponseStatusCodeSame(403);
        foreach (['creators', 'companies', 'campaigns'] as $kind) {
            $this->write('POST', '/api/admin/listing-ads/'.$kind, $this->ad());
            self::assertResponseStatusCodeSame(201);
            $ad = $this->payload()['data'];
            $this->client->request('GET', '/api/listing-ads/'.$kind);
            self::assertSame([], $this->payload()['data']);
            $this->write('PUT', '/api/admin/listing-ads/'.$kind.'/'.$ad['id'], [...$ad, 'enabled' => true]);
            self::assertResponseIsSuccessful();
            $this->client->request('GET', '/api/listing-ads/'.$kind);
            self::assertSame([$ad['id']], array_column($this->payload()['data'], 'id'));
            self::assertCount(2, $this->payload()['data'][0]['placements']);
            $this->write('PUT', '/api/admin/listing-ads/'.$kind.'/'.$ad['id'], [...$ad, 'enabled' => false]);
            self::assertResponseIsSuccessful();
            $this->client->request('GET', '/api/listing-ads/'.$kind);
            self::assertSame([], $this->payload()['data']);
        }
        $this->client->request('GET', '/api/admin/listing-ads/companies');
        self::assertCount(1, $this->payload()['data']);
        $this->client->request('GET', '/api/listing-ads/companies?locale=invalid');
        self::assertResponseStatusCodeSame(400);
    }

    public function testValidationAndPlacementConflictsPreserveExistingAds(): void
    {
        $this->admin();
        $this->write('POST', '/api/admin/listing-ads/creators', [...$this->ad(), 'enabled' => true]);
        $existing = $this->payload()['data'];
        foreach ([
            ['helpLabel' => 'Help', 'helpUrl' => 'javascript:alert(1)'], ['helpLabel' => 'Help', 'helpUrl' => ''], ['artwork' => 'https://external.test/ad.svg'], ['title' => ''], ['span' => 3], ['span' => '2'], ['palette' => '#f00'], ['enabled' => 1],
            ['url' => 'javascript:alert(1)'], ['url' => '//evil.test'], ['url' => '/\\evil.test'], ['url' => "https://wa\nve.ba"],
            ['url' => 'https://user:password@example.com'], ['url' => '', 'buttonLabel' => 'Go'],
            ['placements' => []], ['placements' => [['row' => 0, 'column' => 1]]],
            ['placements' => [['row' => 1, 'column' => 4]]],
            ['placements' => [['row' => 2, 'column' => 1], ['row' => 2, 'column' => 2]]],
            ['imageId' => 999999],
        ] as $invalid) {
            $this->write('POST', '/api/admin/listing-ads/creators', [...$this->ad(), ...$invalid]);
            self::assertResponseStatusCodeSame(422);
        }
        $this->write('POST', '/api/admin/listing-ads/creators', [...$this->ad(), 'enabled' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('listingAds.overlap', $this->payload()['code']);
        // Drafts may share planned positions; activation must resolve conflicts.
        $this->write('POST', '/api/admin/listing-ads/creators', $this->ad());
        self::assertResponseStatusCodeSame(201);
        $draft = $this->payload()['data'];
        $this->write('PUT', '/api/admin/listing-ads/creators/'.$draft['id'], [...$draft, 'enabled' => true]);
        self::assertResponseStatusCodeSame(422);
        $this->write('PUT', '/api/admin/listing-ads/companies/'.$existing['id'], $existing);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/listing-ads/creators');
        self::assertSame([$existing['id']], array_column($this->payload()['data'], 'id'));
        $this->write('PUT', '/api/admin/listing-ads/creators/'.$existing['id'], [...$existing, 'title' => 'Updated']);
        self::assertResponseIsSuccessful();
        self::assertSame('Updated', $this->payload()['data']['title']);
        self::assertSame('/podrska', $this->payload()['data']['helpUrl']);
    }

    public function testAdImagesRespectOwnershipPublicActivationAndCleanupReferences(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->find(User::class, $this->adminId);
        $folder = new MediaFolder('listing-ad', 'Listing ads'); $em->persist($folder);
        $other = new User('other-admin@example.test', 'ROLE_COMPANY'); $other->setPassword('unused'); $other->setAdmin(true); $em->persist($other);
        $image = new Media($folder, $admin, 'ad.png', 'listing-ad/test.webp', 'image/webp', 20);
        $foreign = new Media($folder, $other, 'other.png', 'listing-ad/other.webp', 'image/webp', 20);
        $em->persist($image); $em->persist($foreign); $em->flush();
        $imageId = $image->getId(); $foreignId = $foreign->getId();
        $this->admin();
        $this->write('POST', '/api/admin/listing-ads/creators', [...$this->ad(), 'imageId' => $foreignId]);
        self::assertResponseStatusCodeSame(422);
        $this->write('POST', '/api/admin/listing-ads/creators', [...$this->ad(), 'imageId' => $imageId]);
        self::assertResponseStatusCodeSame(201);
        $ad = $this->payload()['data'];
        self::assertSame('/api/media/'.$imageId.'/file', $ad['imageUrl']);
        self::assertTrue(static::getContainer()->get(UnusedMediaCleanup::class)->isReferenced($imageId));
        self::assertSame([], static::getContainer()->get(UnusedMediaCleanup::class)->remove([$imageId]));
        $this->write('PUT', '/api/admin/listing-ads/creators/'.$ad['id'], [...$ad, 'enabled' => true]);
        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $controller = new \ReflectionMethod(\App\Controller\Api\MediaController::class, 'isPublicMedia');
        self::assertTrue($controller->invoke(new \App\Controller\Api\MediaController(), $em->find(Media::class, $imageId), $em));
        $this->write('PUT', '/api/admin/listing-ads/creators/'.$ad['id'], [...$ad, 'enabled' => false]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertFalse($controller->invoke(new \App\Controller\Api\MediaController(), $em->find(Media::class, $imageId), $em));
        $this->write('PUT', '/api/admin/listing-ads/creators/'.$ad['id'], [...$ad, 'imageId' => null]);
        self::assertFalse(static::getContainer()->get(UnusedMediaCleanup::class)->isReferenced($imageId));
    }

    private function admin(): void
    {
        $this->client->loginUser(static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->adminId));
    }
    private function write(string $method, string $path, array $body): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $token = $this->payload()['csrfToken'];
        $this->client->jsonRequest($method, $path, $body, ['HTTP_X_CSRF_TOKEN' => $token]);
    }
    private function payload(): array { return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }
    private function ad(): array
    {
        return ['name' => 'Example', 'title' => 'Your next collaboration', 'body' => 'Discover Wave', 'buttonLabel' => 'Go', 'url' => '/kampanje', 'span' => 2, 'palette' => 'forest', 'enabled' => false, 'imageId' => null, 'helpLabel' => 'Need help?', 'helpUrl' => '/podrska', 'placements' => [['row' => 2, 'column' => 1], ['row' => 5, 'column' => 3]]];
    }
}
