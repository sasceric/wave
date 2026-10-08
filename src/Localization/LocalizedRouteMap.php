<?php

namespace App\Localization;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class LocalizedRouteMap
{
    private array $routes;
    private array $prefixes;

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

        $segment = $this->routes[$locale][$routeName];
        $prefix = $this->prefixes[$locale] === '' ? '' : '/'.$this->prefixes[$locale];
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

    public function localizedAlternates(string $routeName, array $params = []): array
    {
        $alternates = [];
        foreach ($this->locales() as $locale) {
            $language = match ($locale) {
                'bs' => $routeName === 'country-creators' ? 'bs-BA' : 'bs',
                'hr' => $routeName === 'country-creators' ? 'hr-HR' : 'hr',
                'sl' => $routeName === 'country-creators' ? 'sl-SI' : 'sl',
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
            '/account' => 'account',
            '/messages' => 'messages',
            '/verify-email' => 'verify-email',
            '/reset-password' => 'reset-password',
            '/creators' => 'creators',
            '/companies' => 'companies',
            '/campaigns' => 'campaigns',
            '/moderation' => 'moderation',
            '/admin' => 'admin',
            '/admin/homepage' => 'admin-homepage',
            '/admin/creators' => 'admin-creators',
            '/admin/companies' => 'admin-companies',
            '/admin/campaigns' => 'admin-campaigns',
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
        if (!isset($legacyRoutes[$path])) {
            return null;
        }

        return ['locale' => 'bs', 'name' => $legacyRoutes[$path], 'params' => []];
    }
}
