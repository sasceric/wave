<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublicUrlsMigrationTest extends KernelTestCase
{
    public function testUpgradePreservesOldUrlsAndAllowsImmediateFirstEditOnBothDatabases(): void
    {
        self::bootKernel();
        $postgres = self::getContainer()->get('doctrine')->getConnection();
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__, 2).'/migrations/Version20261013190000.php';
        foreach ([$postgres, $sqlite] as $db) {
            $db->beginTransaction();
            try {
                if ($db === $postgres) { $db->executeStatement('CREATE SCHEMA public_urls_migration_test'); $db->executeStatement('SET LOCAL search_path TO public_urls_migration_test'); }
                $db->executeStatement('CREATE TABLE wave_user (id INT PRIMARY KEY, deleted_at TIMESTAMP DEFAULT NULL)');
                $db->executeStatement('CREATE TABLE creator (id INT PRIMARY KEY, owner_id INT DEFAULT NULL, slug VARCHAR(100), display_name VARCHAR(120))');
                $db->executeStatement('CREATE TABLE company (id INT PRIMARY KEY, owner_id INT DEFAULT NULL, slug VARCHAR(100), name VARCHAR(120))');
                $db->executeStatement('CREATE TABLE campaign (id INT PRIMARY KEY, company_id INT, slug VARCHAR(120), title VARCHAR(160))');
                $db->executeStatement('CREATE TABLE directory_index (id VARCHAR(100) PRIMARY KEY, search_text TEXT)');
                $db->executeStatement("INSERT INTO directory_index VALUES ('creator:1', 'amina kovac')");
                $db->executeStatement('CREATE TABLE url_redirect (id '.($db === $postgres ? 'SERIAL' : 'INTEGER').' PRIMARY KEY, source_path VARCHAR(500) UNIQUE, target_path VARCHAR(1000), enabled BOOLEAN, preserve_query BOOLEAN, origin VARCHAR(30), created_at TIMESTAMP, updated_at TIMESTAMP)');
                $db->executeStatement("INSERT INTO creator VALUES (1,NULL,'amina-kovac-123abc','Amina Kovač'), (2,NULL,'amina-kovac-456def','Amina Kovač'), (3,NULL,'custom-profile','Custom profile')");
                $db->executeStatement("INSERT INTO company VALUES (1,NULL,'dn-kozmetik-123abc','DN kozmetik')");
                $db->executeStatement("INSERT INTO campaign VALUES (1,1,'campaign-123abc','Kampanja')");
                $migration = new \DoctrineMigrations\Version20261013190000($db, new NullLogger());
                $migration->up($db->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
                $migration->postUp($db->createSchemaManager()->introspectSchema());
                self::assertSame(['amina-kovac', 'amina-kovac-1', 'custom-profile'], $db->fetchFirstColumn('SELECT slug FROM creator ORDER BY id'));
                self::assertNull($db->fetchOne('SELECT public_url_changed_at FROM creator WHERE id=1'));
                self::assertSame('amina kovac', $db->fetchOne("SELECT search_text FROM directory_index WHERE id='creator:1'"));
                self::assertSame('/kreatori/amina-kovac', $db->fetchOne("SELECT target_path FROM url_redirect WHERE source_path='/en/creators/amina-kovac-123abc'"));
                self::assertSame('/kampanje/kampanja', $db->fetchOne("SELECT target_path FROM url_redirect WHERE source_path='/en/campaigns/campaign-123abc'"));
                $count = (int) $db->fetchOne('SELECT COUNT(*) FROM url_redirect');
                $migration->postUp($db->createSchemaManager()->introspectSchema());
                self::assertSame($count, (int) $db->fetchOne('SELECT COUNT(*) FROM url_redirect'));
            } finally { $db->rollBack(); }
        }
        $sqlite->close();
    }
}
