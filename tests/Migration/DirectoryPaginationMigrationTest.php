<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DirectoryPaginationMigrationTest extends KernelTestCase
{
    public function testDirectoryIndexesCanBeRemovedAndRecreated(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        $connection = $entityManager->getConnection();
        require_once dirname(__DIR__, 2).'/migrations/Version20261006220000.php';
        $migration = new \DoctrineMigrations\Version20261006220000($connection, new NullLogger());
        $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertArrayNotHasKey('idx_creator_directory_name', $connection->createSchemaManager()->listTableIndexes('creator'));
        $migration = new \DoctrineMigrations\Version20261006220000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertArrayHasKey('idx_creator_directory_name', $connection->createSchemaManager()->listTableIndexes('creator'));
        $indexes = $connection->createSchemaManager()->listTableIndexes('campaign');
        self::assertArrayHasKey('idx_campaign_directory_order', $indexes);
        self::assertArrayHasKey('idx_campaign_company_available', $indexes);
    }
}
