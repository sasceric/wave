<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VoluntaryCampaignMigrationTest extends KernelTestCase
{
    public function testExistingCampaignsRemainPaidWithoutChangingTheirBudget(): void
    {
        self::bootKernel();
        $configured = self::getContainer()->get('doctrine')->getConnection();
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connections = [$sqlite];
        if ($configured->getDatabasePlatform() instanceof PostgreSQLPlatform) $connections[] = $configured;
        require_once dirname(__DIR__, 2).'/migrations/Version20261022150000.php';
        foreach ($connections as $db) {
            $db->beginTransaction();
            try {
                if ($db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                    $db->executeStatement('CREATE SCHEMA voluntary_campaign_migration_test');
                    $db->executeStatement('SET LOCAL search_path TO voluntary_campaign_migration_test');
                }
                $db->executeStatement('CREATE TABLE campaign (id INTEGER PRIMARY KEY, budget_min INTEGER NOT NULL, budget_max INTEGER NOT NULL)');
                $db->executeStatement('INSERT INTO campaign VALUES (1, 300, 800)');
                $migration = new \DoctrineMigrations\Version20261022150000($db, new NullLogger());
                $migration->up($db->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
                self::assertSame('paid', $db->fetchOne('SELECT compensation_type FROM campaign WHERE id = 1'));
                self::assertSame(300, (int) $db->fetchOne('SELECT budget_min FROM campaign WHERE id = 1'));
                self::assertSame(800, (int) $db->fetchOne('SELECT budget_max FROM campaign WHERE id = 1'));
                $db->executeStatement("INSERT INTO campaign VALUES (2, 0, 0, 'voluntary')");
                self::assertSame('voluntary', $db->fetchOne('SELECT compensation_type FROM campaign WHERE id = 2'));
            } finally { $db->rollBack(); }
        }
        $sqlite->close();
    }
}
