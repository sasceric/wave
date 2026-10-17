<?php

namespace App\Search;

use App\Background\JobDispatcher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use App\Localization\LocalizedRouteMap;

final class SearchIndexer
{
    public function __construct(private readonly Meilisearch $meili, private readonly SearchDocuments $documents, private readonly Connection $db, private readonly EntityManagerInterface $em, private readonly JobDispatcher $jobs, private readonly LocalizedRouteMap $routes) {}

    public function mark(string $kind, int $id): void
    {
        $this->lock($kind, $id);
        $this->db->executeStatement('INSERT INTO search_index_change (id, kind, entity_id, revision) VALUES (?, ?, ?, ?) ON CONFLICT (id) DO UPDATE SET revision = EXCLUDED.revision', [$kind.'_'.$id, $kind, $id, bin2hex(random_bytes(16))]);
    }

    private function lock(string $kind, int $id): void
    {
        $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['wave.search.'.$kind.'_'.$id]);
    }

    public function index(string $kind, array $ids): void
    {
        if (!$this->meili->enabled) return;
        if (!isset(SearchDocuments::CLASSES[$kind]) || count($ids) > 100 || array_filter($ids, static fn ($id): bool => !is_int($id) || $id < 1)) throw new \InvalidArgumentException('Invalid search batch.');
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        $this->em->clear();
        $this->db->transactional(function () use ($kind, $ids): void {
            foreach ($ids as $id) $this->lock($kind, $id);
            $labels = $this->documents->labels();
            $upserts = $deletes = [];
            foreach ($ids as $id) {
                $rows = $this->documents->build($kind, $id, $labels);
                if ($rows) array_push($upserts, ...$rows);
                else foreach ($this->routes->locales() as $locale) $deletes[] = $kind.'_'.$id.'_'.$locale;
            }
            $base = '/indexes/'.$this->meili->index();
            if ($deletes) $this->meili->wait($this->meili->request('POST', $base.'/documents/delete-batch', $deletes));
            if ($upserts) $this->meili->wait($this->meili->request('POST', $base.'/documents?primaryKey=id', $upserts));
            // Same advisory locks as profile writes: a newer change cannot be acknowledged accidentally.
            foreach ($ids as $id) $this->db->delete('search_index_change', ['id' => $kind.'_'.$id]);
        });
    }

    /** Each invocation handles at most 100 rows, then queues one continuation. */
    public function reconcile(array $payload = [], bool $queue = true): ?array
    {
        if (!$this->meili->enabled) return null;
        if (!$payload) $this->meili->configure();
        $kind = $payload['kind'] ?? 'creator';
        if (!isset(SearchDocuments::CLASSES[$kind])) throw new \InvalidArgumentException('Invalid search catalog.');
        $after = (int) ($payload['after'] ?? 0);
        $phase = $payload['phase'] ?? 'source';
        if ($phase === 'source') {
            $upper = (int) ($payload['upper'] ?? $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM '.$kind));
            $ids = array_map(intval(...), $this->db->fetchFirstColumn('SELECT id FROM '.$kind.' WHERE id > ? AND id <= ? ORDER BY id LIMIT 100', [$after, $upper]));
        } else {
            // Numeric keyset sweep also repairs hard-deleted records without offset/deletion skips.
            $response = $this->meili->request('POST', '/indexes/'.$this->meili->index().'/search', ['q' => '', 'filter' => ['locale = "bs"', 'kind = "'.SearchDocuments::TYPES[$kind].'"', 'entityId > '.$after], 'sort' => ['entityId:asc'], 'limit' => 100, 'attributesToRetrieve' => ['id']]);
            $ids = array_map(static fn (array $row): int => (int) explode('_', $row['id'])[1], $response['hits']);
            $upper = null;
        }
        if ($ids) $this->index($kind, $ids);
        if (count($ids) === 100) {
            $next = ['kind' => $kind, 'phase' => $phase, 'after' => max($ids)];
            if ($upper !== null) $next['upper'] = $upper;
        } elseif ($phase === 'source') {
            $next = ['kind' => $kind, 'phase' => 'prune'];
        } else {
            $kinds = array_keys(SearchDocuments::CLASSES);
            $position = array_search($kind, $kinds, true);
            $next = isset($kinds[$position + 1]) ? ['kind' => $kinds[$position + 1]] : null;
        }
        if ($next !== null && $queue) $this->jobs->enqueue('SearchReconcileTask', $next);
        return $next;
    }
}
