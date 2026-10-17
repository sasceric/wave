<?php

namespace App\Search;

use App\Safety\BlockPolicy;
use Doctrine\DBAL\Connection;

final class PublicSearch
{
    public const TYPES = ['creators', 'campaigns', 'companies'];
    public function __construct(private readonly Meilisearch $meili, private readonly Connection $db, private readonly BlockPolicy $blocks, private readonly DatabaseSearch $database) {}

    public function search(string $term, string $locale, string $type = 'all', int $page = 1, bool $suggest = false): array
    {
        if (!in_array($type, ['all', ...self::TYPES], true) || $page < 1 || $page > 40_000_000 || mb_strlen($term) > 200) throw new \InvalidArgumentException('Invalid search request.');
        if (trim($term) === '') return ['data' => $suggest ? array_fill_keys(self::TYPES, []) : [], 'meta' => ['counts' => array_fill_keys(self::TYPES, 0), 'total' => 0, 'page' => $page, 'pageSize' => $suggest ? 10 : 25]];
        if (!$this->meili->enabled) return $this->database->search($term, $locale, $type, $page, $suggest);
        // Security fences only. No SQL matching, COUNT, result hydration, or directory fallback.
        $pending = $this->db->fetchFirstColumn('SELECT id FROM search_index_change LIMIT 5001');
        if (count($pending) > 5000) throw new \RuntimeException('Search is catching up.');
        $filter = ['locale = '.json_encode($locale), 'expiresAt >= '.time()];
        if ($pending) $filter[] = 'entityKey NOT IN '.json_encode($pending, JSON_THROW_ON_ERROR);
        $blocked = $this->blocks->ids();
        if ($blocked) $filter[] = 'ownerId NOT IN ['.implode(',', $blocked).']';
        $base = ['indexUid' => $this->meili->index(), 'q' => $term, 'filter' => $filter, 'page' => 1, 'hitsPerPage' => $suggest ? 10 : 0, 'matchingStrategy' => 'all'];
        $queries = [];
        foreach (self::TYPES as $category) $queries[] = array_replace($base, ['filter' => [...$filter, 'kind = "'.$category.'"']]);
        if (!$suggest) $queries[] = array_replace($base, ['filter' => $type === 'all' ? $filter : [...$filter, 'kind = "'.$type.'"'], 'page' => $page, 'hitsPerPage' => 25]);
        $response = $this->meili->request('POST', '/multi-search', ['queries' => $queries], search: true);
        $results = $response['results'] ?? [];
        if (count($results) !== count($queries)) throw new \RuntimeException('Incomplete search response.');
        $counts = $buckets = [];
        foreach (self::TYPES as $index => $category) {
            if (!isset($results[$index]['totalHits'])) throw new \RuntimeException('Exact search total unavailable.');
            $counts[$category] = (int) $results[$index]['totalHits'];
            $buckets[$category] = array_slice($results[$index]['hits'] ?? [], 0, 10);
        }
        $result = $suggest ? null : $results[3];
        return ['data' => $suggest ? $buckets : ($result['hits'] ?? []), 'meta' => ['counts' => $counts, 'total' => $suggest ? array_sum($counts) : (int) $result['totalHits'], 'page' => $page, 'pageSize' => $suggest ? 10 : 25]];
    }
}
