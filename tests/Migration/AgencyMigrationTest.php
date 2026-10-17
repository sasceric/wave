<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261017120000;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AgencyMigrationTest extends KernelTestCase
{
    public static function databases(): iterable
    {
        yield 'historical SQLite' => [true];
        yield 'configured test database' => [false];
    }

    #[DataProvider('databases')]
    public function testAdditiveMigrationPreservesExistingPortfolioAndApplications(bool $sqlite): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261017120000.php';
        if ($sqlite) {
            $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        } else {
            self::bootKernel();
            $em = self::getContainer()->get(EntityManagerInterface::class);
            (new SchemaTool($em))->dropDatabase();
            $db = $em->getConnection();
        }
        try {
            $db->executeStatement('CREATE TABLE creator (id INT PRIMARY KEY, portfolio TEXT NOT NULL)');
            $db->executeStatement('CREATE TABLE campaign_application (id INT PRIMARY KEY, message TEXT NOT NULL)');
            $db->insert('creator', ['id' => 1, 'portfolio' => '[{"title":"Existing project"}]']);
            $db->insert('campaign_application', ['id' => 1, 'message' => 'An existing application']);
            $migration = new Version20261017120000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
            self::assertSame(0, (int) $db->fetchOne('SELECT agency_profile FROM creator WHERE id = 1'));
            self::assertSame('[{"title":"Existing project"}]', $db->fetchOne('SELECT portfolio FROM creator WHERE id = 1'));
            self::assertSame('An existing application', $db->fetchOne('SELECT message FROM campaign_application WHERE id = 1'));
            self::assertSame([], json_decode($db->fetchOne('SELECT proposal FROM campaign_application WHERE id = 1'), true));
        } finally {
            $db->executeStatement('DROP TABLE campaign_application');
            $db->executeStatement('DROP TABLE creator');
        }
    }
}
