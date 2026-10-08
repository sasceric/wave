<?php

namespace App\Background;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

#[\Monolog\Attribute\WithMonologChannel('background')]
final class TaskRegistry
{
    public const DEFINITIONS = [
        'UnreadMessageReminderTask' => [3600, null],
        'SitemapGenerateTask' => [3600, null],
        'ExpiredTokenCleanupTask' => [3600, null],
        'QueueMaintenanceTask' => [900, null],
        'LogCleanupTask' => [86400, null],
        'CachePruneTask' => [86400, null],
        'MediaMaintenanceTask' => [86400, null],
        'IndexReconcileTask' => [86400, null],
    ];

    public function __construct(private readonly Connection $connection, private readonly JobDispatcher $jobs, private readonly LoggerInterface $logger)
    {
    }

    public function register(): int
    {
        $added = 0;
        foreach (self::DEFINITIONS as $name => [$interval]) {
            $added += $this->connection->executeStatement('INSERT INTO wave_scheduled_task (name, interval_seconds, status, next_run_at) VALUES (?, ?, ?, ?) ON CONFLICT (name) DO NOTHING', [$name, $interval, 'scheduled', $this->next($interval)]);
        }
        $this->logger->info('scheduler.registered', ['added' => $added]);

        return $added;
    }

    public function registerAndReschedule(): int
    {
        return $this->connection->transactional(function (): int {
            $this->register();
            $rows = $this->connection->fetchAllAssociative('SELECT name, interval_seconds FROM wave_scheduled_task ORDER BY name FOR UPDATE');
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $updated = 0;
            foreach ($rows as $row) {
                if (!isset(self::DEFINITIONS[$row['name']])) {
                    continue;
                }
                // Explicit admin reset also offsets daily tasks from this instant.
                $next = $now->modify('+'.(int) $row['interval_seconds'].' seconds');
                $updated += $this->connection->update('wave_scheduled_task', ['next_run_at' => $next->format('Y-m-d H:i:s')], ['name' => $row['name']]);
            }
            $this->logger->info('scheduler.rescheduled', ['count' => $updated]);

            return $updated;
        });
    }

    public function tasks(): array
    {
        $states = $this->connection->fetchAllAssociativeIndexed('SELECT name, interval_seconds, status, next_run_at, active_job_id, last_started_at, last_finished_at, last_outcome FROM wave_scheduled_task');
        $rows = [];
        foreach (self::DEFINITIONS as $name => [$interval]) {
            $state = $states[$name] ?? null;
            $rows[] = [
                'id' => $name, 'name' => $name, 'intervalSeconds' => $state ? (int) $state['interval_seconds'] : $interval,
                'schedule' => (string) ($state['interval_seconds'] ?? $interval) . 's',
                'status' => $state['status'] ?? 'unregistered', 'lastOutcome' => $state['last_outcome'] ?? null,
                'activeJobId' => $state['active_job_id'] ?? null,
                'lastStartedAt' => $this->iso($state['last_started_at'] ?? null),
                'lastFinishedAt' => $this->iso($state['last_finished_at'] ?? null),
                'nextExpectedAt' => ($state['status'] ?? '') === 'inactive' ? null : $this->iso($state['next_run_at'] ?? null),
                'overdue' => $state !== null && $state['status'] === 'scheduled' && strtotime($state['next_run_at'] . ' UTC') < time() - 90,
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            if ($left['nextExpectedAt'] === null || $right['nextExpectedAt'] === null) {
                $order = ($left['nextExpectedAt'] === null) <=> ($right['nextExpectedAt'] === null);
            } else {
                $order = strcmp($left['nextExpectedAt'], $right['nextExpectedAt']);
            }

            return $order ?: strcmp($left['name'], $right['name']);
        });

        return $rows;
    }

    public function action(string $name, string $action): ?string
    {
        if (!isset(self::DEFINITIONS[$name]) || !in_array($action, ['run', 'scheduled', 'immediate', 'inactive'], true)) {
            throw new \InvalidArgumentException('Unknown task or action.');
        }

        return $this->connection->transactional(function () use ($name, $action): ?string {
            $row = $this->connection->fetchAssociative('SELECT * FROM wave_scheduled_task WHERE name = ? FOR UPDATE', [$name]);
            if (!$row) {
                throw new \InvalidArgumentException('Register tasks first.');
            }
            if ($action === 'run') {
                if ($row['active_job_id'] !== null) {
                    throw new \DomainException('A task run is already active.');
                }
                $id = $this->queue($row);
            } else {
                $values = ['status' => $action === 'inactive' ? 'inactive' : ($row['active_job_id'] === null ? 'scheduled' : ($row['status'] === 'running' ? 'running' : 'queued'))];
                if ($action !== 'inactive') {
                    $values['next_run_at'] = $action === 'immediate' ? gmdate('Y-m-d H:i:s') : $this->next((int) $row['interval_seconds']);
                }
                $this->connection->update('wave_scheduled_task', $values, ['name' => $name]);
                $id = null;
            }
            $this->logger->info('scheduler.action', ['task' => $name, 'action' => $action, 'job_id' => $id]);

            return $id;
        });
    }

    public function dispatchDue(): int
    {
        return $this->connection->transactional(function (): int {
            $rows = $this->connection->fetchAllAssociative("SELECT * FROM wave_scheduled_task WHERE status = 'scheduled' AND active_job_id IS NULL AND next_run_at <= ? ORDER BY next_run_at LIMIT 20 FOR UPDATE SKIP LOCKED", [gmdate('Y-m-d H:i:s')]);
            foreach ($rows as $row) {
                $this->queue($row);
            }

            return count($rows);
        });
    }

    public function started(string $id): void
    {
        $this->connection->executeStatement("UPDATE wave_scheduled_task SET status = CASE WHEN status = 'inactive' THEN status ELSE 'running' END, last_started_at = ? WHERE active_job_id = ?", [gmdate('Y-m-d H:i:s'), $id]);
    }

    public function finished(string $id, bool $success): void
    {
        $this->connection->executeStatement("UPDATE wave_scheduled_task SET status = CASE WHEN status = 'inactive' THEN status WHEN ? = 1 THEN 'scheduled' ELSE 'failed' END, active_job_id = NULL, last_finished_at = ?, last_outcome = ? WHERE active_job_id = ?", [$success ? 1 : 0, gmdate('Y-m-d H:i:s'), $success ? 'success' : 'failed', $id]);
    }

    private function queue(array $row): string
    {
        $id = $this->jobs->enqueue($row['name'], ['taskName' => $row['name']], 'task:' . $row['name'] . ':' . bin2hex(random_bytes(16)));
        $this->connection->update('wave_scheduled_task', ['active_job_id' => $id, 'status' => $row['status'] === 'inactive' ? 'inactive' : 'queued', 'next_run_at' => $this->next((int) $row['interval_seconds'])], ['name' => $row['name']]);

        return $id;
    }

    private function next(int $interval): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $now->modify('+' . $interval . ' seconds')->format('Y-m-d H:i:s');
    }

    private function iso(?string $value): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
