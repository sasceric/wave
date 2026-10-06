<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class ScheduledTaskMonitor
{
    public const REMINDERS = 'app:send-unread-message-reminders';
    public const CACHE_PRUNE = 'cache:pool:prune';

    private const TASKS = [
        self::REMINDERS => ['labelKey' => 'reminders', 'scheduleKey' => 'everyFiveMinutes', 'schedule' => '*/5 * * * *', 'interval' => 300, 'overdueAfter' => 900],
        self::CACHE_PRUNE => ['labelKey' => 'cachePrune', 'scheduleKey' => 'dailyUtc', 'schedule' => '17 2 * * * UTC', 'interval' => 86400, 'overdueAfter' => 172800],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function tracks(string $name): bool
    {
        return isset(self::TASKS[$name]);
    }

    public function start(string $name): string
    {
        $runId = bin2hex(random_bytes(16));
        $values = [
            'run_id' => $runId,
            'started_at' => gmdate('Y-m-d H:i:s'),
            'finished_at' => null,
            'exit_code' => null,
        ];
        if ($this->connection->update('scheduled_task_state', $values, ['name' => $name]) === 0) {
            $this->connection->insert('scheduled_task_state', ['name' => $name, ...$values]);
        }

        return $runId;
    }

    public function finish(string $name, string $runId, int $exitCode): void
    {
        $this->connection->update('scheduled_task_state', [
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'exit_code' => $exitCode,
        ], ['name' => $name, 'run_id' => $runId]);
    }

    public function tasks(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tasks = [];
        foreach (self::TASKS as $name => $definition) {
            $tasks[] = $this->task($name, $definition, $now);
        }

        return $tasks;
    }

    private function task(string $name, array $definition, \DateTimeImmutable $now): array
    {
        $state = $this->connection->fetchAssociative('SELECT started_at, finished_at, exit_code FROM scheduled_task_state WHERE name = ?', [$name]);
        $started = $state ? new \DateTimeImmutable($state['started_at'], new \DateTimeZone('UTC')) : null;
        $finished = $state && $state['finished_at'] !== null ? new \DateTimeImmutable($state['finished_at'], new \DateTimeZone('UTC')) : null;
        $age = $started === null ? null : $now->getTimestamp() - $started->getTimestamp();
        $status = match (true) {
            $started === null => 'neverRun',
            $finished === null && $age > 3600 => 'stalled',
            $finished === null => 'running',
            (int) $state['exit_code'] !== 0 => 'failed',
            $age > $definition['overdueAfter'] => 'overdue',
            default => 'success',
        };

        $next = $name === self::REMINDERS
            ? $now->setTimestamp((intdiv($now->getTimestamp(), 300) + 1) * 300)
            : $now->setTimezone(new \DateTimeZone('UTC'))->setTime(2, 17);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }

        return [
            'id' => $name,
            'labelKey' => $definition['labelKey'],
            'scheduleKey' => $definition['scheduleKey'],
            'schedule' => $definition['schedule'],
            'intervalSeconds' => $definition['interval'],
            'status' => $status,
            'lastStartedAt' => $started?->format(DATE_ATOM),
            'lastFinishedAt' => $finished?->format(DATE_ATOM),
            'nextExpectedAt' => $started === null ? null : $next->format(DATE_ATOM),
            'exitCode' => $state && $state['exit_code'] !== null ? (int) $state['exit_code'] : null,
            'durationSeconds' => $finished !== null ? max(0, $finished->getTimestamp() - $started->getTimestamp()) : null,
        ];
    }
}
