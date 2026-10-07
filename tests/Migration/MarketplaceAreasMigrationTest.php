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
