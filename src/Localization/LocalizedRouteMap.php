<?php

namespace App\Localization;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class LocalizedRouteMap
{
    private array $routes;

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
        $prefix = $locale === 'bs' ? '' : '/'.$locale;
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
                'sr' => 'sr-Latn',
                'cnr' => 'cnr-Latn-ME',
                default => $locale,
            };
            $alternates[$language] = $this->localizedPath($routeName, $locale, $params);
        }
        $alternates['x-default'] = $this->localizedPath($routeName, 'bs', $params);

        return $alternates;
    }

    public function resolve(string $path): ?array
    {
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

                $prefix = $locale === 'bs' ? '' : '/'.preg_quote($locale, '~');
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
