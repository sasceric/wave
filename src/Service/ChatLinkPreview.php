<?php

namespace App\Service;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ChatLinkPreview
{
    private HttpClientInterface $client;

    public function __construct(HttpClientInterface $httpClient, private readonly CacheInterface $cache)
    {
        $this->client = new NoPrivateNetworkHttpClient($httpClient);
    }

    public function firstUrl(string $body): ?string
    {
        preg_match_all('~https?://[^\s<>"\x27]+|(?<![\w@/])(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?\.)+[\p{L}]{2,63}(?:[/?\#][^\s<>"\x27]*)?~iu', $body, $matches);
        foreach ($matches[0] as $match) {
            $url = rtrim($match, '.,!?;:');
            foreach ([')' => '(', ']' => '[', '}' => '{'] as $close => $open) {
                while (str_ends_with($url, $close) && substr_count($url, $close) > substr_count($url, $open)) $url = substr($url, 0, -1);
            }
            $url = preg_match('~^https?://~i', $url) ? $url : 'https://'.$url;
            if ($this->validUrl($url)) return $url;
        }

        return null;
    }

    public function metadata(string $body): ?array
    {
        $url = $this->firstUrl($body);
        if ($url === null) return null;

        return $this->cache->get('chat_link.'.hash('sha256', $url), function (ItemInterface $item) use ($url): ?array {
            $item->expiresAfter(300);
            $page = $this->fetch($url, 524288, ['text/html', 'application/xhtml+xml']);
            if ($page === null || !$this->validUrl($page['url'])) return null;
            $document = new \DOMDocument();
            $errors = libxml_use_internal_errors(true);
            try {
                $document->loadHTML('<?xml encoding="UTF-8">'.$page['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($errors);
            }
            $xpath = new \DOMXPath($document);
            $title = $this->text($xpath->evaluate('string(//meta[@property="og:title"]/@content)') ?: $xpath->evaluate('string(//title)'), 160);
            $description = $this->text($xpath->evaluate('string(//meta[@property="og:description"]/@content)') ?: $xpath->evaluate('string(//meta[@name="description"]/@content)'), 300);
            $image = $this->absolute($this->text($xpath->evaluate('string(//meta[@property="og:image"]/@content)'), 2048), $page['url']);
            if ($title === '' && $description === '' && $image === null) return null;
            $item->expiresAfter(86400);

            return ['url' => $page['url'], 'domain' => parse_url($page['url'], PHP_URL_HOST), 'title' => $title, 'description' => $description, 'image' => $image];
        });
    }

    public function image(string $body): ?array
    {
        $url = $this->metadata($body)['image'] ?? null;
        if ($url === null) return null;

        return $this->cache->get('chat_link_image.'.hash('sha256', $url), function (ItemInterface $item) use ($url): ?array {
            $item->expiresAfter(300);
            $image = $this->fetch($url, 2097152, ['image/jpeg', 'image/png', 'image/webp']);
            if ($image === null) return null;
            $size = @getimagesizefromstring($image['body']);
            if ($size === false || $size[0] * $size[1] > 6_000_000 || !in_array($size['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
            $item->expiresAfter(86400);

            return ['body' => $image['body'], 'type' => $size['mime']];
        });
    }

    private function fetch(string $url, int $limit, array $types): ?array
    {
        try {
            $response = $this->client->request('GET', $url, [
                'timeout' => 2, 'max_duration' => 3, 'max_redirects' => 2, 'buffer' => false,
                'headers' => ['Accept' => implode(', ', $types), 'User-Agent' => 'WaveLinkPreview/1.0'],
            ]);
            $headers = $response->getHeaders(false);
            $type = strtolower(trim(explode(';', $headers['content-type'][0] ?? '')[0]));
            if ($response->getStatusCode() !== 200 || !in_array($type, $types, true) || (int) ($headers['content-length'][0] ?? 0) > $limit) {
                $response->cancel();
                return null;
            }
            $body = '';
            foreach ($this->client->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) > $limit) {
                    $response->cancel();
                    return null;
                }
            }

            return ['body' => $body, 'url' => $response->getInfo('url') ?: $url];
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface|\InvalidArgumentException) {
            return null;
        }
    }

    private function validUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || strlen($url) > 2048 || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) && !in_array($parts['port'], [80, 443], true)) return false;
        $host = trim($parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        return str_contains($host, '.') && !preg_match('~(?:^|\.)(?:localhost|local|internal|test|invalid)$~i', $host);
    }

    private function absolute(string $value, string $base): ?string
    {
        if ($value === '') return null;
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        $url = match (true) {
            str_starts_with($value, '//') => ($parts['scheme'] ?? 'https').':'.$value,
            preg_match('~^https?://~i', $value) === 1 => $value,
            str_starts_with($value, '/') => $origin.$value,
            str_contains($value, ':') => '',
            default => $origin.rtrim(dirname($parts['path'] ?? '/'), '/.').'/'.$value,
        };

        return $this->validUrl($url) ? $url : null;
    }

    private function text(mixed $value, int $limit): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? ''), 0, $limit);
    }
}
