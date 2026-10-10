<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261010100000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccountDeletionMigrationTest extends KernelTestCase
{
    public function testMigrationOnPostgreSql(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($em))->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $this->migrate($em->getConnection());
    }

    public function testMigrationOnSqlite(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $this->migrate($connection);
        } finally {
            $connection->close();
        }
    }

    private function migrate(Connection $connection): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261010100000.php';
        $connection->executeStatement('CREATE TABLE wave_user (id INTEGER NOT NULL PRIMARY KEY)');
        $migration = new Version20261010100000($connection, new NullLogger());
        $migration->up($connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) $connection->executeStatement($query->getStatement());
        self::assertArrayHasKey('deleted_at', $connection->createSchemaManager()->listTableColumns('wave_user'));
        self::assertTrue($connection->createSchemaManager()->tablesExist(['stored_file_deletion']));
        $migration = new Version20261010100000($connection, new NullLogger());
        $migration->down($connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) $connection->executeStatement($query->getStatement());
        self::assertArrayNotHasKey('deleted_at', $connection->createSchemaManager()->listTableColumns('wave_user'));
        self::assertFalse($connection->createSchemaManager()->tablesExist(['stored_file_deletion']));
        $connection->executeStatement('DROP TABLE wave_user');
    }
}
