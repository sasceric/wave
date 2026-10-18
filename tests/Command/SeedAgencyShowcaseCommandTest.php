<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Api\CompanyResource;
use App\Demo\PresentationDemoSeeder;
use App\Entity\{Application as CampaignApplication, Campaign, CampaignReview, Creator, Media, User};
use App\Reviews\CollaborationFeedback;
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

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
}
