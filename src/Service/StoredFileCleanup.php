<?php

namespace App\Service;

use App\Support\TicketAttachments;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class StoredFileCleanup
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MediaStorage $media,
        private readonly TicketAttachments $tickets,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function schedule(string $kind, string $path): void
    {
        if (!in_array($kind, ['media', 'support'], true)) {
            throw new \InvalidArgumentException('Unknown stored file kind.');
        }
        $this->connection->executeStatement('INSERT INTO stored_file_deletion (id, kind, path) VALUES (?, ?, ?) ON CONFLICT (id) DO NOTHING', [hash('sha256', $kind.':'.$path), $kind, $path]);
    }

    public function run(int $limit = 1000): int
    {
        // API transactions commit in kernel.response. Never remove files on a rollback.
        if ($this->connection->isTransactionActive()) {
            return 0;
        }
        $rows = $this->connection->fetchAllAssociative('SELECT id, kind, path FROM stored_file_deletion ORDER BY id LIMIT '.max(1, min(10000, $limit)));
        $removed = 0;
        foreach ($rows as $row) {
            try {
                if ($row['kind'] === 'media') {
                    $this->media->removePath($row['path']);
                } else {
                    $this->tickets->removeTicketFiles($row['path']);
                }
                $this->connection->delete('stored_file_deletion', ['id' => $row['id']]);
                ++$removed;
            } catch (\Throwable $error) {
                $this->logger->warning('Stored file cleanup will be retried.', ['cleanup_id' => $row['id'], 'exception' => $error]);
            }
        }

        return $removed;
    }
}
