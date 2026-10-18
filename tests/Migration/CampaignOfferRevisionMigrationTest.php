<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261018200000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CampaignOfferRevisionMigrationTest extends KernelTestCase
{
    public function testBackfillPreservesLegacyOffersAndAllowsSeparateRevisions(): void
    {
        self::bootKernel();
        $db = static::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            // Transaction-local tables shadow application tables without altering account data.
            $db->executeStatement('CREATE TEMP TABLE campaign_application (id INT PRIMARY KEY, status VARCHAR(20) NOT NULL)');
            $db->executeStatement('CREATE TEMP TABLE campaign_offer (id INT PRIMARY KEY, application_id INT NOT NULL REFERENCES campaign_application(id) ON DELETE CASCADE, amount INT NOT NULL, status VARCHAR(20) NOT NULL)');
            $db->executeStatement('CREATE UNIQUE INDEX UNIQ_67D3E3513E030ACD ON campaign_offer(application_id)');
            $db->executeStatement("INSERT INTO campaign_application VALUES (1, 'accepted'), (2, 'offer_declined'), (3, 'pending')");
            $db->executeStatement("INSERT INTO campaign_offer VALUES (10, 1, 500, 'accepted'), (20, 2, 300, 'rejected')");
            require_once dirname(__DIR__, 2).'/migrations/Version20261018200000.php';
            $migration = new Version20261018200000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());

            self::assertSame([
                ['id' => 1, 'status' => 'accepted', 'offer_id' => 10],
                ['id' => 2, 'status' => 'shortlisted', 'offer_id' => 20],
                ['id' => 3, 'status' => 'pending', 'offer_id' => null],
            ], $db->fetchAllAssociative('SELECT * FROM campaign_application ORDER BY id'));
            self::assertSame(500, $db->fetchOne('SELECT amount FROM campaign_offer WHERE id = 10'));
            self::assertNull($db->fetchOne('SELECT due_date FROM campaign_offer WHERE id = 10'));
            $db->executeStatement("INSERT INTO campaign_offer VALUES (21, 2, 400, 'pending', '2099-01-20')");
            $db->executeStatement('UPDATE campaign_application SET offer_id = 21 WHERE id = 2');
            self::assertSame(2, $db->fetchOne('SELECT COUNT(*) FROM campaign_offer WHERE application_id = 2'));
            self::assertSame('rejected', $db->fetchOne('SELECT status FROM campaign_offer WHERE id = 20'));
            self::assertSame('2099-01-20', $db->fetchOne('SELECT due_date FROM campaign_offer WHERE id = 21'));
            // Removing an application still removes every historical offer, including the current one.
            $db->executeStatement('DELETE FROM campaign_application WHERE id = 2');
            self::assertSame(0, $db->fetchOne('SELECT COUNT(*) FROM campaign_offer WHERE application_id = 2'));
        } finally {
            $db->rollBack();
        }
    }
}
