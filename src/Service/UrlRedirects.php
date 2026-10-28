<?php

namespace App\Service;

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\UrlRedirect;
use App\Localization\LocalizedRouteMap;
use Doctrine\ORM\EntityManagerInterface;

final class UrlRedirects
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly LocalizedRouteMap $routes)
    {
    }

    /** Register the old public URLs before a profile is anonymized or a campaign removed. */
    public function deleted(Creator|Company|Campaign $entity): void
    {
        [$detail, $listing, $origin] = match (true) {
            $entity instanceof Creator => ['creator-profile', 'creators', 'deleted-creator'],
            $entity instanceof Company => ['company-profile', 'companies', 'deleted-company'],
            default => ['campaign-detail', 'campaigns', 'deleted-campaign'],
        };
        $sources = array_unique([$this->routes->localizedPath($detail, 'bs', ['slug' => $entity->getSlug()]), ...$this->routes->legacyDetailPaths($detail, $entity->getSlug())]);
        foreach ($sources as $source) {
            $locale = $this->routes->resolve($source)['locale'] ?? 'bs';
            // Keep any destination/disabled state an administrator already chose.
            if ($this->find($source) === null) {
                $target = $this->routes->localizedPath($listing, $locale);
                $redirect = new UrlRedirect($source, $target, $origin);
                try {
                    $this->validate($source, $target, true);
                } catch (\InvalidArgumentException) {
                    // An administrator's existing chain must not turn deletion
                    // into a loop or prevent the account from being erased.
                    $redirect->update($source, $target, false, false);
                }
                $this->em->persist($redirect);
            }
        }
    }

    public function path(string $path): string
    {
        if ($path === '' || strlen($path) > 500 || $path[0] !== '/' || str_starts_with($path, '//')
            || preg_match('/[\x00-\x20\x7f\\\\?#]/', $path) || preg_match('/%(?![a-f0-9]{2})/i', $path)) {
            throw new \InvalidArgumentException('Use a local path without query parameters.');
        }
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..' || preg_match('/[\x00-\x20\x7f\\\\\/?#%]/', $decoded)) {
                throw new \InvalidArgumentException('Invalid redirect path.');
            }
            $segments[] = rawurlencode($decoded);
        }
        $normalized = '/'.implode('/', $segments);
        $route = $this->routes->resolve($normalized);
        if ($route !== null && !$this->routes->isShared($route['name'])) {
            $normalized = $this->routes->localizedPath($route['name'], $route['locale'], $route['params']);
        }
        if (!$this->publicPath($normalized, $route['name'] ?? null)) {
            throw new \InvalidArgumentException('Account, admin, API and system paths cannot be redirected.');
        }

        return $normalized;
    }

    public function target(string $target): string
    {
        if (strlen($target) > 1000 || preg_match('/[\x00-\x20\x7f\\\\#]/', $target)) {
            throw new \InvalidArgumentException('Invalid redirect target.');
        }
        [$path, $query] = array_pad(explode('?', $target, 2), 2, null);

        return $this->path($path).($query !== null && $query !== '' ? '?'.$query : '');
    }

    /** Only the retired public profile-guide selector accepts a query-specific source. */
    public function source(string $source): string
    {
        if (strlen($source) > 500) throw new \InvalidArgumentException('Redirect source is too long.');
        [$path, $query] = array_pad(explode('?', $source, 2), 2, null);
        $path = $this->path($path);
        if ($query === null) return $path;
        if (preg_match('/^type=(creator|agency|brand)$/D', $query) !== 1
            || ($this->routes->resolve($path)['name'] ?? null) !== 'account-profile-guide') {
            throw new \InvalidArgumentException('Query-specific sources are limited to legacy profile guides.');
        }
        return $path.'?'.$query;
    }

    public function validate(string $source, string $target, bool $enabled, ?int $id = null): void
    {
        $existing = $this->find($source);
        if ($existing !== null && $existing->getId() !== $id) {
            throw new \InvalidArgumentException('A redirect already exists for this path.');
        }
        [$path, $query] = array_pad(explode('?', $target, 2), 2, null);
        if ($path === $source || $target === $source) {
            throw new \InvalidArgumentException('A redirect cannot point to itself.');
        }
        if (!$enabled) {
            return;
        }
        $seen = [$source => true];
        for ($i = 0; $i < 64; ++$i) {
            $key = $this->lookupKey($path, $query);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Redirect loop.');
            }
            $seen[$key] = true;
            $next = $this->matching($path, $query);
            if ($next === null || !$next->isEnabled()) {
                $route = $this->routes->resolve($path);
                if ($route !== null && $this->routes->isShared($route['name'])) {
                    $canonical = $this->routes->localizedPath($route['name'], 'bs', $route['params']);
                    if ($canonical !== $path) { $path = $canonical; continue; }
                }
                return;
            }
            if ($id !== null && $next->getId() === $id) return;
            [$path, $query] = array_pad(explode('?', $next->getTargetPath(), 2), 2, null);
        }
        throw new \InvalidArgumentException('Redirect chain is too long.');
    }

    /** Resolve chains in one response; unmatched query parameters are discarded by default. */
    public function resolve(string $path, ?string $query = null): ?string
    {
        // Authentication URLs are protected from editable public redirect rules.
        // These built-in legacy aliases still resolve on the first document
        // request and through the SPA redirect endpoint, without database rows.
        $route = $this->routes->resolve($path);
        if ($route !== null && in_array($route['name'], ['account', 'login', 'register', 'forgot-password'], true)) {
            parse_str($query ?? '', $parameters);
            $mode = $parameters['mode'] ?? null;
            $name = is_string($mode) ? (['login' => 'login', 'register' => 'register', 'reset' => 'forgot-password'][$mode] ?? null) : null;
            if ($name !== null) {
                unset($parameters['mode']);
                $target = $this->routes->localizedPath($name, $route['locale']);

                return $target.($parameters !== [] ? '?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986) : '');
            }
        }
        try {
            $path = $this->path($path);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $seen = [];
        $location = null;
        for ($i = 0; $i < 64; ++$i) {
            $key = $this->lookupKey($path, $query);
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            $redirect = $this->matching($path, $query);
            if ($redirect === null || !$redirect->isEnabled()) {
                $route = $this->routes->resolve($path);
                if ($route !== null && $this->routes->isShared($route['name'])) {
                    $canonical = $this->routes->localizedPath($route['name'], 'bs', $route['params']);
                    if ($canonical !== $path) { $path = $location = $canonical; continue; }
                }
                return $location;
            }
            try {
                $location = $this->target($redirect->getTargetPath());
            } catch (\InvalidArgumentException) {
                return null;
            }
            if ($redirect->preservesQuery() && $query !== null && $query !== '' && !preg_match('/[\x00-\x20\x7f#]/', $query)) {
                $location .= (str_contains($location, '?') ? '&' : '?').$query;
            }
            [$path, $query] = array_pad(explode('?', $location, 2), 2, null);
        }

        return null;
    }

    private function publicPath(string $path, ?string $name): bool
    {
        if (in_array($name, ['account-profile-guide', 'profile-guide-creator', 'profile-guide-agency', 'profile-guide-brand'], true)) {
            return true;
        }
        if ($name !== null && ($name === 'account' || str_starts_with($name, 'account-') || $name === 'admin' || str_starts_with($name, 'admin-')
            || in_array($name, ['maintenance', 'login', 'register', 'forgot-password', 'messages', 'verify-email', 'reset-password', 'moderation', 'support-create', 'support-track'], true))) {
            return false;
        }
        $unprefixed = preg_replace('~^/(?:hr|rs|me|si|en|de|sr|cnr|sl)(?=/)~', '', $path) ?? $path;

        return preg_match('~^/(?:api|admin|account|racun|messages|poruke|q|assets|build|images|media|uploads|fonts|_profiler|_wdt)(?:/|$)~', $unprefixed) !== 1
            && !str_contains($path, '.');
    }

    private function lookupKey(string $path, ?string $query): string
    {
        if (($this->routes->resolve($path)['name'] ?? null) === 'account-profile-guide') {
            parse_str($query ?? '', $parameters);
            $type = $parameters['type'] ?? null;
            if (is_string($type) && in_array($type, ['creator', 'agency', 'brand'], true)) return $path.'?type='.$type;
        }
        return $path;
    }

    private function matching(string $path, ?string $query): ?UrlRedirect
    {
        $key = $this->lookupKey($path, $query);
        return ($key !== $path ? $this->find($key) : null) ?? $this->find($path);
    }

    private function find(string $path, bool $enabledOnly = false): ?UrlRedirect
    {
        // Batch account deletion may add several connected rules before flush.
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof UrlRedirect && $entity->getSourcePath() === $path) {
                return !$enabledOnly || $entity->isEnabled() ? $entity : null;
            }
        }
        $row = $this->em->getRepository(UrlRedirect::class)->findOneBy(['sourcePath' => $path]);

        return $row !== null && (!$enabledOnly || $row->isEnabled()) ? $row : null;
    }
}
