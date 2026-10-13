<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Upgrade generated hash suffixes; existing deliberate custom URLs stay unchanged. */
final class UnifyPublicUrls
{
    public function __construct(private readonly Connection $db, private readonly PublicUrlSlugs $slugs, private readonly PublicUrlRedirectWriter $writer) {}
    public function run(): void
    {
        foreach (array_keys(PublicUrlSlugs::ROUTES) as $kind) {
            foreach ($this->db->fetchFirstColumn("SELECT slug FROM $kind") as $slug) $this->slugs->remember($kind, $slug);
        }
        $this->slugs->rememberHistoricalSources();
        foreach (['creator' => 'display_name', 'company' => 'name', 'campaign' => 'title'] as $kind => $column) {
            $join = $kind === 'campaign' ? 'JOIN company c ON c.id = p.company_id LEFT JOIN wave_user u ON u.id = c.owner_id' : 'LEFT JOIN wave_user u ON u.id = p.owner_id';
            foreach ($this->db->fetchAllAssociative("SELECT p.id, p.slug, p.$column AS name FROM $kind p $join WHERE u.deleted_at IS NULL ORDER BY p.id") as $row) {
                $old = $row['slug'];
                $new = preg_match('/-[a-f0-9]{6}$/D', $old) ? $this->slugs->reserve($kind, $row['name']) : $old;
                if ($old !== $new) $this->db->update($kind, ['slug' => $new], ['id' => $row['id']]);
                $this->writer->changed($kind, $old, $new, 'url-unified');
                if ($old !== $new) $this->writer->changed($kind, $new, $new, 'url-unified');
            }
        }
    }
}
