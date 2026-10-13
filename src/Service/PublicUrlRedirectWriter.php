<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class PublicUrlRedirectWriter
{
    public function __construct(private readonly Connection $db, private readonly PublicUrlSlugs $slugs) {}

    /** Collapse older redirects to the latest URL, preserving settings of incoming manual rules. */
    public function changed(string $kind, string $old, string $new, string $origin = 'url-change'): void
    {
        $sources = $this->slugs->sources($kind, $old);
        $target = $this->slugs->path($kind, $new);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        foreach ($this->db->fetchAllAssociative('SELECT id, target_path FROM url_redirect') as $row) {
            [$path, $query] = array_pad(explode('?', $row['target_path'], 2), 2, null);
            if (in_array($path, $sources, true)) {
                $this->db->update('url_redirect', ['target_path' => $target.($query === null ? '' : '?'.$query), 'updated_at' => $now], ['id' => $row['id']]);
            }
        }
        foreach ($sources as $source) {
            if ($source === $target) continue;
            $existing = $this->db->fetchAssociative('SELECT id, origin FROM url_redirect WHERE source_path = ?', [$source]);
            if ($existing !== false) {
                if (in_array($existing['origin'], ['url-change', 'url-unified'], true)) $this->db->update('url_redirect', ['target_path' => $target, 'updated_at' => $now], ['id' => $existing['id']]);
                continue;
            }
            $this->db->insert('url_redirect', ['source_path' => $source, 'target_path' => $target, 'enabled' => true, 'preserve_query' => false, 'origin' => $origin, 'created_at' => $now, 'updated_at' => $now], ['enabled' => \Doctrine\DBAL\ParameterType::BOOLEAN, 'preserve_query' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
        }
    }
}
