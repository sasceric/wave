<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261102120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PrefixedRedirectCleanupTest extends TestCase
{
    public function testGeneratedPrefixedAliasesFoldIntoCanonicalRowsAndManualRulesStay(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261102120000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE url_redirect (id INTEGER PRIMARY KEY AUTOINCREMENT, source_path VARCHAR(500) UNIQUE, target_path VARCHAR(1000), enabled BOOLEAN, preserve_query BOOLEAN, origin VARCHAR(30), created_at TIMESTAMP, updated_at TIMESTAMP)');
        $rows = [
            // URL change: canonical row plus every prefixed alias.
            ['/kreatori/nermana', '/kreatori/nermanar', 1, 'url-change'],
            ['/hr/kreatori/nermana', '/kreatori/nermanar', 1, 'url-change'],
            ['/si/ustvarjalci/nermana', '/kreatori/nermanar', 1, 'url-change'],
            ['/en/creators/nermana', '/kreatori/nermanar', 1, 'url-change'],
            ['/creators/nermana', '/kreatori/nermanar', 1, 'url-change'],
            // Deleted profile: per-language listings.
            ['/kreatori/vildana-kovacevic-1', '/kreatori', 1, 'deleted-creator'],
            ['/rs/kreatori/vildana-kovacevic-1', '/rs/kreatori', 1, 'deleted-creator'],
            ['/de/kreative/vildana-kovacevic-1', '/de/kreative', 1, 'deleted-creator'],
            // Pure canonicalisation rows from URL unification.
            ['/hr/tvrtke/brand', '/kompanije/brand', 1, 'url-unified'],
            // No canonical row yet: the first alias becomes it.
            ['/en/campaigns/summer', '/kampanje/winter', 1, 'url-change'],
            ['/hr/kampanje/summer', '/kampanje/winter', 1, 'url-change'],
            // Manual and disabled rules are never touched.
            ['/hr/kreatori/manual', '/somewhere', 1, 'manual'],
            ['/kreatori/off', '/kreatori/elsewhere', 0, 'url-change'],
            ['/hr/kreatori/off', '/kreatori/elsewhere', 1, 'url-change'],
            ['/hr/kreatori/disabled-alias', '/kreatori/x', 0, 'url-change'],
        ];
        foreach ($rows as [$source, $target, $enabled, $origin]) {
            $db->insert('url_redirect', ['source_path' => $source, 'target_path' => $target, 'enabled' => $enabled, 'preserve_query' => 0, 'origin' => $origin, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        }
        $migration = new Version20261102120000($db, new NullLogger());
        $schema = $db->createSchemaManager()->introspectSchema();
        $migration->postUp($schema);

        self::assertSame([
            '/hr/kreatori/disabled-alias' => '/kreatori/x',
            '/hr/kreatori/manual' => '/somewhere',
            '/hr/kreatori/off' => '/kreatori/elsewhere',
            '/kampanje/summer' => '/kampanje/winter',
            '/kreatori/nermana' => '/kreatori/nermanar',
            '/kreatori/off' => '/kreatori/elsewhere',
            '/kreatori/vildana-kovacevic-1' => '/kreatori',
        ], $db->fetchAllKeyValue('SELECT source_path, target_path FROM url_redirect ORDER BY source_path'));

        // Idempotent.
        $migration->postUp($schema);
        self::assertSame(7, (int) $db->fetchOne('SELECT COUNT(*) FROM url_redirect'));
    }
}
