<?php

namespace App\Background;

use App\Entity\Media;
use App\Service\MediaThumbnails;
use App\Service\WebPushNotificationSender;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;

#[\Monolog\Attribute\WithMonologChannel('background')]
final class JobExecutor
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly JobDispatcher $jobs,
        private readonly TransportInterface $mailTransport,
        private readonly WebPushNotificationSender $push,
        private readonly HubInterface $hub,
        private readonly MediaThumbnails $thumbnails,
        private readonly \App\Service\StoredFileCleanup $fileCleanup,
        private readonly CachedSitemap $sitemap,
        private readonly DirectoryIndexer $indexer,
        private readonly ReminderBatch $reminders,
        private readonly \App\Credits\CreditService $credits,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')] private readonly PruneableInterface $cache,
        #[Autowire('%kernel.logs_dir%')] private readonly string $logsDirectory,
        #[Autowire('%env(int:LOG_RETENTION_DAYS)%')] private readonly int $logRetention,
    ) {
    }

    public function execute(string $type, array $payload, string $id): void
    {
        match ($type) {
            'CreditsAnnouncementMessage' => $this->credits->announce((string) $payload['announcementId']),
            'SendEmailMessage' => $this->email($payload),
            'SendWebPushMessage' => $this->push->deliver($payload),
            'PublishRealtimeMessage' => $this->realtime($payload),
            'GenerateThumbnailsMessage' => $this->thumbnail((int) $payload['mediaId']),
            'MediaIndexingMessage' => $this->mediaBatch($payload),
            'CreatorIndexingMessage' => $this->indexer->index('creator', $payload['ids']),
            'CampaignIndexingMessage' => $this->indexer->index('campaign', $payload['ids']),
            'CompanyIndexingMessage' => $this->indexer->index('company', $payload['ids']),
            'IndexReconcileTask' => $this->indexer->rebuild($payload['kind'] ?? 'creator', (int) ($payload['after'] ?? 0), isset($payload['upper']) ? (int) $payload['upper'] : null),
            'SitemapGenerateTask' => $this->sitemap->generate(),
            'UnreadMessageReminderTask' => $this->reminders->run(),
            'MediaMaintenanceTask' => $this->maintainMedia(),
            'CachePruneTask' => $this->cache->prune(),
            'LogCleanupTask' => $this->cleanupLogs(),
            'ExpiredTokenCleanupTask' => $this->cleanupTokens(),
            'QueueMaintenanceTask' => $this->cleanupJobs(),
            default => throw new \Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException('Unknown job type.'),
        };
    }

    private function maintainMedia(): void
    {
        $this->fileCleanup->run(10000);
        $this->mediaBatch([]);
    }

    private function email(array $payload): void
    {
        if (($payload['tokenHash'] ?? null) !== null && !$this->connection->fetchOne('SELECT id FROM user_action_token WHERE token_hash = ? AND expires_at > ?', [$payload['tokenHash'], (new \DateTimeImmutable())->format('Y-m-d H:i:s')])) {
            $this->logger->info('mail.skipped', ['reason' => 'security_token_no_longer_valid']);

            return;
        }
        $reminder = $payload['reminder'] ?? null;
        if ($reminder !== null && !$this->isCurrentReminder($reminder)) {
            $this->logger->info('mail.skipped', ['reason' => 'reminder_no_longer_current']);

            return;
        }
        $raw = base64_decode($payload['raw'], true);
        if ($raw === false) {
            throw new \Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException('Invalid mail payload.');
        }
        $this->mailTransport->send(new RawMessage($raw), new Envelope(new Address($payload['sender']), array_map(static fn (string $email): Address => new Address($email), $payload['recipients'])));
    }

    private function isCurrentReminder(array $reminder): bool
    {
        $sql = <<<'SQL'
            SELECT t.id FROM campaign_conversation t
            JOIN campaign c ON c.id = t.campaign_id
            JOIN creator cr ON cr.id = t.creator_id
            JOIN company co ON co.id = c.company_id
            JOIN wave_user creator_owner ON creator_owner.id = cr.owner_id
            JOIN wave_user company_owner ON company_owner.id = co.owner_id
            WHERE t.id = ? AND creator_owner.deleted_at IS NULL AND company_owner.deleted_at IS NULL
                AND (creator_owner.id = ? OR company_owner.id = ?)
            SQL;
        if (!$this->connection->fetchOne($sql, [$reminder['conversationId'], $reminder['recipientId'], $reminder['recipientId']])) {
            return false;
        }

        return (int) $this->connection->fetchOne(
            'SELECT COALESCE(MIN(id), 0) FROM campaign_message WHERE conversation_id = ? AND sender_id != ? AND read_at IS NULL',
            [$reminder['conversationId'], $reminder['recipientId']],
        ) === (int) $reminder['firstId'];
    }

    private function realtime(array $payload): void
    {
        if (!$this->connection->fetchOne('SELECT id FROM wave_user WHERE id = ? AND deleted_at IS NULL', [$payload['userId']])) {
            return;
        }
        $result = $this->hub->publish(new Update('https://wave.local/users/' . (int) $payload['userId'], json_encode($payload['payload'], JSON_THROW_ON_ERROR), true, $payload['eventId']));
        if (trim($result) === '') {
            throw new \RuntimeException('Mercure returned an empty event identifier.');
        }
    }

    private function thumbnail(int $id): void
    {
        $media = $this->entityManager->find(Media::class, $id);
        if (!$media) {
            return;
        }
        $dimensions = $this->thumbnails->warm($media->getStoragePath());
        $media->setDimensions($dimensions['width'], $dimensions['height']);
        $this->entityManager->flush();
    }

    private function mediaBatch(array $payload): void
    {
        $after = (int) ($payload['after'] ?? 0);
        $upper = (int) ($payload['upper'] ?? $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM media'));
        $rows = $this->connection->fetchAllAssociative('SELECT id, storage_path FROM media WHERE id > ? AND id <= ? ORDER BY id LIMIT 50', [$after, $upper]);
        foreach ($rows as $row) {
            if (!$this->thumbnails->complete($row['storage_path'])) {
                $this->jobs->enqueue('GenerateThumbnailsMessage', ['mediaId' => (int) $row['id']], 'thumbnail-repair:' . $row['id'] . ':' . gmdate('Y-m-d'));
            }
        }
        if (count($rows) === 50) {
            $this->jobs->enqueue('MediaIndexingMessage', ['after' => (int) end($rows)['id'], 'upper' => $upper]);
        }
    }

    private function cleanupTokens(): void
    {
        $count = $this->connection->executeStatement('DELETE FROM user_action_token WHERE id IN (SELECT id FROM user_action_token WHERE expires_at <= ? ORDER BY expires_at LIMIT 500)', [(new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        if ($count === 500) {
            $this->jobs->enqueue('ExpiredTokenCleanupTask');
        }
        $this->logger->info('tokens.cleanup.completed', ['count' => $count]);
    }

    private function cleanupJobs(): void
    {
        $count = $this->connection->executeStatement("DELETE FROM background_job WHERE id IN (SELECT id FROM background_job WHERE status IN ('completed', 'discarded') AND finished_at < ? ORDER BY finished_at LIMIT 500)", [gmdate('Y-m-d H:i:s', time() - 2592000)]);
        $this->connection->executeStatement('DELETE FROM worker_heartbeat WHERE last_seen_at < ?', [gmdate('Y-m-d H:i:s', time() - 3600)]);
        if ($count === 500) {
            $this->jobs->enqueue('QueueMaintenanceTask');
        }
        $this->logger->info('queue.cleanup.completed', ['count' => $count]);
    }

    private function cleanupLogs(): void
    {
        if (!is_dir($this->logsDirectory)) {
            return;
        }
        $removed = 0;
        foreach (new \DirectoryIterator($this->logsDirectory) as $file) {
            // Rotation names only; never delete a currently open application log.
            if ($file->isFile() && !$file->isLink() && preg_match('/^(dev|prod|test)(?:\.[a-z_]+)?-\d{4}-\d{2}-\d{2}\.log$/D', $file->getFilename()) && $file->getMTime() < time() - max(1, $this->logRetention) * 86400) {
                if (!unlink($file->getPathname())) {
                    throw new \RuntimeException('Unable to remove retained log.');
                }
                if (++$removed >= 100) {
                    $this->jobs->enqueue('LogCleanupTask');
                    break;
                }
            }
        }
        $this->logger->info('logs.cleanup.completed', ['count' => $removed]);
    }
}
