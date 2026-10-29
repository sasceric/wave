<?php

namespace App\Localization;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class LocalizedRouteMap
{
    private array $routes;
    private array $prefixes;
    private array $shared;

    public function __construct(#[Autowire('%kernel.project_dir%')] string $projectDir)
    {
        $contents = file_get_contents($projectDir.'/config/localized_routes.json');
        if ($contents === false) {
            throw new RuntimeException('Unable to read the localized route map.');
        }

        $routes = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($routes)) {
            throw new RuntimeException('The localized route map must contain a JSON object.');
        }

        $this->routes = $routes;
        $this->shared = json_decode(file_get_contents($projectDir.'/config/shared_public_routes.json'), true, flags: JSON_THROW_ON_ERROR);
        $prefixes = file_get_contents($projectDir.'/config/localized_route_prefixes.json');
        if ($prefixes === false) {
            throw new RuntimeException('Unable to read the localized route prefixes.');
        }
        $this->prefixes = json_decode($prefixes, true, flags: JSON_THROW_ON_ERROR);
    }

    public function locales(): array
    {
        return array_keys($this->routes);
    }

    public function localizedPath(string $routeName, string $locale, array $params = []): string
    {
        if (!isset($this->routes[$locale][$routeName])) {
            throw new InvalidArgumentException(sprintf('Unknown localized route "%s" for locale "%s".', $routeName, $locale));
        }

        $segment = $this->shared[$routeName] ?? $this->routes[$locale][$routeName];
        $prefix = ($this->isShared($routeName) || $this->prefixes[$locale] === '') ? '' : '/'.$this->prefixes[$locale];
        if ($segment === '') {
            return $prefix !== '' ? $prefix.'/' : '/';
        }

        $path = preg_replace_callback(
            '/:([A-Za-z0-9_]+)/',
            static function (array $match) use ($params): string {
                if (!array_key_exists($match[1], $params)) {
                    return $match[0];
                }

                return rawurlencode((string) $params[$match[1]]);
            },
            $segment,
        );

        return $prefix.'/'.$path;
    }

    public function isShared(string $name): bool { return isset($this->shared[$name]); }

    /** Previous locale paths remain valid redirect sources, never new canonical URLs. */
    public function legacyDetailPaths(string $name, string $slug): array
    {
        if (!$this->isShared($name)) return [];
        $paths = [];
        foreach ($this->routes as $locale => $routes) {
            foreach (array_unique([$this->prefixes[$locale], $locale === 'bs' ? '' : $locale]) as $prefix) {
                $paths[] = ($prefix === '' ? '' : '/'.$prefix).'/'.str_replace(':slug', rawurlencode($slug), $routes[$name]);
            }
        }
        $english = ['creator-profile' => 'creators', 'company-profile' => 'companies', 'campaign-detail' => 'campaigns'][$name];
        $paths[] = '/'.$english.'/'.rawurlencode($slug);
        return array_values(array_unique($paths));
    }

    public function localizedAlternates(string $routeName, array $params = []): array
    {
        if ($this->isShared($routeName)) return [];
        $alternates = [];
        foreach ($this->locales() as $locale) {
            $language = match ($locale) {
                'bs' => in_array($routeName, ['country-creators', 'country-topic'], true) ? 'bs-BA' : 'bs',
                'hr' => in_array($routeName, ['country-creators', 'country-topic'], true) ? 'hr-HR' : 'hr',
                'sl' => in_array($routeName, ['country-creators', 'country-topic'], true) ? 'sl-SI' : 'sl',
                'sr' => 'sr-RS',
                'cnr' => 'sr-ME',
                default => $locale,
            };
            $alternates[$language] = $this->localizedPath($routeName, $locale, $params);
        }
        $alternates['x-default'] = $this->localizedPath($routeName, 'bs', $params);

        return $alternates;
    }

