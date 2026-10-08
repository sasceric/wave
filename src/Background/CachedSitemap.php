<?php

namespace App\Background;

use App\Localization\LocalizedRouteMap;
use App\Service\SiteOrigin;
use App\Service\RegionalSeoContent;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final class CachedSitemap
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LocalizedRouteMap $routes,
        private readonly SiteOrigin $origin,
        private readonly JobDispatcher $jobs,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/var/sitemaps')] private readonly string $directory,
        private readonly RegionalSeoContent $regionalContent,
    ) {
    }

    public function index(): ?string
    {
        $path = $this->directory . '/sitemap.xml';
        if (!is_file($path)) {
            $this->jobs->enqueue('SitemapGenerateTask', [], 'sitemap:' . intdiv(time(), 300));

            return null;
        }

        return $path;
    }

    public function part(string $name): ?string
    {
        if (preg_match('/^[a-f0-9]{32}-[0-9]{1,5}\.xml$/D', $name) !== 1) {
            return null;
        }
        $path = $this->directory . '/' . $name;

        return is_file($path) && !is_link($path) ? $path : null;
    }

    public function generate(): void
    {
        $this->filesystem->mkdir($this->directory);
        $lock = fopen($this->directory . '/.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot lock sitemap generation.');
        }
        $parts = [];
        $handle = null;
        $published = false;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Sitemap generation is already active.');
            }
            $version = bin2hex(random_bytes(16));
            $count = $bytes = 0;
            foreach ($this->pages() as [$route, $params]) {
                foreach ($this->routes->locales() as $locale) {
                    $xml = '<url><loc>' . $this->escape($this->origin->url($this->routes->localizedPath($route, $locale, $params))) . '</loc>';
                    foreach ($this->routes->localizedAlternates($route, $params) as $language => $path) {
                        $xml .= '<xhtml:link rel="alternate" hreflang="' . $this->escape($language) . '" href="' . $this->escape($this->origin->url($path)) . '"/>';
                    }
                    $xml .= '</url>';
                    if ($handle === null || $count >= 49000 || $bytes + strlen($xml) > 45000000) {
                        if ($handle !== null) {
                            $this->put($handle, '</urlset>');
                            fclose($handle);
                        }
                        $name = $version . '-' . count($parts) . '.xml';
                        $parts[] = $name;
                        $handle = fopen($this->directory . '/' . $name, 'xb');
                        if ($handle === false) {
                            throw new \RuntimeException('Cannot create sitemap part.');
                        }
                        $this->put($handle, '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">');
                        $count = $bytes = 0;
                    }
                    $this->put($handle, $xml);
                    ++$count;
                    $bytes += strlen($xml);
                }
            }
            if ($handle !== null) {
                $this->put($handle, '</urlset>');
                fclose($handle);
                $handle = null;
            }
            $xml = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($parts as $part) {
                $xml .= '<sitemap><loc>' . $this->escape($this->origin->url('/sitemaps/' . $part)) . '</loc></sitemap>';
            }
            $this->filesystem->dumpFile($this->directory . '/sitemap.xml', $xml . '</sitemapindex>');
            $published = true;
            // Old index readers keep their immutable parts for two days.
            foreach (new \DirectoryIterator($this->directory) as $file) {
                if ($file->isFile() && !$file->isLink() && preg_match('/^[a-f0-9]{32}-[0-9]+\.xml$/D', $file->getFilename()) && $file->getMTime() < time() - 172800) {
                    $this->filesystem->remove($file->getPathname());
                }
            }
        } catch (\Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!$published) {
                foreach ($parts as $part) {
                    $this->filesystem->remove($this->directory . '/' . $part);
                }
            }
            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function pages(): \Generator
    {
        yield from $this->regionalContent->extraPages();
        foreach (['home', 'creators', 'companies', 'campaigns', 'imprint', 'privacy-policy', 'cookie-policy'] as $route) {
            yield [$route, []];
        }
        foreach (['creator' => 'creator-profile', 'company' => 'company-profile', 'campaign' => 'campaign-detail'] as $kind => $route) {
            $after = 0;
            $upper = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM ' . $kind);
            do {
                $join = $kind === 'campaign' ? 'JOIN company c ON c.id = e.company_id LEFT JOIN wave_user u ON u.id = c.owner_id' : 'LEFT JOIN wave_user u ON u.id = e.owner_id';
                $filter = $kind === 'campaign' ? " AND e.status = 'open' AND e.closes_at >= ?" : '';
                $params = [$after, $upper];
                if ($kind === 'campaign') {
                    $params[] = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
                }
                $rows = $this->connection->fetchAllAssociative('SELECT e.id, e.slug FROM ' . $kind . ' e ' . $join . ' WHERE e.id > ? AND e.id <= ? AND (u.id IS NULL OR (u.approved = TRUE AND u.hide_my_account = FALSE))' . $filter . ' ORDER BY e.id LIMIT 500', $params);
                foreach ($rows as $row) {
                    $after = (int) $row['id'];
                    yield [$route, ['slug' => $row['slug']]];
                }
            } while (count($rows) === 500);
        }
    }

    private function put(mixed $handle, string $value): void
    {
        if (fwrite($handle, $value) !== strlen($value)) {
            throw new \RuntimeException('Sitemap storage is full.');
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
