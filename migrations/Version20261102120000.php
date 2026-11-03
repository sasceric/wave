<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Localization\LocalizedRouteMap;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102120000 extends AbstractMigration
{
    /** Rows written automatically; manual rules are never touched. */
    private const GENERATED = ['url-change', 'url-unified', 'url-repaired', 'deleted-creator', 'deleted-company', 'deleted-campaign'];

    public function getDescription(): string
    {
        return 'Fold generated language-prefixed profile, company and campaign redirects into their canonical row.';
    }

    public function up(Schema $schema): void {}

    public function postUp(Schema $schema): void
    {
        if (!$schema->hasTable('url_redirect')) return;
        $routes = new LocalizedRouteMap(dirname(__DIR__));
        $rows = $this->connection->fetchAllAssociative('SELECT source_path, target_path, enabled, origin FROM url_redirect ORDER BY source_path');
        // Source => enabled. A disabled canonical rule must keep its prefixed aliases.
        $sources = array_column(array_map(static fn (array $r): array => [$r['source_path'], (bool) $r['enabled']], $rows), 1, 0);
        foreach ($rows as $row) {
            if (!in_array($row['origin'], self::GENERATED, true) || !(bool) $row['enabled']) continue;
            $route = $routes->resolve($row['source_path']);
            if ($route === null || !$routes->isShared($route['name'])) continue;
            $canonical = $routes->localizedPath($route['name'], 'bs', $route['params']);
            if ($canonical === $row['source_path']) continue;
            // UrlRedirects::resolve() folds this path into the canonical one, so the
            // row is redundant when it only canonicalizes or a canonical row exists.
            $target = explode('?', (string) $row['target_path'], 2)[0];
            if ($target === $canonical || ($sources[$canonical] ?? false) === true) {
                $this->connection->delete('url_redirect', ['source_path' => $row['source_path']]);
                unset($sources[$row['source_path']]);
                continue;
            }
            if (isset($sources[$canonical])) continue;
            // The first prefixed row without a canonical row becomes that row.
            $this->connection->update('url_redirect', ['source_path' => $canonical], ['source_path' => $row['source_path']]);
            unset($sources[$row['source_path']]);
            $sources[$canonical] = true;
        }
    }

    public function down(Schema $schema): void
    {
        // The removed rows were redundant aliases; every old URL still resolves.
    }
}
