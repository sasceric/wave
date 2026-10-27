<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

final class UnusedMediaCleanup
{
    private const UNREFERENCED = <<<'SQL'
        NOT EXISTS (SELECT 1 FROM creator WHERE avatar_media_id = media.id)
        AND NOT EXISTS (SELECT 1 FROM company WHERE logo_media_id = media.id)
        AND NOT EXISTS (SELECT 1 FROM company WHERE cover_media_id = media.id)
        AND NOT EXISTS (SELECT 1 FROM listing_ad WHERE image_id = media.id)
        AND NOT EXISTS (SELECT 1 FROM campaign WHERE cover_media_id = media.id)
        AND NOT EXISTS (SELECT 1 FROM creator_portfolio_media WHERE media_id = media.id)
        AND NOT EXISTS (SELECT 1 FROM marketplace_report_media WHERE media_id = media.id)
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly StoredFileCleanup $files,
    ) {
    }

    public function isReferenced(int $id): bool
    {
        return (bool) $this->connection->fetchOne('SELECT 1 FROM media WHERE id = ? AND NOT ('.self::UNREFERENCED.')', [$id]);
    }

    /**
     * Call after all replacement associations have been flushed, in the same transaction.
     * Physical deletion is deferred until that transaction has committed.
     *
     * @param list<int|null> $ids
     * @return list<int>
     */
    public function remove(array $ids, ?\DateTimeImmutable $uploadedBefore = null): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null && $id > 0)));
        sort($ids);
        if ($ids === []) {
            return [];
        }

        return $this->connection->transactional(function () use ($ids, $uploadedBefore): array {
            $removed = [];
            foreach ($ids as $id) {
                // Serialize against new FK references before checking their committed state.
                $lock = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';
                $row = $this->connection->fetchAssociative('SELECT storage_path FROM media WHERE id = ?'.$lock, [$id]);
                if ($row === false) {
                    continue;
                }
                $sql = 'DELETE FROM media WHERE id = ? AND '.self::UNREFERENCED;
                $params = [$id];
                if ($uploadedBefore !== null) {
                    $sql .= ' AND created_at < ?';
                    $params[] = $uploadedBefore->format('Y-m-d H:i:s');
                }
                if ($this->connection->executeStatement($sql, $params) === 1) {
                    $this->files->schedule('media', $row['storage_path']);
                    $removed[] = $id;
                }
            }

            return $removed;
        });
    }
}
