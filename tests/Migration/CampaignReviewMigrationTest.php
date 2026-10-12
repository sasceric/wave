<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CampaignReviewMigrationTest extends KernelTestCase
{
    public function testSqliteAndPostgreSqlCompletionBackfillAndReviewConstraints(): void
    {
        self::bootKernel();
        $postgres = self::getContainer()->get('doctrine')->getConnection();
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__, 2).'/migrations/Version20261013100000.php';
        foreach ([$sqlite, $postgres] as $connection) {
            $pg = $connection === $postgres;
            $connection->beginTransaction();
            try {
                if ($pg) {
                    $connection->executeStatement('CREATE SCHEMA feedback_migration_test');
                    $connection->executeStatement('SET LOCAL search_path TO feedback_migration_test');
                } else {
                    $connection->executeStatement('PRAGMA foreign_keys = ON');
                }
                $connection->executeStatement('CREATE TABLE campaign (id INT PRIMARY KEY, status VARCHAR(20) NOT NULL)');
                $connection->executeStatement('CREATE TABLE wave_user (id INT PRIMARY KEY)');
                $connection->executeStatement('CREATE TABLE campaign_application (id INT PRIMARY KEY)');
                $connection->executeStatement("INSERT INTO campaign (id, status) VALUES (1, 'finished'), (2, 'open')");
                $migration = new \DoctrineMigrations\Version20261013100000($connection, new NullLogger());
                $migration->up($connection->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) { $connection->executeStatement($query->getStatement()); }
                self::assertNotNull($connection->fetchOne('SELECT finished_at FROM campaign WHERE id = 1'));
                self::assertNull($connection->fetchOne('SELECT finished_at FROM campaign WHERE id = 2'));
                self::assertTrue($connection->createSchemaManager()->listTableIndexes('campaign_review')['uniq_review_application_author']->isUnique());
                $migration = new \DoctrineMigrations\Version20261013100000($connection, new NullLogger());
                $migration->down($connection->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) { $connection->executeStatement($query->getStatement()); }
                self::assertFalse($connection->createSchemaManager()->tablesExist(['campaign_review']));
            } finally {
                $connection->rollBack();
            }
        }
        $sqlite->close();
    }
}
