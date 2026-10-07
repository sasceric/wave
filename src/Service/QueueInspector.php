<?php

namespace App\Service;

use App\Background\JobCatalog;
use Doctrine\DBAL\Connection;

final class QueueInspector
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function queues(): array
    {
        // Metadata only: never deserialize a message to display statistics.
        $counts = $this->connection->fetchAllAssociative("SELECT queue_name, headers::jsonb->>'type' AS type, COUNT(*) AS size, COUNT(*) FILTER (WHERE available_at > (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')) AS delayed, COUNT(*) FILTER (WHERE delivered_at IS NOT NULL) AS in_flight FROM messenger_messages GROUP BY queue_name, type");
        $rows = [];
        foreach (JobCatalog::TYPES as $type => $queue) {
            $rows[$type] = ['id' => $type, 'kind' => 'message', 'count' => 0, 'delayed' => 0, 'inFlight' => 0, 'status' => 'available'];
        }
        foreach (['realtime', 'mail', 'push', 'background', 'failed'] as $queue) {
            $rows['messenger.transport.' . $queue] = ['id' => 'messenger.transport.' . $queue, 'kind' => 'transport', 'count' => 0, 'delayed' => 0, 'inFlight' => 0, 'status' => 'available'];
        }
        foreach ($counts as $count) {
            $type = str_starts_with($count['type'] ?? '', 'App\\Message\\') ? substr($count['type'], strlen('App\\Message\\')) : '';
            foreach ([$type, 'messenger.transport.' . $count['queue_name']] as $key) {
                if (!isset($rows[$key])) {
                    continue;
                }
                $rows[$key]['count'] += (int) $count['size'];
                $rows[$key]['delayed'] += (int) $count['delayed'];
                $rows[$key]['inFlight'] += (int) $count['in_flight'];
            }
        }

        return array_values($rows);
    }

    public function workers(): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, mode, queues, last_seen_at, memory_bytes, handled FROM worker_heartbeat WHERE last_seen_at >= ? ORDER BY last_seen_at DESC', [gmdate('Y-m-d H:i:s', time() - 90)]);
    }

    public function failed(int $page, int $size): array
    {
        return $this->connection->fetchAllAssociative("SELECT m.id, j.id AS job_id, j.type, j.attempts, j.error_code, j.finished_at FROM messenger_messages m JOIN background_job j ON j.id = m.body::jsonb->>'jobId' WHERE m.queue_name = 'failed' ORDER BY m.id DESC LIMIT ? OFFSET ?", [$size + 1, ($page - 1) * $size], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER]);
    }

    public function failedCount(): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM messenger_messages m JOIN background_job j ON j.id = m.body::jsonb->>'jobId' WHERE m.queue_name = 'failed'");
    }
}
