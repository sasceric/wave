<?php

namespace App\Tests\Migration;

use App\Entity\MarketplaceCategory;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MarketplaceAreasMigrationTest extends KernelTestCase
{
    public function testCyclingAndServiceAreasAreLocalizedAndPreserveExistingModeratorEdits(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        // Case-insensitive matching must preserve even a previously customized record.
        $custom = new MarketplaceCategory('cycling', ['bs' => 'Moje biciklističke ture', 'en' => 'Custom cycling'], 73);
        $custom->update($custom->getLabels(), 73, false);
        $em->persist($custom);
        $em->persist(new MarketplaceCategory('Food', ['bs' => 'Hrana'], 80));
        $em->flush();
        $db = $em->getConnection();
        require_once dirname(__DIR__, 2).'/migrations/Version20261026170000.php';
        for ($run = 0; $run < 2; ++$run) {
            $migration = new \DoctrineMigrations\Version20261026170000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
        self::assertSame(7, (int) $db->fetchOne('SELECT COUNT(*) FROM marketplace_category'));
        $em->clear();
        $custom = $em->getRepository(MarketplaceCategory::class)->findOneBy(['value' => 'cycling']);
        self::assertSame('Moje biciklističke ture', $custom->label('bs'));
        self::assertSame(73, $custom->getPosition());
        self::assertFalse($custom->isActive());
        foreach (['Repairs and services', 'Mountain biking', 'Electric scooters', 'Adventure', 'Extreme sports'] as $value) {
            $area = $em->getRepository(MarketplaceCategory::class)->findOneBy(['value' => $value]);
            self::assertNotNull($area);
            self::assertTrue($area->isActive());
            self::assertGreaterThan(80, $area->getPosition());
            foreach (['bs', 'hr', 'sr', 'cnr', 'sl', 'en', 'de'] as $locale) {
                self::assertNotEmpty($area->getLabels()[$locale]);
                self::assertSame($area->getLabels()[$locale], $area->label($locale));
            }
        }
        self::assertSame('Brdski biciklizam', $em->getRepository(MarketplaceCategory::class)->findOneBy(['value' => 'Mountain biking'])->label('bs'));
    }

    public function testCatalogExpansionPreservesEditsAndCanBeAppliedTwice(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $custom = new MarketplaceCategory('Food', ['bs' => 'Moja hrana', 'en' => 'Custom food'], 73);
        $custom->update($custom->getLabels(), 73, false);
        $em->persist($custom);
        $em->flush();
        $db = $em->getConnection();
        require_once dirname(__DIR__, 2).'/migrations/Version20261007020000.php';
        for ($run = 0; $run < 2; ++$run) {
            $migration = new \DoctrineMigrations\Version20261007020000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
        self::assertSame(48, (int) $db->fetchOne('SELECT COUNT(*) FROM marketplace_category'));
        $em->clear();
        $custom = $em->getRepository(MarketplaceCategory::class)->findOneBy(['value' => 'Food']);
        self::assertSame('Moja hrana', $custom->label('bs'));
        self::assertSame(73, $custom->getPosition());
        self::assertFalse($custom->isActive());
        $photography = $em->getRepository(MarketplaceCategory::class)->findOneBy(['value' => 'Photography']);
        self::assertSame(['bs', 'hr', 'sr', 'sl', 'en', 'cnr'], array_keys($photography->getLabels()));
        self::assertTrue($photography->isActive());
        self::assertSame('Fotografija', $photography->label('bs'));
    }
}
