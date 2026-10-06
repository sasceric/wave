<?php

namespace App\Background;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[\Monolog\Attribute\WithMonologChannel('background')]
final class FailedJobs
{
    public function __construct(private readonly Connection $connection, private readonly MessageBusInterface $bus, private readonly LoggerInterface $logger)
    {
    }

    public function action(int $id, string $action): void
    {
        if (!in_array($action, ['retry', 'discard'], true)) {
            throw new \InvalidArgumentException('Unknown failed job action.');
        }
        $this->connection->transactional(function () use ($id, $action): void {
            $message = $this->connection->fetchAssociative("SELECT id, body FROM messenger_messages WHERE id = ? AND queue_name = 'failed' FOR UPDATE", [$id]);
            $body = $message ? json_decode($message['body'], true, flags: JSON_THROW_ON_ERROR) : [];
            $job = isset($body['jobId']) ? $this->connection->fetchAssociative('SELECT * FROM background_job WHERE id = ? FOR UPDATE', [$body['jobId']]) : false;
            if (!$job || !isset(JobCatalog::TYPES[$job['type']]) || $job['status'] !== 'failed') {
                throw new \InvalidArgumentException('Failed job is unavailable.');
            }
            if ($action === 'retry') {
                $task = $this->connection->fetchAssociative('SELECT * FROM wave_scheduled_task WHERE name = ? FOR UPDATE', [$job['type']]);
                if ($task && $task['active_job_id'] !== null) {
                    throw new \DomainException('A task run is already active.');
                }
                $this->bus->dispatch(JobCatalog::message($job['type'], $job['id']));
                $this->connection->update('background_job', ['status' => 'queued', 'finished_at' => null, 'error_code' => null], ['id' => $job['id']]);
                if ($task) {
                    $this->connection->update('wave_scheduled_task', ['active_job_id' => $job['id'], 'status' => $task['status'] === 'inactive' ? 'inactive' : 'queued'], ['name' => $job['type']]);
                }
            } else {
                $this->connection->update('background_job', ['status' => 'discarded', 'payload' => '', 'finished_at' => gmdate('Y-m-d H:i:s')], ['id' => $job['id']]);
            }
            $this->connection->delete('messenger_messages', ['id' => $id]);
            $this->logger->info('queue.failed_job.action', ['job_id' => $job['id'], 'action' => $action]);
        });
    }
}
