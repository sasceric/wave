<?php

namespace App\Tests\Controller;

use App\Background\JobExecutor;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\User;
use App\Service\ImageUploadProcessor;
use App\Service\MediaStorage;
use App\Service\MediaThumbnails;
use App\Service\StoredFileCleanup;
use App\Service\UnusedMediaCleanup;
use App\Support\TicketAttachments;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MediaLifecycleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $directory;
    private MediaStorage $storage;
    private MediaThumbnails $thumbnails;
    private StoredFileCleanup $files;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $schema = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $this->directory = sys_get_temp_dir().'/wave-media-lifecycle-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory);
        $processor = new ImageUploadProcessor();
        $this->thumbnails = new MediaThumbnails($this->directory.'/media', $filesystem, $processor);
        $this->storage = new MediaStorage($this->directory.'/media', $filesystem, $processor, $this->thumbnails);
        $this->files = new StoredFileCleanup($this->em->getConnection(), $this->storage, new TicketAttachments($this->directory.'/support', $processor), new NullLogger());
        $container->set(MediaStorage::class, $this->storage);
        $container->set(MediaThumbnails::class, $this->thumbnails);
        $container->set(StoredFileCleanup::class, $this->files);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testCreatorReplacementAndPortfolioRemovalDeleteOnlyUnusedFiles(): void
    {
        $owner = $this->user('creator');
        $oldAvatar = $this->media($owner, 'creator-avatar');
        $newAvatar = $this->media($owner, 'creator-avatar');
        $main = $this->media($owner, 'creator-portfolio');
        $cover = $this->media($owner, 'creator-portfolio');
        $extra = $this->media($owner, 'creator-portfolio');
        $retained = $this->media($owner, 'creator-portfolio');
        $work = static fn (Media $media): array => ['type' => 'image', 'mediaId' => $media->getId(), 'title' => 'Project'];
        $body = [
            'displayName' => 'Creator', 'categories' => ['Travel'], 'bio' => '', 'tags' => [],
            'avatarMediaId' => $oldAvatar->getId(),
            'portfolio' => [[...$work($main), 'coverMediaId' => $cover->getId(), 'media' => [['type' => 'image', 'mediaId' => $extra->getId()]]], $work($retained)],
        ];
        $this->client->loginUser($owner, 'main');
        $this->request('PUT', '/api/me/profile', $body);
        self::assertResponseIsSuccessful();
        // Re-saving/reordering recreates associations, but must keep their files.
        $body['portfolio'] = array_reverse($body['portfolio']);
        $this->request('PUT', '/api/me/profile', $body);
        self::assertResponseIsSuccessful();
        foreach ([$oldAvatar, $main, $cover, $extra, $retained] as $media) $this->assertStored($media, true);

        $body['avatarMediaId'] = $newAvatar->getId();
        $body['portfolio'] = [[...$work($retained), 'coverMediaId' => $cover->getId()]];
        $this->request('PUT', '/api/me/profile', [...$body, 'displayName' => '']);
        self::assertResponseStatusCodeSame(400);
        foreach ([$oldAvatar, $newAvatar, $main, $cover, $extra, $retained] as $media) $this->assertStored($media, true);
        $this->request('PUT', '/api/me/profile', $body);
        self::assertResponseIsSuccessful();
        foreach ([$oldAvatar, $main, $extra] as $media) $this->assertStored($media, false);
        foreach ([$newAvatar, $cover, $retained] as $media) $this->assertStored($media, true);
        $this->request('PUT', '/api/me/profile', [...$body, 'portfolio' => []]);
        self::assertResponseIsSuccessful();
        $this->assertStored($cover, false);
        $this->assertStored($retained, false);
        $this->assertStored($newAvatar, true);
    }

    public function testCompanyLogoAndCoverReplacementAndRemovalDeleteTheirFiles(): void
    {
        $owner = $this->user('company');
        $logo = $this->media($owner, 'company-logo');
        $cover = $this->media($owner, 'company-cover');
        $newLogo = $this->media($owner, 'company-logo');
        $newCover = $this->media($owner, 'company-cover');
        $body = ['name' => 'Company', 'industries' => ['Travel'], 'logoMediaId' => $logo->getId(), 'coverMediaId' => $cover->getId()];
        $this->client->loginUser($owner, 'main');
        $this->request('PUT', '/api/me/profile', $body);
        self::assertResponseIsSuccessful();
        $body = [...$body, 'logoMediaId' => $newLogo->getId(), 'coverMediaId' => $newCover->getId()];
        $this->request('PUT', '/api/me/profile', [...$body, 'industries' => []]);
        self::assertResponseStatusCodeSame(400);
        $this->assertStored($logo, true);
        $this->assertStored($cover, true);
        $this->request('PUT', '/api/me/profile', $body);
        self::assertResponseIsSuccessful();
        $this->assertStored($logo, false);
        $this->assertStored($cover, false);
        $this->assertStored($newLogo, true);
        $this->assertStored($newCover, true);
        $this->request('PUT', '/api/me/profile', [...$body, 'logoMediaId' => null, 'coverMediaId' => null]);
        self::assertResponseIsSuccessful();
        $this->assertStored($newLogo, false);
        $this->assertStored($newCover, false);
    }

    public function testSharedCampaignCoverSurvivesUntilItsLastReferenceIsRemoved(): void
    {
        $owner = $this->user('company');
        $cover = $this->media($owner, 'campaign-cover');
        $replacement = $this->media($owner, 'campaign-cover');
        $this->client->loginUser($owner, 'main');
        $body = [
            'title' => 'A thoughtful campaign', 'summary' => 'A clear campaign brief for creators.',
            'description' => 'Create a thoughtful travel video with our company.', 'categories' => ['Travel'],
            'channels' => ['Instagram'], 'deliverables' => ['1 video'], 'budgetMin' => 100, 'budgetMax' => 300,
            'currency' => 'BAM', 'city' => 'Sarajevo', 'countryCode' => 'BA', 'creatorCount' => 2,
            'closesAt' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'), 'coverMediaId' => $cover->getId(),
        ];
        $ids = [];
        for ($i = 0; $i < 2; ++$i) {
            $this->request('POST', '/api/company/campaigns', $body);
            self::assertResponseStatusCodeSame(201);
            $ids[] = json_decode($this->client->getResponse()->getContent(), true)['data']['id'];
        }
        $this->request('PUT', '/api/company/campaigns/'.$ids[0], [...$body, 'status' => 'open', 'coverMediaId' => $replacement->getId()]);
        self::assertResponseIsSuccessful();
        $this->assertStored($cover, true);
        $this->request('PUT', '/api/company/campaigns/'.$ids[1], [...$body, 'status' => 'open', 'coverMediaId' => null]);
        self::assertResponseIsSuccessful();
        $this->assertStored($cover, false);
        $this->assertStored($replacement, true);
    }

    public function testRollbackKeepsFilesAndDailyMaintenanceOnlyDeletesOldUnusedUploads(): void
    {
        $owner = $this->user('creator');
        $old = $this->media($owner, 'creator-portfolio');
        $fresh = $this->media($owner, 'creator-portfolio');
        $used = $this->media($owner, 'creator-avatar');
        $owner->getCreator()->setAvatarMedia($used);
        $this->em->flush();
        $db = $this->em->getConnection();
        foreach ([$old, $used] as $media) $db->update('media', ['created_at' => '2000-01-01 00:00:00'], ['id' => $media->getId()]);
        $db->beginTransaction();
        self::assertSame([$old->getId()], self::getContainer()->get(UnusedMediaCleanup::class)->remove([$old->getId(), $used->getId()]));
        self::assertSame(0, $this->files->run());
        self::assertFileExists($this->storage->absolutePath($old->getStoragePath()));
        $db->rollBack();
        $this->assertStored($old, true);
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM stored_file_deletion'));
        self::getContainer()->get(JobExecutor::class)->execute('MediaMaintenanceTask', [], 'test');
        $this->assertStored($old, false);
        $this->assertStored($used, true);
        $this->assertStored($fresh, true);
        $this->client->loginUser($owner, 'main');
        $this->request('DELETE', '/api/media/'.$fresh->getId(), []);
        self::assertResponseIsSuccessful();
        $this->assertStored($fresh, false);
    }

    public function testAgencyPortfolioUploadsAndLogoReplacementsCleanUpSharedStudioReferences(): void
    {
        $owner = $this->user('company');
        $owner->getCompany()->setCompanyType('agency');
        $this->em->flush();
        $oldLogo = $this->media($owner, 'company-logo');
        $newLogo = $this->media($owner, 'company-logo');
        $cover = $this->media($owner, 'creator-portfolio');
        $extra = $this->media($owner, 'creator-portfolio');
        $this->client->loginUser($owner, 'main');
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = json_decode($this->client->getResponse()->getContent(), true)['csrfToken'];
        $this->client->request('POST', '/api/media?folder=creator-portfolio', [], [
            'file' => new UploadedFile($this->directory.'/source.png', 'source.png', null, null, true),
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($this->client->getResponse()->getContent(), true)['data']['id'];
        $main = self::getContainer()->get(EntityManagerInterface::class)->find(Media::class, $id);
        $body = ['name' => 'Agency', 'industries' => ['Travel'], 'companyType' => 'agency', 'logoMediaId' => $oldLogo->getId(),
            'portfolio' => [['type' => 'image', 'title' => 'Agency project', 'mediaId' => $id, 'coverMediaId' => $cover->getId(), 'media' => [['type' => 'image', 'mediaId' => $extra->getId()]], 'publicPermission' => true]]];
        $this->request('PUT', '/api/me/profile', $body);
        self::assertResponseIsSuccessful();
        foreach ([$oldLogo, $main, $cover, $extra] as $media) $this->assertStored($media, true);
        $this->request('PUT', '/api/me/profile', [...$body, 'logoMediaId' => $newLogo->getId(), 'portfolio' => []]);
        self::assertResponseIsSuccessful();
        foreach ([$oldLogo, $main, $cover, $extra] as $media) $this->assertStored($media, false);
        $this->assertStored($newLogo, true);
        $this->request('PUT', '/api/me/profile', [...$body, 'companyType' => 'brand', 'logoMediaId' => null, 'portfolio' => []]);
        self::assertResponseIsSuccessful();
        $this->assertStored($newLogo, false);
    }

    private function user(string $role): User
    {
        $user = new User($role.'@lifecycle.example.test', $role === 'creator' ? 'ROLE_CREATOR' : 'ROLE_COMPANY');
        $user->setPassword('unused');
        $user->setApproved(true);
        $user->setEmailVerified(true);
        $user->setPhone('+38761123456');
        $user->setCity('Sarajevo');
        $user->setCountryCode('BA');
        if ($role === 'creator') $user->setCreator(new Creator('lifecycle', 'Creator', 'Travel', 'Sarajevo', '', []));
        else $user->setCompany(new Company('lifecycle', 'Company', 'Travel'));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    private function media(User $owner, string $slug): Media
    {
        $folder = $this->em->getRepository(MediaFolder::class)->findOneBy(['slug' => $slug]);
        if (!$folder) {
            $folder = new MediaFolder($slug, $slug);
            $this->em->persist($folder);
        }
        $path = $this->directory.'/source.png';
        imagepng(imagecreatetruecolor(120, 80), $path);
        $stored = $this->storage->store(new UploadedFile($path, 'source.png', null, null, true), $folder, $owner);
        $media = new Media($folder, $owner, 'source.png', $stored['path'], $stored['mimeType'], $stored['fileSize']);
        $media->setDimensions($stored['width'], $stored['height']);
        $this->em->persist($media);
        $this->em->flush();
        return $media;
    }

    private function request(string $method, string $url, array $body): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = json_decode($this->client->getResponse()->getContent(), true)['csrfToken'];
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function assertStored(Media $media, bool $exists): void
    {
        self::assertSame($exists, (bool) $this->em->getConnection()->fetchOne('SELECT 1 FROM media WHERE id = ?', [$media->getId()]));
        self::assertSame($exists, is_file($this->storage->absolutePath($media->getStoragePath())));
        foreach ([96, 320, 480] as $width) {
            $path = $this->directory.'/media/thumbnails/v2/'.hash('sha256', $media->getStoragePath()).'/'.$width.'.webp';
            self::assertSame($exists, is_file($path));
        }
    }
}
