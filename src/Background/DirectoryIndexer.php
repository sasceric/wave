<?php

namespace App\Background;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

#[\Monolog\Attribute\WithMonologChannel('background')]
final class DirectoryIndexer
{
    public function __construct(private readonly Connection $connection, private readonly JobDispatcher $jobs, private readonly LoggerInterface $logger)
    {
    }

    public function index(string $kind, array $ids): void
    {
        if (!in_array($kind, ['creator', 'company', 'campaign'], true) || count($ids) > 100) {
            throw new \InvalidArgumentException('Invalid indexing batch.');
        }
        foreach ($ids as $id) {
            $id = (int) $id;
            $this->connection->transactional(function () use ($kind, $id): void {
                $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['wave.index.' . $kind . ':' . $id]);
                $row = $this->connection->fetchAssociative('SELECT * FROM ' . $kind . ' WHERE id = ?', [$id]);
                if (!$row) {
                    $this->connection->delete('directory_index', ['id' => $kind . ':' . $id]);

                    return;
                }
                $tags = $kind === 'creator' ? json_decode($row['tags'], true, flags: JSON_THROW_ON_ERROR) : [];
                $profiles = $kind === 'creator' ? json_decode($row['social_profiles'], true, flags: JSON_THROW_ON_ERROR) : [];
                $platforms = array_values(array_unique(array_map(static fn ($profile): string => mb_strtolower((string) ($profile['platform'] ?? '')), $profiles)));
                $count = $kind === 'company' ? (int) $this->connection->fetchOne("SELECT COUNT(*) FROM campaign WHERE company_id = ? AND status = 'open' AND closes_at >= ?", [$id, (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s')]) : null;
                $fields = $kind === 'creator' ? ['display_name', 'bio', 'location', 'category'] : ($kind === 'company' ? ['name', 'industry', 'about'] : ['title', 'summary', 'description', 'category', 'location']);
                $text = implode(' ', array_map(static fn ($field): string => (string) ($row[$field] ?? ''), $fields));
                $this->connection->executeStatement('INSERT INTO directory_index (id, kind, entity_id, search_text, platform_keys, tag_text, available_campaign_count, indexed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (id) DO UPDATE SET search_text = EXCLUDED.search_text, platform_keys = EXCLUDED.platform_keys, tag_text = EXCLUDED.tag_text, available_campaign_count = EXCLUDED.available_campaign_count, indexed_at = EXCLUDED.indexed_at', [$kind . ':' . $id, $kind, $id, mb_strtolower($text), json_encode($platforms, JSON_THROW_ON_ERROR), mb_strtolower(json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), $count, gmdate('Y-m-d H:i:s')]);
            });
        }
        $this->logger->info('indexing.batch.completed', ['kind' => $kind, 'count' => count($ids)]);
    }

    public function rebuild(string $kind = 'creator', int $after = 0, ?int $upper = null): void
    {
        $kinds = ['creator' => 'CreatorIndexingMessage', 'campaign' => 'CampaignIndexingMessage', 'company' => 'CompanyIndexingMessage'];
        if (!isset($kinds[$kind])) {
            throw new \InvalidArgumentException('Unknown catalog.');
        }
        $upper ??= (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM ' . $kind);
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM ' . $kind . ' WHERE id > ? AND id <= ? ORDER BY id LIMIT 100', [$after, $upper]);
        if ($ids !== []) {
            $this->jobs->enqueue($kinds[$kind], ['ids' => array_map('intval', $ids)]);
        }
        if (count($ids) === 100) {
            $this->jobs->enqueue('IndexReconcileTask', ['kind' => $kind, 'after' => (int) end($ids), 'upper' => $upper]);
        } elseif ($kind !== 'company') {
            $this->jobs->enqueue('IndexReconcileTask', ['kind' => $kind === 'creator' ? 'campaign' : 'company']);
        }
    }
}
