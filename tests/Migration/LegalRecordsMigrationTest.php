<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LegalRecordsMigrationTest extends KernelTestCase
{
    public function testNewConsentFieldsAreNullableAndDoNotInventHistoricalConsent(): void
    {
        self::bootKernel();
        $configured = self::getContainer()->get('doctrine')->getConnection();
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connections = [$sqlite];
        if ($configured->getDatabasePlatform() instanceof PostgreSQLPlatform) $connections[] = $configured;
        foreach (['Version20261026010000', 'Version20261026160000'] as $version) {
            require_once dirname(__DIR__, 2).'/migrations/'.$version.'.php';
        }
        foreach ($connections as $db) {
            $db->beginTransaction();
            try {
                if ($db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                    $db->executeStatement('CREATE SCHEMA legal_records_migration_test');
                    $db->executeStatement('SET LOCAL search_path TO legal_records_migration_test');
                }
                $db->executeStatement('CREATE TABLE wave_user (id INT PRIMARY KEY)');
                $db->executeStatement('CREATE TABLE newsletter_subscriber (id INT PRIMARY KEY)');
                foreach (['wave_user' => 'Version20261026010000', 'newsletter_subscriber' => 'Version20261026160000'] as $table => $version) {
                    $db->executeStatement('INSERT INTO '.$table.' (id) VALUES (1)');
                    $class = 'DoctrineMigrations\\'.$version;
                    $migration = new $class($db, new NullLogger());
                    $migration->up(new Schema());
                    foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement());
                    self::assertNull($db->fetchOne('SELECT legal_records FROM '.$table.' WHERE id = 1'));
                    $db->executeStatement('UPDATE '.$table.' SET legal_records = ? WHERE id = 1', ['{"newsletter":{"enabled":false}}']);
                    self::assertSame(['newsletter' => ['enabled' => false]], json_decode($db->fetchOne('SELECT legal_records FROM '.$table.' WHERE id = 1'), true));
                }
            } finally {
                $db->rollBack();
            }
        }
        $sqlite->close();
    }
}
