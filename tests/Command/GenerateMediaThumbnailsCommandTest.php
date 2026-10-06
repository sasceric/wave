<?php

namespace App\Tests\Command;

use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\User;
use App\Service\MediaStorage;
use App\Service\MediaThumbnails;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class GenerateMediaThumbnailsCommandTest extends KernelTestCase
{
    private array $paths = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $storage = self::getContainer()->get(MediaStorage::class);
        $thumbnails = self::getContainer()->get(MediaThumbnails::class);
        foreach ($this->paths as $path) {
            $thumbnails->remove($path);
            (new Filesystem())->remove($storage->absolutePath($path));
        }
        parent::tearDown();
    }

    public function testLegacyImageIndexingIsBoundedResumableAndIdempotent(): void
    {
        $ids = $this->createImages(5);
        $storage = self::getContainer()->get(MediaStorage::class);
        $source = $storage->absolutePath($this->paths[0]);
        $hash = hash_file('sha256', $source);
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--batch-size' => '2', '--limit' => '3']));
        self::assertStringContainsString('Processed 3; failed 0;', $tester->getDisplay());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($ids as $index => $id) {
            $media = $entityManager->find(Media::class, $id);
            self::assertSame($index < 3 ? 1200 : null, $media->getWidth());
            self::assertSame($index < 3 ? 800 : null, $media->getHeight());
        }
        self::assertSame(0, $tester->execute(['--batch-size' => '1', '--after-id' => (string) $ids[2]]));
        self::assertStringContainsString('Processed 2; failed 0;', $tester->getDisplay());
        self::assertSame($hash, hash_file('sha256', $source));
        $entityManager->clear();
        $media = $entityManager->find(Media::class, $ids[0]);
        $path = self::getContainer()->get(MediaThumbnails::class)->path($media, 320);
        touch($path, 946684800);
        self::assertSame(0, $tester->execute([]));
        clearstatcache(true, $path);
        self::assertSame(946684800, filemtime($path));
        self::assertSame(0, $tester->execute(['--force' => true, '--limit' => '1']));
        clearstatcache(true, $path);
        self::assertGreaterThan(946684800, filemtime($path));
    }

    public function testMissingSourcesAreReportedAndDoNotStopLaterImages(): void
    {
        $ids = $this->createImages(2);
        $storage = self::getContainer()->get(MediaStorage::class);
        unlink($storage->absolutePath($this->paths[0]));
        $tester = $this->tester();
        self::assertSame(1, $tester->execute(['--batch-size' => '1']));
        self::assertStringContainsString('Processed 2; failed 1;', $tester->getDisplay());
        self::assertStringContainsString('Media '.$ids[0].':', $tester->getDisplay());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($entityManager->find(Media::class, $ids[0])->getWidth());
        self::assertSame(1200, $entityManager->find(Media::class, $ids[1])->getWidth());
    }

    public function testInvalidBatchOptionsAreRejected(): void
    {
        $tester = $this->tester();
        self::assertSame(2, $tester->execute(['--batch-size' => '0']));
        self::assertSame(2, $tester->execute(['--after-id' => '-1']));
        self::assertSame(2, $tester->execute(['--limit' => 'invalid']));
    }

    private function createImages(int $count): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('legacy-images@example.test', 'ROLE_CREATOR');
        $owner->setPassword('unused-test-hash');
        $folder = new MediaFolder('creator-avatar', 'Avatars');
        $entityManager->persist($owner);
        $entityManager->persist($folder);
        $storage = self::getContainer()->get(MediaStorage::class);
        $images = [];
        for ($index = 0; $index < $count; ++$index) {
            $path = 'legacy-test-'.bin2hex(random_bytes(8)).'.png';
            imagepng(imagecreatetruecolor(1200, 800), $storage->absolutePath($path));
            $this->paths[] = $path;
            $media = new Media($folder, $owner, 'legacy.png', $path, 'image/png', filesize($storage->absolutePath($path)));
            $entityManager->persist($media);
            $images[] = $media;
        }
        $entityManager->flush();

        return array_map(static fn (Media $media): int => $media->getId(), $images);
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:media:generate-thumbnails'));
    }
}
