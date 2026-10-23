<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PortfolioVisibilityMigrationTest extends KernelTestCase
{
    public function testPublishesAllCreatorAndAgencyWorksWithoutChangingContent(): void
    {
        self::bootKernel();
        $configured = self::getContainer()->get('doctrine')->getConnection();
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connections = [$sqlite];
        if ($configured->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $connections[] = $configured;
        }
        require_once dirname(__DIR__, 2).'/migrations/Version20261023120000.php';

        $private = [
            'id' => 'private', 'publicPermission' => false, 'title' => 'Priča o brendu',
            'featured' => true, 'url' => '/media/original.webp', 'coverMediaId' => 42,
            'media' => [['mediaId' => 43, 'type' => 'image', 'url' => '/media/second.webp']],
            'results' => [['metric' => 'views', 'value' => 1200]],
            'projectDate' => '2026-10-22', 'summary' => 'Original content',
        ];
        $public = ['id' => 'public', 'publicPermission' => true, 'featured' => false];
        $legacy = ['id' => 'legacy', 'url' => 'https://youtu.be/example'];
        $portfolios = [1 => [$private, $public, $legacy], 2 => [$private, $legacy], 3 => [], 4 => [$public]];

        foreach ($connections as $db) {
            $db->beginTransaction();
            try {
                if ($db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                    $db->executeStatement('CREATE SCHEMA portfolio_visibility_migration_test');
                    $db->executeStatement('SET LOCAL search_path TO portfolio_visibility_migration_test');
                }
                $db->executeStatement('CREATE TABLE creator (id INTEGER PRIMARY KEY, agency_profile BOOLEAN NOT NULL, portfolio JSON NOT NULL)');
                foreach ($portfolios as $id => $works) {
                    $db->executeStatement('INSERT INTO creator (id, agency_profile, portfolio) VALUES (?, ?, ?)', [
                        $id, $id === 2 ? 1 : 0, json_encode($works, JSON_THROW_ON_ERROR),
                    ], ['integer', 'boolean', 'string']);
                }

                $migration = new \DoctrineMigrations\Version20261023120000($db, new NullLogger());
                $migration->up(new Schema());
                // Planning must not change live visibility before SQL executes.
                self::assertEquals($portfolios[1], json_decode($db->fetchOne('SELECT portfolio FROM creator WHERE id = 1'), true, flags: JSON_THROW_ON_ERROR));
                foreach ($migration->getSql() as $query) {
                    $db->executeStatement($query->getStatement());
                }
                foreach ($portfolios as $id => $works) {
                    $expected = array_map(static fn (array $work): array => [...$work, 'publicPermission' => true], $works);
                    $actual = json_decode($db->fetchOne('SELECT portfolio FROM creator WHERE id = ?', [$id]), true, flags: JSON_THROW_ON_ERROR);
                    self::assertEquals($expected, $actual);
                    foreach ($actual as $work) {
                        self::assertTrue($work['publicPermission']);
                    }
                }
                self::assertSame(4, (int) $db->fetchOne('SELECT COUNT(*) FROM creator'));
                // A retry preserves the same works, their order and all fields.
                foreach ($migration->getSql() as $query) {
                    $db->executeStatement($query->getStatement());
                }
                self::assertEquals(
                    array_map(static fn (array $work): array => [...$work, 'publicPermission' => true], $portfolios[1]),
                    json_decode($db->fetchOne('SELECT portfolio FROM creator WHERE id = 1'), true, flags: JSON_THROW_ON_ERROR),
                );
            } finally {
                $db->rollBack();
            }
        }
        $sqlite->close();
    }
}
