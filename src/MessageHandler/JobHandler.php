<?php

namespace App\MessageHandler;

use App\Background\JobExecutor;
use App\Background\PayloadCipher;
use App\Background\TaskRegistry;
use App\Message\JobMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

#[AsMessageHandler]
#[\Monolog\Attribute\WithMonologChannel('background')]
final class JobHandler
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PayloadCipher $cipher,
        private readonly JobExecutor $executor,
        private readonly TaskRegistry $tasks,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(JobMessage $message): void
    {
        // A session advisory lock spans external delivery without a long SQL transaction.
        $locked = $this->connection->fetchOne('SELECT CASE WHEN pg_try_advisory_lock(hashtext(?)) THEN 1 ELSE 0 END', ['wave.job.' . $message->jobId]);
        if ((int) $locked !== 1) {
            throw new RecoverableMessageHandlingException('Background job is already being handled.', retryDelay: 1000, forceRetry: false);
        }
        try {
            $row = $this->connection->fetchAssociative('SELECT * FROM background_job WHERE id = ?', [$message->jobId]);
            if (!$row || in_array($row['status'], ['completed', 'discarded'], true)) {
                return;
            }
            if ('App\\Message\\' . $row['type'] !== $message::class) {
                throw new \Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException('Job schema does not match its protected record.');
            }
            $this->connection->update('background_job', ['status' => 'running', 'started_at' => gmdate('Y-m-d H:i:s'), 'attempts' => (int) $row['attempts'] + 1], ['id' => $message->jobId]);
            $this->tasks->started($message->jobId);
            $started = microtime(true);
            try {
                $this->executor->execute($row['type'], $this->cipher->decrypt($row['payload']), $message->jobId);
                $this->connection->transactional(function () use ($message): void {
                    $this->connection->update('background_job', ['status' => 'completed', 'finished_at' => gmdate('Y-m-d H:i:s'), 'payload' => '', 'error_code' => null], ['id' => $message->jobId]);
                    $this->tasks->finished($message->jobId, true);
                });
                $this->logger->info('background.job.completed', ['job_id' => $message->jobId, 'type' => $row['type'], 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
            } catch (\Throwable $exception) {
                $this->connection->update('background_job', ['status' => 'retrying', 'error_code' => mb_substr($exception::class, 0, 255)], ['id' => $message->jobId]);
                // Provider exception text can contain private endpoints/credentials.
                $this->logger->warning('background.job.attempt_failed', ['job_id' => $message->jobId, 'type' => $row['type'], 'error_code' => $exception::class]);
                throw $exception;
            }
        } finally {
            $this->connection->executeQuery('SELECT pg_advisory_unlock(hashtext(?))', ['wave.job.' . $message->jobId]);
        }
    }
}