    public function resolve(string $path): ?array
    {
        // Keep every old language-prefixed URL as a permanent alias, including
        // account, email and profile deep links. Locale identifiers stay unchanged.
        foreach ($this->prefixes as $locale => $prefix) {
            if ($locale !== $prefix && $locale !== 'bs' && ($path === '/'.$locale || str_starts_with($path, '/'.$locale.'/'))) {
                $path = '/'.$prefix.substr($path, strlen('/'.$locale));
                break;
            }
        }
        $path = $path !== '/' ? rtrim($path, '/') : $path;
        foreach ($this->routes as $locale => $routes) {
            foreach ($routes as $routeName => $segment) {
                $routePath = $this->localizedPath($routeName, $locale);
                if ($segment === '') {
                    if ($path === $routePath || $path === rtrim($routePath, '/')) {
                        return ['locale' => $locale, 'name' => $routeName, 'params' => []];
                    }
                    continue;
                }

                $pattern = '';
                foreach (preg_split('/(:[A-Za-z0-9_]+)/', $segment, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                    if (str_starts_with($part, ':')) {
                        $pattern .= '(?P<'.substr($part, 1).'>[^/]+)';
                    } else {
                        $pattern .= preg_quote($part, '~');
                    }
                }

                $prefix = $this->prefixes[$locale] === '' ? '' : '/'.preg_quote($this->prefixes[$locale], '~');
                if (preg_match('~^'.$prefix.'/'.$pattern.'$~', $path, $matches) !== 1) {
                    continue;
                }

                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = rawurldecode($value);
                    }
                }

                return ['locale' => $locale, 'name' => $routeName, 'params' => $params];
            }
        }

        return $this->resolveLegacyEnglishPath($path);
    }

    private function resolveLegacyEnglishPath(string $path): ?array
    {
        $legacyRoutes = [
            '/maintenance' => 'maintenance',
            '/account' => 'account',
            '/login' => 'login',
            '/register' => 'register',
            '/forgot-password' => 'forgot-password',
            '/account/applications' => 'account-applications',
            '/account/offers' => 'account-offers',
            '/account/inquiries' => 'account-inquiries',
            '/account/bookmarks' => 'account-bookmarks',
            '/account/invitations' => 'account-invitations',
            '/account/credits' => 'account-credits',
            '/account/campaigns' => 'account-campaigns',
            '/account/campaigns/new' => 'account-campaign-create',
            '/messages' => 'messages',
            '/verify-email' => 'verify-email',
            '/reset-password' => 'reset-password',
            '/creators' => 'creators',
            '/companies' => 'companies',
            '/campaigns' => 'campaigns',
            '/moderation' => 'moderation',
            '/admin' => 'admin',
            '/admin/registrations' => 'admin-registrations',
            '/admin/homepage' => 'admin-homepage',
            '/admin/creators' => 'admin-creators',
            '/admin/companies' => 'admin-companies',
            '/admin/campaigns' => 'admin-campaigns',
            '/admin/email-templates' => 'admin-email-templates',
            '/admin/subscribers' => 'admin-subscribers',
            '/admin/tools' => 'admin-tools',
            '/admin/credit-settings' => 'admin-credit-settings',
            '/admin/account-type-requests' => 'admin-account-types',
            '/admin/listing-ads' => 'admin-listing-ads',
            '/admin/redirects' => 'admin-redirects',
            '/imprint' => 'imprint',
            '/impressum' => 'imprint',
            '/privacy-policy' => 'privacy-policy',
            '/cookies' => 'cookie-policy',
        ];
        foreach (['creators' => 'creator-profile', 'companies' => 'company-profile', 'campaigns' => 'campaign-detail'] as $prefix => $routeName) {
            if (preg_match('~^/'.$prefix.'/([^/]+)$~', $path, $matches) === 1) {
                return [
                    'locale' => 'bs',
                    'name' => $routeName,
                    'params' => ['slug' => rawurldecode($matches[1])],
                ];
            }
        }
        if (!isset($legacyRoutes[$path]) && preg_match('~^/account/campaigns/([^/]+)$~', $path, $matches) === 1) {
            return ['locale' => 'bs', 'name' => 'account-campaign-detail', 'params' => ['slug' => rawurldecode($matches[1])]];
        }
        if (!isset($legacyRoutes[$path])) {
            return null;
        }

        return ['locale' => 'bs', 'name' => $legacyRoutes[$path], 'params' => []];
    }
}
