<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261008090000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HourlyReminderMigrationTest extends TestCase
{
    public static function statuses(): iterable
    {
        foreach (['scheduled', 'queued', 'running', 'inactive', 'failed'] as $status) {
            yield $status => [$status];
        }
    }

    #[DataProvider('statuses')]
    public function testExistingSchedulesChangeWithoutResettingTaskState(string $status): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261008090000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE wave_scheduled_task (name TEXT PRIMARY KEY, interval_seconds INTEGER, next_run_at TEXT, status TEXT, active_job_id TEXT, last_outcome TEXT)');
        $db->insert('wave_scheduled_task', [
            'name' => 'UnreadMessageReminderTask', 'interval_seconds' => 300,
            'next_run_at' => '2026-10-01 00:00:00', 'status' => $status,
            'active_job_id' => 'active-job', 'last_outcome' => 'success',
        ]);
        $db->insert('wave_scheduled_task', ['name' => 'QueueMaintenanceTask', 'interval_seconds' => 900]);
        $up = new Version20261008090000($db, new NullLogger());
        $up->up(new Schema());
        foreach ($up->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $row = $db->fetchAssociative("SELECT * FROM wave_scheduled_task WHERE name = 'UnreadMessageReminderTask'");
        self::assertSame(3600, (int) $row['interval_seconds']);
        self::assertEqualsWithDelta(time() + 3600, strtotime($row['next_run_at'].' UTC'), 5);
        self::assertSame($status, $row['status']);
        self::assertSame('active-job', $row['active_job_id']);
        self::assertSame('success', $row['last_outcome']);
        self::assertSame(900, (int) $db->fetchOne("SELECT interval_seconds FROM wave_scheduled_task WHERE name = 'QueueMaintenanceTask'"));
        // A repeat must not postpone an already updated schedule.
        foreach ($up->getSql() as $query) {
            self::assertSame(0, $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes()));
        }
        $down = new Version20261008090000($db, new NullLogger());
        $down->down(new Schema());
        foreach ($down->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        self::assertSame(300, (int) $db->fetchOne("SELECT interval_seconds FROM wave_scheduled_task WHERE name = 'UnreadMessageReminderTask'"));
        self::assertSame($status, $db->fetchOne("SELECT status FROM wave_scheduled_task WHERE name = 'UnreadMessageReminderTask'"));
    }
}
