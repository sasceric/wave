<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Api\CreatorResource;
use App\Demo\PresentationDemoSeeder;
use App\Entity\Application as CampaignApplication;
use App\Entity\Campaign;
use App\Entity\CampaignMessage;
use App\Entity\CampaignReview;
use App\Entity\Creator;
use App\Entity\Media;
use App\Entity\User;
use App\Reviews\CollaborationFeedback;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SeedPresentationDemoCommandTest extends KernelTestCase
{
    private array $paths = [];
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }
    protected function tearDown(): void
    {
        $storage = self::getContainer()->get(MediaStorage::class);
        foreach ($this->paths as $path) $storage->removePath($path);
        parent::tearDown();
    }
    public function testUpsertOwnsMediaKeepsVisibilityPasswordsAndUrlsAndNeverDuplicatesHistory(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $seeder = self::getContainer()->get(PresentationDemoSeeder::class);
        $result = $seeder->seed('ShowcaseTest123!');
        $this->paths = array_map(static fn (Media $media): string => $media->getStoragePath(), $em->getRepository(Media::class)->findAll());
        self::assertCount(3, $result);
        self::assertSame(3, $em->getRepository(User::class)->count([]));
        self::assertSame(6, $em->getRepository(Campaign::class)->count([]));
        self::assertSame(4, $em->getRepository(CampaignApplication::class)->count([]));
        self::assertSame(8, $em->getRepository(CampaignReview::class)->count([]));
        self::assertSame(12, $em->getRepository(CampaignMessage::class)->count([]));
        $user = $em->getRepository(User::class)->findOneBy(['presentationDemoKey' => 'wave-showcase-amina']);
        $creator = $user->getCreator();
        self::assertTrue($user->hasCompleteProfile());
        self::assertFalse($user->hasRole('ROLE_ADMIN'));
        self::assertCount(4, $creator->getPortfolio());
        self::assertCount(3, $creator->getPackages());
        self::assertCount(3, $creator->getFaqs());
        $resource = CreatorResource::fromEntity($creator, 'bs');
        self::assertTrue($resource['isPresentationDemo']);
        self::assertNotNull($resource['portfolio'][0]['url']);
        $summary = self::getContainer()->get(CollaborationFeedback::class)->summary($user);
        self::assertSame(4, $summary['reviewCount']);
        self::assertEqualsWithDelta(4.8, $summary['average'], 0.01);
        foreach ($em->getRepository(User::class)->findAll() as $demo) {
            self::assertTrue($demo->isPresentationDemo());
            self::assertTrue($demo->hasCompleteProfile());
            self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($demo, 'ShowcaseTest123!'));
        }
        foreach ($em->getRepository(Media::class)->findAll() as $media) {
            self::assertLessThanOrEqual(600, $media->getWidth());
            self::assertTrue($media->getOwner()->isPresentationDemo());
            self::assertFileExists(self::getContainer()->get(MediaStorage::class)->absolutePath($media->getStoragePath()));
        }
        $countMedia = count($this->paths);
        $hash = $user->getPassword();
        $user->setHideMyAccount(true);
        $creator->changePublicSlug('amina-demo-promijenjeno');
        $campaign = $em->getRepository(Campaign::class)->findOneBy(['presentationDemoKey' => 'wave-showcase-dn-rutina']);
        $campaign->changePublicSlug('demo-promijenjena-kampanja');
        $em->flush();
        $again = $seeder->seed('DifferentTest123!');
        self::assertTrue($again['amina']['hidden']);
        self::assertSame('/kreatori/amina-demo-promijenjeno', $again['amina']['path']);
        self::assertSame($hash, $user->getPassword());
        self::assertSame(6, $em->getRepository(Campaign::class)->count([]));
        self::assertSame(8, $em->getRepository(CampaignReview::class)->count([]));
        self::assertSame(12, $em->getRepository(CampaignMessage::class)->count([]));
        self::assertSame($countMedia, $em->getRepository(Media::class)->count([]));
        $seeder->seed('ResetShowcase123!', true);
        self::assertTrue($user->isHideMyAccount());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'ResetShowcase123!'));
    }
    public function testDryRunDoesNotWriteAndExistingRealEmailIsNeverClaimed(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $command = new CommandTester((new Application(self::$kernel))->find('app:seed-presentation-demo'));
        self::assertSame(0, $command->execute(['--dry-run' => true]));
        self::assertSame(0, $em->getRepository(User::class)->count([]));
        self::assertSame(0, $em->getRepository(Media::class)->count([]));
        $real = new User('amina.kovac@wave.ba', 'ROLE_CREATOR');
        $real->setPassword('untouched-hash');
        $real->setCreator(new Creator('real-amina', 'Real owner', 'Beauty', 'Sarajevo', '', []));
        $em->persist($real); $em->flush();
        self::assertSame(1, $command->execute(['--dry-run' => true]));
        self::assertStringContainsString('Refusing to overwrite', $command->getDisplay());
        self::assertSame('untouched-hash', $real->getPassword());
        self::assertFalse($real->isPresentationDemo());
        self::assertSame(0, $em->getRepository(Campaign::class)->count([]));
    }
}
