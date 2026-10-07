<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261007170000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NotificationPreferenceMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261007170000.php';
    }

    public function testExistingAccountsDefaultToEnabledAndTheMigrationCanBeReversed(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE wave_user (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('INSERT INTO wave_user (id) VALUES (1)');
        $schemaManager = $connection->createSchemaManager();
        $migration = new Version20261007170000($connection, new NullLogger());
        $migration->up($schemaManager->introspectSchema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertSame(1, (int) $connection->fetchOne('SELECT notifications_enabled FROM wave_user WHERE id = 1'));
        $connection->executeStatement('UPDATE wave_user SET notifications_enabled = FALSE WHERE id = 1');
        self::assertSame(0, (int) $connection->fetchOne('SELECT notifications_enabled FROM wave_user WHERE id = 1'));
        $noop = new Version20261007170000($connection, new NullLogger());
        $noop->up($schemaManager->introspectSchema());
        self::assertSame([], $noop->getSql());
        $down = new Version20261007170000($connection, new NullLogger());
        $down->down($schemaManager->introspectSchema());
        foreach ($down->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertFalse($schemaManager->introspectTable('wave_user')->hasColumn('notifications_enabled'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT id FROM wave_user'));
    }

    public function testMigrationPlansWithPostgreSqlBooleanSyntax(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE wave_user (id INTEGER PRIMARY KEY)');
        $migration = new Version20261007170000($connection, new NullLogger());
        (new \ReflectionProperty(AbstractMigration::class, 'platform'))->setValue($migration, new PostgreSQLPlatform());
        $migration->up($connection->createSchemaManager()->introspectSchema());
        self::assertStringContainsString('BOOLEAN NOT NULL DEFAULT TRUE', $migration->getSql()[0]->getStatement());
    }
}
