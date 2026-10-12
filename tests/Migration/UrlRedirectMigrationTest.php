<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UrlRedirectMigrationTest extends KernelTestCase
{
    public function testSqliteAndPostgreSqlMigrationPreservesUniqueSources(): void
    {
        self::bootKernel();
        $postgres = self::getContainer()->get('doctrine')->getConnection();
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__, 2).'/migrations/Version20261012100000.php';
        try {
            foreach ([$sqlite, $postgres] as $connection) {
                if ($connection->createSchemaManager()->tablesExist(['url_redirect'])) $connection->executeStatement('DROP TABLE url_redirect');
                $migration = new \DoctrineMigrations\Version20261012100000($connection, new NullLogger());
                $migration->up($connection->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) $connection->executeStatement($query->getStatement());
                $connection->insert('url_redirect', ['source_path' => '/old', 'target_path' => '/kreatori', 'enabled' => 1, 'preserve_query' => 0, 'origin' => 'manual', 'created_at' => '2026-10-12 10:00:00', 'updated_at' => '2026-10-12 10:00:00']);
                self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM url_redirect'));
                $indexes = $connection->createSchemaManager()->listTableIndexes('url_redirect');
                self::assertTrue($indexes['uniq_url_redirect_source']->isUnique());
                $migration = new \DoctrineMigrations\Version20261012100000($connection, new NullLogger());
                $migration->down($connection->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) $connection->executeStatement($query->getStatement());
                self::assertFalse($connection->createSchemaManager()->tablesExist(['url_redirect']));
            }
        } finally {
            $sqlite->close();
        }
    }
}
