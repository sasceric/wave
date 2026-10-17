<?php

namespace App\Search;

use App\Safety\BlockPolicy;
use Doctrine\DBAL\Connection;

/** Explicit database backend when MEILISEARCH_ENABLED=false, never a hidden outage fallback. */
final class DatabaseSearch
{
    public function __construct(private readonly Connection $db, private readonly SearchDocuments $documents, private readonly BlockPolicy $blocks) {}

    public function search(string $term, string $locale, string $type, int $page, bool $suggest): array
    {
        $visible = '(u.id IS NULL OR (u.approved = TRUE AND u.hide_my_account = FALSE AND u.deleted_at IS NULL)) AND '.$this->blocks->visible('u.id');
        $sources = [
            "SELECT p.id, 'creators' AS kind, p.display_name AS title FROM creator p LEFT JOIN wave_user u ON u.id = p.owner_id WHERE p.agency_profile = FALSE AND $visible AND LOWER(p.display_name || ' ' || p.bio || ' ' || COALESCE(u.city, p.city, '') || ' ' || CAST(p.categories AS TEXT) || ' ' || CAST(p.tags AS TEXT) || ' ' || CAST(p.translations AS TEXT)) LIKE :query ESCAPE '!'",
            "SELECT p.id, 'companies' AS kind, p.name AS title FROM company p LEFT JOIN wave_user u ON u.id = p.owner_id WHERE $visible AND LOWER(p.name || ' ' || COALESCE(p.about, '') || ' ' || p.industry || ' ' || COALESCE(u.city, '') || ' ' || CAST(p.services AS TEXT) || ' ' || CAST(p.translations AS TEXT)) LIKE :query ESCAPE '!'",
            "SELECT p.id, 'campaigns' AS kind, p.title FROM campaign p JOIN company c ON c.id = p.company_id LEFT JOIN wave_user u ON u.id = c.owner_id WHERE p.status = 'open' AND p.closes_at >= :today AND $visible AND LOWER(p.title || ' ' || p.summary || ' ' || p.description || ' ' || p.city || ' ' || c.name || ' ' || CAST(p.categories AS TEXT) || ' ' || CAST(p.translations AS TEXT)) LIKE :query ESCAPE '!'",
        ];
        $sql = implode(' UNION ALL ', $sources);
        $params = ['query' => '%'.strtr(mb_strtolower($term), ['!' => '!!', '%' => '!%', '_' => '!_']).'%', 'today' => (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s')];
        $counts = array_fill_keys(PublicSearch::TYPES, 0);
        foreach ($this->db->fetchAllAssociative('SELECT kind, COUNT(*) AS total FROM ('.$sql.') matches GROUP BY kind', $params) as $row) $counts[$row['kind']] = (int) $row['total'];
        $labels = $this->documents->labels($locale);
        $read = function (string $category, int $limit, int $offset = 0) use ($sql, $params, $labels, $locale): array {
            $filter = $category === 'all' ? '' : " WHERE kind = '$category'";
            $rows = $this->db->fetchAllAssociative('SELECT id, kind FROM ('.$sql.') matches'.$filter.' ORDER BY LOWER(title), kind, id LIMIT '.$limit.' OFFSET '.$offset, $params);
            $hits = [];
            foreach ($rows as $row) {
                $kind = array_search($row['kind'], SearchDocuments::TYPES, true);
                if ($kind === false) continue;
                $documents = $this->documents->build($kind, (int) $row['id'], $labels, $locale);
                if ($documents) $hits[] = array_intersect_key($documents[0], array_flip(['id', 'entityKey', 'kind', 'slug', 'title', 'subtitle', 'summary', 'location', 'image', 'imageUrl', 'isPresentationDemo', 'budgetMin', 'budgetMax', 'currency']));
            }
            return $hits;
        };
        $data = [];
        if ($suggest) foreach (PublicSearch::TYPES as $category) $data[$category] = $read($category, 10);
        else $data = $read($type, 25, ($page - 1) * 25);
        return ['data' => $data, 'meta' => ['counts' => $counts, 'total' => $type === 'all' || $suggest ? array_sum($counts) : $counts[$type], 'page' => $page, 'pageSize' => $suggest ? 10 : 25]];
    }
}
