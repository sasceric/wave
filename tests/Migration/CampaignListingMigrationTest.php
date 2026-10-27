<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261027090000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CampaignListingMigrationTest extends KernelTestCase
{
    public function testExistingAndNewCampaignsStayListedByDefault(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            $db->executeStatement('CREATE TEMP TABLE campaign (id INT PRIMARY KEY, status VARCHAR(20))');
            $db->executeStatement("INSERT INTO campaign VALUES (1, 'open'), (2, 'finished')");
            require_once dirname(__DIR__, 2).'/migrations/Version20261027090000.php';
            $migration = new Version20261027090000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
            $db->executeStatement("INSERT INTO campaign (id, status) VALUES (3, 'open')");
            self::assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM campaign WHERE listed = TRUE'));
            self::assertSame('finished', $db->fetchOne('SELECT status FROM campaign WHERE id = 2'));
            $db->executeStatement('UPDATE campaign SET listed = FALSE WHERE id = 1');
            self::assertSame('open', $db->fetchOne('SELECT status FROM campaign WHERE id = 1'));
        } finally {
            $db->rollBack();
        }
    }
}
