<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261018230000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CampaignWorkMigrationTest extends KernelTestCase
{
    public function testFinishedWorkKeepsItsOriginalCompletionAndReviewWindow(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            $db->executeStatement('CREATE TEMP TABLE campaign (id INT PRIMARY KEY, status VARCHAR(20), finished_at TIMESTAMP(0) WITHOUT TIME ZONE)');
            $db->executeStatement('CREATE TEMP TABLE campaign_application (id INT PRIMARY KEY, campaign_id INT)');
            $db->executeStatement('CREATE TEMP TABLE campaign_offer (id INT PRIMARY KEY, application_id INT, status VARCHAR(20))');
            $db->executeStatement("INSERT INTO campaign VALUES (1, 'finished', '2026-09-30 12:00:00'), (2, 'closed', NULL), (3, 'open', NULL)");
            $db->executeStatement('INSERT INTO campaign_application VALUES (1, 1), (2, 1), (3, 2), (4, 3)');
            $db->executeStatement("INSERT INTO campaign_offer VALUES (1, 1, 'accepted'), (2, 2, 'rejected'), (3, 3, 'accepted'), (4, 4, 'accepted')");
            require_once dirname(__DIR__, 2).'/migrations/Version20261018230000.php';
            $migration = new Version20261018230000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
            self::assertSame(['completed', 'in_progress', 'in_progress', 'in_progress'], $db->fetchFirstColumn('SELECT work_status FROM campaign_offer ORDER BY id'));
            self::assertSame('2026-09-30 12:00:00', $db->fetchOne('SELECT work_completed_at FROM campaign_offer WHERE id = 1'));
            self::assertSame(3, $db->fetchOne('SELECT COUNT(*) FROM campaign_offer WHERE work_completed_at IS NULL'));
            self::assertSame(4, $db->fetchOne('SELECT COUNT(*) FROM campaign_offer WHERE work_changes_reason IS NULL AND work_submitted_at IS NULL'));
        } finally {
            $db->rollBack();
        }
    }
}
