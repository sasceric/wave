<?php

namespace App\Service;

use App\Api\ProfileSlug;
use App\Localization\LocalizedRouteMap;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class PublicUrlSlugs
{
    public const ROUTES = ['creator' => 'creator-profile', 'company' => 'company-profile', 'campaign' => 'campaign-detail'];

    public function __construct(private readonly Connection $db, private readonly LocalizedRouteMap $routes) {}

    public function path(string $kind, string $slug): string
    {
        return $this->routes->localizedPath(self::ROUTES[$kind], 'bs', ['slug' => $slug]);
    }

    public function sources(string $kind, string $slug): array
    {
        return array_values(array_unique([$this->path($kind, $slug), ...$this->routes->legacyDetailPaths(self::ROUTES[$kind], $slug)]));
    }

    public function reserve(string $kind, string $name): string
    {
        $this->checkKind($kind);
        $base = ProfileSlug::fromName($name);
        for ($number = 0; $number < 100000; ++$number) {
            $slug = $base.($number === 0 ? '' : '-'.$number);
            if ($this->taken($kind, $slug)) continue;
            // An atomic, portable reservation also handles concurrent registrations.
            if ($this->db->executeStatement('INSERT INTO public_url_slug (kind, slug) VALUES (?, ?) ON CONFLICT (kind, slug) DO NOTHING', [$kind, $slug]) === 1) return $slug;
        }
        throw new \RuntimeException('No public URL is available for this name.');
    }

    public function remember(string $kind, string $slug): void
    {
        $this->checkKind($kind);
        $this->db->executeStatement('INSERT INTO public_url_slug (kind, slug) VALUES (?, ?) ON CONFLICT (kind, slug) DO NOTHING', [$kind, $slug]);
    }

    public function rememberHistoricalSources(): void
    {
        foreach ($this->db->fetchFirstColumn('SELECT source_path FROM url_redirect') as $path) {
            $route = $this->routes->resolve($path);
            if ($route === null || !$this->routes->isShared($route['name'])) continue;
            $kind = array_search($route['name'], self::ROUTES, true);
            if (is_string($kind)) $this->remember($kind, $route['params']['slug']);
        }
    }

    private function taken(string $kind, string $slug): bool
    {
        return (bool) $this->db->fetchOne("SELECT 1 FROM $kind WHERE slug = ?", [$slug])
            || (bool) $this->db->fetchOne('SELECT 1 FROM public_url_slug WHERE kind = ? AND slug = ?', [$kind, $slug])
            || (bool) $this->db->fetchOne('SELECT 1 FROM url_redirect WHERE source_path IN (:paths)', ['paths' => $this->sources($kind, $slug)], ['paths' => ArrayParameterType::STRING]);
    }

    private function checkKind(string $kind): void
    {
        if (!isset(self::ROUTES[$kind])) throw new \InvalidArgumentException('Unknown public URL kind.');
    }
}
