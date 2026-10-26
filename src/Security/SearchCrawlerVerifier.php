<?php

namespace App\Security;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Confirms that a request claiming to be a search engine crawler really comes from
 * that search engine: reverse DNS must end in the engine's domain and resolve back to
 * the same IP (the method Google, Bing, Apple and Yandex document). Anyone can copy a
 * crawler's user agent, so the user agent alone never grants anything.
 */
class SearchCrawlerVerifier
{
    /** User-agent pattern => accepted reverse DNS suffixes. */
    private const CRAWLERS = [
        '/googlebot|google-inspectiontool|googleother|storebot-google|adsbot-google|mediapartners-google|apis-google/i' => ['.googlebot.com', '.google.com'],
        '/bingbot|bingpreview|msnbot|adidxbot/i' => ['.search.msn.com'],
        '/applebot/i' => ['.applebot.apple.com'],
        '/yandex(bot|images|mobilebot)/i' => ['.yandex.ru', '.yandex.net', '.yandex.com'],
    ];

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function claimsToBeCrawler(?string $userAgent): bool
    {
        return $this->suffixes($userAgent) !== null;
    }

    public function isVerified(?string $ip, ?string $userAgent): bool
    {
        $suffixes = $this->suffixes($userAgent);
        if ($suffixes === null || $ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // DNS lookups are slow; remember the answer per IP and engine for a day.
        return $this->cache->get('crawler_'.hash('sha256', $ip.'|'.implode(',', $suffixes)), function (ItemInterface $item) use ($ip, $suffixes): bool {
            $item->expiresAfter(86400);

            return $this->resolves($ip, $suffixes);
        });
    }

    /** @param list<string> $suffixes */
    protected function resolves(string $ip, array $suffixes): bool
    {
        $host = strtolower((string) @gethostbyaddr($ip));
        if ($host === '' || $host === $ip || !array_filter($suffixes, static fn (string $suffix): bool => str_ends_with($host, $suffix))) {
            return false;
        }
        $addresses = str_contains($ip, ':')
            ? array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
            : (@gethostbynamel($host) ?: []);

        return in_array(inet_pton($ip), array_map(static fn (string $address) => @inet_pton($address), $addresses), true);
    }

    /** @return list<string>|null */
    private function suffixes(?string $userAgent): ?array
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }
        foreach (self::CRAWLERS as $pattern => $suffixes) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $suffixes;
            }
        }

        return null;
    }
}
