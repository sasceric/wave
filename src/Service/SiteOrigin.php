<?php

namespace App\Service;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SiteOrigin
{
    private string $origin;

    public function __construct(#[Autowire('%env(APP_BASE_URL)%')] string $appBaseUrl)
    {
        $origin = rtrim($appBaseUrl, '/');
        $parsedOrigin = parse_url($origin);
        if (!is_array($parsedOrigin)
            || !isset($parsedOrigin['scheme'], $parsedOrigin['host'])
            || !in_array($parsedOrigin['scheme'], ['http', 'https'], true)
            || isset($parsedOrigin['path']) && $parsedOrigin['path'] !== ''
        ) {
            throw new RuntimeException('APP_BASE_URL must be an absolute HTTP(S) origin without a path.');
        }

        $this->origin = $origin;
    }

    public function base(): string
    {
        return $this->origin;
    }

    public function url(string $path): string
    {
        return $this->origin.'/'.ltrim($path, '/');
    }
}
