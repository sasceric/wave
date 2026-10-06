<?php

namespace App\Background;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[\Monolog\Attribute\WithMonologChannel('background')]
final class JobDispatcher
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MessageBusInterface $bus,
        private readonly PayloadCipher $cipher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enqueue(string $type, array $payload = [], ?string $dedupeKey = null, int $delayMilliseconds = 0): string
    {
        if (!isset(JobCatalog::TYPES[$type])) {
            throw new \InvalidArgumentException('Unknown job type.');
        }

        return $this->connection->transactional(function () use ($type, $payload, $dedupeKey, $delayMilliseconds): string {
            $id = bin2hex(random_bytes(16));
            $inserted = $this->connection->executeStatement('INSERT INTO background_job (id, type, queue, dedupe_key, payload, status, attempts, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?) ON CONFLICT (dedupe_key) DO NOTHING', [$id, $type, JobCatalog::TYPES[$type], $dedupeKey, $this->cipher->encrypt($payload), 'queued', gmdate('Y-m-d H:i:s')]);
            if ($inserted === 0) {
                return (string) $this->connection->fetchOne('SELECT id FROM background_job WHERE dedupe_key = ?', [$dedupeKey]);
            }
            $message = JobCatalog::message($type, $id);
            $this->bus->dispatch($delayMilliseconds > 0 ? new \Symfony\Component\Messenger\Envelope($message, [new \Symfony\Component\Messenger\Stamp\DelayStamp(min(900000, $delayMilliseconds))]) : $message);
            $this->logger->info('background.job.queued', ['job_id' => $id, 'type' => $type, 'queue' => JobCatalog::TYPES[$type]]);

            return $id;
        });
    }
}
