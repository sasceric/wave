<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Localization\LocalizedRouteMap;
use App\Service\PublicUrlSlugs;
use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261016170000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LegacyProfileRedirectRepairTest extends KernelTestCase
{
    public function testRepairIsScopedIdempotentAndPreservesOverridesOnBothDatabases(): void
    {
        self::bootKernel();
        $postgres = self::getContainer()->get('doctrine')->getConnection();
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__, 2).'/migrations/Version20261016170000.php';
        $routes = new LocalizedRouteMap(dirname(__DIR__, 2));

        foreach ([$postgres, $sqlite] as $db) {
            $db->beginTransaction();
            try {
                if ($db === $postgres) {
                    $db->executeStatement('CREATE SCHEMA legacy_profile_repair_test');
                    $db->executeStatement('SET LOCAL search_path TO legacy_profile_repair_test');
                }
                $db->executeStatement('CREATE TABLE wave_user (id INT PRIMARY KEY, deleted_at TIMESTAMP DEFAULT NULL)');
                $db->executeStatement('CREATE TABLE creator (id INT PRIMARY KEY, owner_id INT DEFAULT NULL, slug VARCHAR(120))');
                $db->executeStatement('CREATE TABLE public_url_slug (kind VARCHAR(20), slug VARCHAR(120), PRIMARY KEY(kind, slug))');
                $db->executeStatement('CREATE TABLE url_redirect (source_path VARCHAR(500) PRIMARY KEY, target_path VARCHAR(1000), enabled BOOLEAN, preserve_query BOOLEAN, origin VARCHAR(30), created_at TIMESTAMP, updated_at TIMESTAMP)');
                $db->executeStatement("INSERT INTO creator VALUES (1,NULL,'suad-asceric')");
                $slugs = new PublicUrlSlugs($db, $routes);
                $sources = $slugs->sources('creator', 'suad-asceric-de986b');
                foreach ($sources as $source) {
                    $db->executeStatement("INSERT INTO url_redirect VALUES (?, '/hr/kreatori', TRUE, TRUE, 'deleted-creator', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)", [$source]);
                }
                $migration = new Version20261016170000($db, new NullLogger());
                $schema = $db->createSchemaManager()->introspectSchema();
                $migration->up($schema);
                $migration->postUp($schema);
                foreach ($sources as $source) {
                    self::assertSame('/kreatori/suad-asceric', $db->fetchOne('SELECT target_path FROM url_redirect WHERE source_path = ?', [$source]));
                }
                self::assertTrue((bool) $db->fetchOne('SELECT preserve_query FROM url_redirect WHERE source_path = ?', [$sources[0]]));
                self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM public_url_slug WHERE kind = 'creator' AND slug = 'suad-asceric-de986b'"));

                // A repeat run fills a missing alias, but leaves manual, disabled
                // and already-correct/custom targets alone, as well as other people.
                $db->executeStatement("UPDATE url_redirect SET target_path = '/kreatori', origin = 'manual' WHERE source_path = ?", [$sources[0]]);
                $db->executeStatement("UPDATE url_redirect SET enabled = FALSE, origin = 'deleted-creator', target_path = '/kreatori' WHERE source_path = ?", [$sources[1]]);
                $db->executeStatement("UPDATE url_redirect SET target_path = '/special', origin = 'deleted-creator' WHERE source_path = ?", [$sources[2]]);
                $db->executeStatement('DELETE FROM url_redirect WHERE source_path = ?', [$sources[3]]);
                $db->executeStatement("INSERT INTO url_redirect VALUES ('/kreatori/someone-123abc', '/kreatori', TRUE, FALSE, 'deleted-creator', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $migration->postUp($schema);
                self::assertSame('/kreatori', $db->fetchOne('SELECT target_path FROM url_redirect WHERE source_path = ?', [$sources[0]]));
                self::assertFalse((bool) $db->fetchOne('SELECT enabled FROM url_redirect WHERE source_path = ?', [$sources[1]]));
                self::assertSame('/special', $db->fetchOne('SELECT target_path FROM url_redirect WHERE source_path = ?', [$sources[2]]));
                self::assertSame('/kreatori/suad-asceric', $db->fetchOne('SELECT target_path FROM url_redirect WHERE source_path = ?', [$sources[3]]));
                self::assertSame('/kreatori', $db->fetchOne("SELECT target_path FROM url_redirect WHERE source_path = '/kreatori/someone-123abc'"));
                self::assertSame(count($sources) + 1, (int) $db->fetchOne('SELECT COUNT(*) FROM url_redirect'));

                $db->executeStatement('DELETE FROM url_redirect');
                $db->executeStatement("INSERT INTO url_redirect VALUES ('/kreatori/suad-asceric', '/kreatori/suad-asceric-de986b', TRUE, FALSE, 'manual', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $migration->postUp($schema);
                self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM url_redirect'));
                $db->executeStatement('DELETE FROM url_redirect');
                $db->executeStatement('INSERT INTO wave_user VALUES (1, CURRENT_TIMESTAMP)');
                $db->executeStatement('UPDATE creator SET owner_id = 1');
                $migration->postUp($schema);
                self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM url_redirect'));
                $db->executeStatement('DELETE FROM creator');
                $migration->postUp($schema);
                self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM url_redirect'));
            } finally {
                $db->rollBack();
            }
        }
        $sqlite->close();
    }
}
