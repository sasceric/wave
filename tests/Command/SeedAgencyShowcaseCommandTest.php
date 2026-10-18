<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Api\CompanyResource;
use App\Demo\PresentationDemoSeeder;
use App\Entity\{Application as CampaignApplication, Campaign, CampaignReview, Creator, Media, MediaFolder, User};
use App\Reviews\CollaborationFeedback;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SeedAgencyShowcaseCommandTest extends KernelTestCase
{
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
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $storage = self::getContainer()->get(MediaStorage::class);
        foreach ($em->getRepository(Media::class)->findAll() as $media) $storage->removePath($media->getStoragePath());
        parent::tearDown();
    }

    public function testExactAgencyContentBothReviewRolesAndSafeRepeat(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $seeder = self::getContainer()->get(PresentationDemoSeeder::class);
        $seeder->seed('OldDemoPassword123!');
        $existing = $em->getRepository(User::class)->findOneBy(['presentationDemoKey' => 'wave-showcase-amina']);
        $oldPassword = $existing->getPassword();
        $existing->setHideMyAccount(true);
        $seeder->seedAgencyShowcase('LumaTestPassword123!');
        $agency = $em->getRepository(User::class)->findOneBy(['presentationDemoKey' => 'wave-agency-luma']);
        self::assertSame('Studio Luma', $agency->getCompany()->getName());
        self::assertTrue($agency->isPresentationDemo());
        self::assertTrue($agency->hasCompleteProfile());
        self::assertTrue($agency->canOfferServices());
        self::assertTrue($agency->getCreator()->isAgencyProfile());
        self::assertFalse($agency->hasRole('ROLE_CREATOR'));
        $profile = CompanyResource::fromEntity($agency->getCompany(), 'bs');
        self::assertCount(3, $profile['services']);
        self::assertCount(3, $profile['portfolio']);
        self::assertCount(2, $profile['packages']);
        self::assertCount(3, $profile['faqs']);
        self::assertSame([650, 1200], array_column($profile['packages'], 'price'));
        self::assertSame(['bs', 'en'], $profile['presentation']['languages']);
        self::assertSame('Njega bez komplikacija', $profile['portfolio'][0]['title']);
        self::assertSame('sample', $profile['portfolio'][2]['source']);
        self::assertSame(1920, $agency->getCompany()->getCoverMedia()->getWidth());
        $feedback = self::getContainer()->get(CollaborationFeedback::class);
        self::assertSame(['average' => 4.8, 'reviewCount' => 6, 'completedCampaigns' => 8], $feedback->summary($agency));
        self::assertSame(['average' => 4.8, 'reviewCount' => 4, 'completedCampaigns' => 5], $feedback->summary($agency, 'provider'));
        self::assertSame(['average' => 5.0, 'reviewCount' => 2, 'completedCampaigns' => 3], $feedback->summary($agency, 'client'));
        $open = $em->getRepository(Campaign::class)->findOneBy(['presentationDemoKey' => 'wave-agency-luma-open-kafa']);
        self::assertSame(5, $open->getApplicationCount());
        self::assertSame($oldPassword, $existing->getPassword());
        self::assertTrue($existing->isHideMyAccount());
        $counts = array_map(fn (string $class): int => $em->getRepository($class)->count([]), [User::class, Campaign::class, CampaignApplication::class, CampaignReview::class, Media::class]);
        $agency->setHideMyAccount(true);
        $agency->getCompany()->changePublicSlug('luma-custom-url');
        $hash = $agency->getPassword();
        $em->flush();
        $again = $seeder->seedAgencyShowcase('NeverResetPasswords123!');
        self::assertTrue($again['hidden']);
        self::assertSame('/kompanije/luma-custom-url', $again['path']);
        self::assertSame($hash, $agency->getPassword());
        self::assertSame($counts, array_map(fn (string $class): int => $em->getRepository($class)->count([]), [User::class, Campaign::class, CampaignApplication::class, CampaignReview::class, Media::class]));
        self::assertSame($em->getRepository(CampaignApplication::class)->count([]), (int) $em->getConnection()->fetchOne('SELECT SUM(application_count) FROM campaign'));
    }

    public function testDryRunAndRealAccountCollisionCannotWrite(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $command = new CommandTester((new Application(self::$kernel))->find('app:seed-agency-showcase'));
        self::assertSame(0, $command->execute(['--dry-run' => true]));
        self::assertSame(0, $em->getRepository(User::class)->count([]));
        $real = new User('amina.kovac@wave.ba', 'ROLE_CREATOR');
        $real->setPassword('real-password-untouched');
        $real->setCreator(new Creator('real-amina', 'Real account', 'Beauty', 'Sarajevo', '', []));
        $em->persist($real);
        $em->flush();
        self::assertSame(1, $command->execute(['--dry-run' => true]));
        self::assertStringContainsString('Refusing to overwrite', $command->getDisplay());
        self::assertSame(1, $em->getRepository(User::class)->count([]));
        self::assertSame(0, $em->getRepository(Media::class)->count([]));
        self::assertSame('real-password-untouched', $real->getPassword());
    }

    public function testCoverOnlyRefreshPreservesSavedContentAndCleansOnlyUnusedOldMedia(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $seeder = self::getContainer()->get(PresentationDemoSeeder::class);
        $seeder->seedAgencyShowcase('LumaCoverTest123!');
        $owner = $em->getRepository(User::class)->findOneBy(['presentationDemoKey' => 'wave-agency-luma']);
        $company = $owner->getCompany();
        $company->updateProfile('Custom Luma name', 'Marketing', null, '<p>Preserve this edited description.</p>');
        $owner->setHideMyAccount(true);
        $password = $owner->getPassword();
        $portfolio = $owner->getCreator()->getPortfolio();
        $storage = self::getContainer()->get(MediaStorage::class);
        $folder = $em->getRepository(MediaFolder::class)->findOneBy(['slug' => 'company-cover']);
        $stored = $storage->store(new UploadedFile(dirname(__DIR__, 2).'/resources/presentation-demo/luma-cover.webp', 'old-cover.webp', 'image/webp', null, true), $folder, $owner);
        $old = new Media($folder, $owner, 'old-cover.webp', $stored['path'], $stored['mimeType'], $stored['fileSize']);
        $old->setDimensions($stored['width'], $stored['height']);
        $em->persist($old); $company->setCoverMedia($old); $em->flush();
        $oldPath = $storage->absolutePath($old->getStoragePath());
        $oldId = $old->getId();
        $thumb = self::getContainer()->get(\App\Service\MediaThumbnails::class)->path($old, 480);
        self::assertFileExists($thumb);
        $seeder->refreshAgencyShowcaseCover(true);
        self::assertSame($oldId, $company->getCoverMedia()->getId()); self::assertFileExists($oldPath);
        $seeder->refreshAgencyShowcaseCover();
        self::assertNotSame($oldId, $company->getCoverMedia()->getId());
        self::assertSame(1920, $company->getCoverMedia()->getWidth()); self::assertSame(640, $company->getCoverMedia()->getHeight());
        self::assertSame(160, CompanyResource::fromEntity($company, 'bs')['coverImage']['height']);
        self::assertSame('Custom Luma name', $company->getName()); self::assertSame('<p>Preserve this edited description.</p>', $company->getAbout());
        self::assertSame($portfolio, $owner->getCreator()->getPortfolio()); self::assertSame($password, $owner->getPassword()); self::assertTrue($owner->isHideMyAccount());
        self::assertFalse($em->getConnection()->fetchOne('SELECT id FROM media WHERE id = ?', [$oldId]));
        self::assertFileDoesNotExist($oldPath); self::assertFileDoesNotExist($thumb);
        $id = $company->getCoverMedia()->getId(); $count = $em->getRepository(Media::class)->count([]);
        $seeder->refreshAgencyShowcaseCover();
        self::assertSame($id, $company->getCoverMedia()->getId()); self::assertSame($count, $em->getRepository(Media::class)->count([]));
    }
}
