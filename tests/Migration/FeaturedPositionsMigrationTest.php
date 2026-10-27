<?php

namespace App\Tests\Migration;

use App\Entity\{Creator, HomepageSettings};
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FeaturedPositionsMigrationTest extends KernelTestCase
{
    public function testExistingProfilesSurviveAndCompaniesSliderDefaultsOff(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $creator = new Creator('existing-featured', 'Existing featured', 'Travel', 'Sarajevo', '', []);
        $creator->setFeatured(true);
        $em->persist($creator);
        $em->persist(new HomepageSettings());
        $em->flush();
        $db = $em->getConnection();
        require_once dirname(__DIR__, 2).'/migrations/Version20261027170000.php';
        $down = new \DoctrineMigrations\Version20261027170000($db, new NullLogger());
        $down->down(new Schema());
        foreach ($down->getSql() as $sql) $db->executeStatement($sql->getStatement());
        $up = new \DoctrineMigrations\Version20261027170000($db, new NullLogger());
        $up->up(new Schema());
        foreach ($up->getSql() as $sql) $db->executeStatement($sql->getStatement());
        $row = $db->fetchAssociative('SELECT featured, featured_position FROM creator WHERE id = ?', [$creator->getId()]);
        self::assertTrue($row['featured']);
        self::assertNull($row['featured_position']);
        self::assertFalse($db->fetchOne('SELECT companies_slider_enabled FROM homepage_settings'));
        foreach (['creator', 'company', 'campaign'] as $table) {
            self::assertArrayHasKey('idx_'.$table.'_featured_position', $db->createSchemaManager()->listTableIndexes($table));
        }
    }
}
