<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreditAssignmentMigrationTest extends KernelTestCase
{
    public function testAssignmentAndReviewNotificationMigrationsOnBothDatabasePlatforms(): void
    {
        self::bootKernel();
        $postgres = self::getContainer()->get('doctrine')->getConnection();
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__, 2).'/migrations/Version20261013110000.php';
        require_once dirname(__DIR__, 2).'/migrations/Version20261013120000.php';
        foreach ([$sqlite, $postgres] as $connection) {
            if ($connection === $sqlite) { $connection->executeStatement('PRAGMA foreign_keys = ON'); }
            $connection->beginTransaction();
            try {
                if ($connection === $postgres) {
                    $connection->executeStatement('CREATE SCHEMA credit_assignment_migration_test');
                    $connection->executeStatement('SET LOCAL search_path TO credit_assignment_migration_test');
                } else {
                    $connection->executeStatement('PRAGMA foreign_keys = ON');
                }
                $connection->executeStatement('CREATE TABLE wave_user (id INT PRIMARY KEY)');
                $connection->executeStatement('CREATE TABLE credit_voucher (id VARCHAR(32) PRIMARY KEY, created_at TIMESTAMP NOT NULL)');
                $connection->executeStatement('CREATE TABLE campaign_application (id INT PRIMARY KEY)');
                $connection->executeStatement('CREATE TABLE wave_notification (id INT PRIMARY KEY)');
                foreach ([\DoctrineMigrations\Version20261013110000::class, \DoctrineMigrations\Version20261013120000::class] as $class) {
                    $migration = new $class($connection, new NullLogger());
                    $migration->up($connection->createSchemaManager()->introspectSchema());
                    foreach ($migration->getSql() as $query) { $connection->executeStatement($query->getStatement()); }
                }
                $connection->executeStatement('INSERT INTO wave_user (id) VALUES (1)');
                $connection->executeStatement('INSERT INTO campaign_application (id) VALUES (2)');
                $connection->executeStatement("INSERT INTO credit_voucher (id, created_at, assigned_to_id) VALUES ('test', CURRENT_TIMESTAMP, 1)");
                $connection->executeStatement('INSERT INTO wave_notification (id, review_application_id) VALUES (1, 2)');
                $connection->executeStatement('DELETE FROM wave_user WHERE id = 1');
                self::assertNull($connection->fetchOne("SELECT assigned_to_id FROM credit_voucher WHERE id = 'test'"));
                $connection->executeStatement('DELETE FROM campaign_application WHERE id = 2');
                self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM wave_notification'));
                foreach ([\DoctrineMigrations\Version20261013120000::class, \DoctrineMigrations\Version20261013110000::class] as $class) {
                    $migration = new $class($connection, new NullLogger());
                    $migration->down($connection->createSchemaManager()->introspectSchema());
                    foreach ($migration->getSql() as $query) { $connection->executeStatement($query->getStatement()); }
                }
                self::assertCount(2, $connection->createSchemaManager()->listTableColumns('credit_voucher'));
                self::assertCount(1, $connection->createSchemaManager()->listTableColumns('wave_notification'));
            } finally { $connection->rollBack(); }
        }
        $sqlite->close();
    }
}
