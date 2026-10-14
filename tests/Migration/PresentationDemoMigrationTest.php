<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261014120000;
use DoctrineMigrations\Version20261014121000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PresentationDemoMigrationTest extends TestCase
{
    public function testExistingRowsRemainOrdinaryAndBothMigrationsAreReversible(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261014120000.php';
        require_once dirname(__DIR__, 2).'/migrations/Version20261014121000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['wave_user', 'campaign'] as $table) {
            $db->executeStatement('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY)');
            $db->executeStatement('INSERT INTO '.$table.' (id) VALUES (1), (2)');
        }
        foreach ([Version20261014120000::class => 'wave_user', Version20261014121000::class => 'campaign'] as $class => $table) {
            $migration = new $class($db, new NullLogger());
            $migration->up($db->createSchemaManager()->introspectSchema());
            foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
            self::assertNull($db->fetchOne('SELECT presentation_demo_key FROM '.$table.' WHERE id = 1'));
            $db->executeStatement('UPDATE '.$table.' SET presentation_demo_key = ? WHERE id = 1', ['wave-showcase-demo']);
            self::assertSame('wave-showcase-demo', $db->fetchOne('SELECT presentation_demo_key FROM '.$table.' WHERE id = 1'));
            $down = new $class($db, new NullLogger());
            $down->down($db->createSchemaManager()->introspectSchema());
            foreach ($down->getSql() as $query) $db->executeStatement($query->getStatement());
            self::assertFalse($db->createSchemaManager()->introspectTable($table)->hasColumn('presentation_demo_key'));
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table));
        }
    }
}
