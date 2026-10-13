<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MarketplaceSafetyMigrationTest extends KernelTestCase
{
    public function testCompanyTypeMigrationPreservesExistingCompaniesOnBothPlatforms(): void
    {
        self::bootKernel();
        $postgres = self::getContainer()->get('doctrine')->getConnection();
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__, 2).'/migrations/Version20261013170000.php';
        foreach ([$postgres, $sqlite] as $db) {
            $db->beginTransaction();
            try {
                if ($db === $postgres) { $db->executeStatement('CREATE SCHEMA company_type_migration_test'); $db->executeStatement('SET LOCAL search_path TO company_type_migration_test'); }
                $db->executeStatement('CREATE TABLE company (id INT PRIMARY KEY, name VARCHAR(120) NOT NULL)');
                $db->executeStatement("INSERT INTO company VALUES (1, 'Existing company')");
                $migration = new \DoctrineMigrations\Version20261013170000($db, new NullLogger());
                $migration->up($db->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
                self::assertSame('brand', $db->fetchOne('SELECT company_type FROM company WHERE id = 1'));
                $db->executeStatement("INSERT INTO company (id, name) VALUES (2, 'New company')");
                self::assertSame('brand', $db->fetchOne('SELECT company_type FROM company WHERE id = 2'));
                $db->executeStatement("UPDATE company SET company_type = 'agency' WHERE id = 1");
                $migration = new \DoctrineMigrations\Version20261013170000($db, new NullLogger());
                $migration->down($db->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
                self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM company'));
            } finally { $db->rollBack(); }
        }
        $sqlite->close();
    }

    public function testCampaignTimelineMigrationPreservesExistingMessagesOnBothPlatforms(): void
    {
        self::bootKernel();
        $postgres = self::getContainer()->get('doctrine')->getConnection();
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__, 2).'/migrations/Version20261013150000.php';
        foreach ([$postgres, $sqlite] as $db) {
            $db->beginTransaction();
            try {
                if ($db === $postgres) { $db->executeStatement('CREATE SCHEMA hiring_migration_test'); $db->executeStatement('SET LOCAL search_path TO hiring_migration_test'); }
                $db->executeStatement('CREATE TABLE campaign_message (id INT PRIMARY KEY, body TEXT NOT NULL)');
                $db->executeStatement("INSERT INTO campaign_message VALUES (1, 'Keep existing history')");
                $migration = new \DoctrineMigrations\Version20261013150000($db, new NullLogger());
                $migration->up($db->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
                self::assertNull($db->fetchOne('SELECT event FROM campaign_message WHERE id = 1'));
                $db->executeStatement('UPDATE campaign_message SET event = ? WHERE id = 1', [json_encode(['type' => 'hired', 'amount' => 500])]);
                self::assertSame(500, json_decode($db->fetchOne('SELECT event FROM campaign_message WHERE id = 1'), true)['amount']);
                $migration = new \DoctrineMigrations\Version20261013150000($db, new NullLogger());
                $migration->down($db->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
                self::assertSame('Keep existing history', $db->fetchOne('SELECT body FROM campaign_message WHERE id = 1'));
            } finally { $db->rollBack(); }
        }
        $sqlite->close();
    }

    public function testReportingAndBlockSchemaWorksOnPostgreSqlAndSqlite(): void
    {
        self::bootKernel();
        $postgres = self::getContainer()->get('doctrine')->getConnection();
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__, 2).'/migrations/Version20261013130000.php';
        foreach ([$postgres, $sqlite] as $db) {
            if ($db === $sqlite) { $db->executeStatement('PRAGMA foreign_keys = ON'); }
            $db->beginTransaction();
            try {
                if ($db === $postgres) { $db->executeStatement('CREATE SCHEMA safety_migration_test'); $db->executeStatement('SET LOCAL search_path TO safety_migration_test'); }
                $db->executeStatement('CREATE TABLE wave_user (id INT PRIMARY KEY)');
                $db->executeStatement('CREATE TABLE media (id INT PRIMARY KEY)');
                $db->executeStatement('INSERT INTO wave_user VALUES (1), (2)');
                $migration = new \DoctrineMigrations\Version20261013130000($db, new NullLogger());
                $migration->up($db->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) { $db->executeStatement($query->getStatement()); }
                self::assertTrue($db->createSchemaManager()->listTableIndexes('user_block')['uniq_active_user_block']->isUnique());
                $db->executeStatement("INSERT INTO user_block (id, blocker_id, blocked_id, created_at) VALUES ('block', 1, 2, CURRENT_TIMESTAMP)");
                $db->executeStatement("INSERT INTO marketplace_report (id, reporter_id, target_user_id, target_kind, target_slug, target_name, category, reason, description, status, response, version, created_at, updated_at) VALUES ('report',1,2,'company','demo','Demo','spam','messages','','received','',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
                $db->executeStatement("INSERT INTO report_notice (id,recipient_id,report_id,version,status,message,created_at) VALUES ('notice',1,'report',2,'processing','',CURRENT_TIMESTAMP)");
                $db->executeStatement('DELETE FROM wave_user WHERE id = 1');
                self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM user_block'));
                self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM report_notice'));
                $migration = new \DoctrineMigrations\Version20261013130000($db, new NullLogger());
                $migration->down($db->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) { $db->executeStatement($query->getStatement()); }
                self::assertFalse($db->createSchemaManager()->tablesExist(['marketplace_report']));
            } finally { $db->rollBack(); }
        }
        $sqlite->close();
    }
}
