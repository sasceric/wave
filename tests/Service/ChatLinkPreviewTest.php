<?php

namespace App\Tests\Service;

use App\Service\ChatLinkPreview;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ChatLinkPreviewTest extends TestCase
{
    public function testBareDomainsAndSubdomainsAreRecognizedWithoutLinkingEmails(): void
    {
        $preview = new ChatLinkPreview(new MockHttpClient(), new ArrayAdapter());
        self::assertSame('https://wave.ba', $preview->firstUrl('wave.ba'));
        self::assertSame('https://app.wave.ba/test', $preview->firstUrl('Visit app.wave.ba/test.'));
        self::assertNull($preview->firstUrl('name@example.com'));
        self::assertSame('https://wave.ba/a_(b)', $preview->firstUrl('(https://wave.ba/a_(b)).'));
    }

    public function testMetadataAndSafeImageAreFetchedOnceAndCached(): void
    {
        $requests = [];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, $png): MockResponse {
            $requests[] = $url;
            self::assertSame(3.0, $options['max_duration']);
            return str_ends_with($url, '.png')
                ? new MockResponse($png, ['response_headers' => ['content-type: image/png']])
                : new MockResponse('<html><head><title>Fallback</title><meta property="og:title" content="Wave &amp; creators"><meta name="description" content="Build brands together."><meta property="og:image" content="/cover.png"></head></html>', ['response_headers' => ['content-type: text/html; charset=utf-8']]);
        });
        $preview = new ChatLinkPreview($http, new ArrayAdapter());
        $data = $preview->metadata('https://93.184.216.34/page');
        self::assertSame('Wave & creators', $data['title']);
        self::assertSame('Build brands together.', $data['description']);
        self::assertSame('https://93.184.216.34/cover.png', $data['image']);
        self::assertSame($data, $preview->metadata('https://93.184.216.34/page'));
        self::assertSame('image/png', $preview->image('https://93.184.216.34/page')['type']);
        self::assertSame($png, $preview->image('https://93.184.216.34/page')['body']);
        self::assertCount(2, $requests);
    }

    public function testPrivateTargetsAndRedirectsAreRejectedAndFailuresAreCached(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;
            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['location: http://127.0.0.1/private']]);
        });
        $preview = new ChatLinkPreview($http, new ArrayAdapter());
        foreach (['http://127.0.0.1/', 'http://169.254.169.254/', 'http://[::1]/', 'https://wave.local/', 'https://user:pass@93.184.216.34/'] as $url) {
            self::assertNull($preview->metadata($url));
        }
        self::assertSame(0, $requests);
        self::assertNull($preview->metadata('https://93.184.216.34/redirect'));
        self::assertNull($preview->metadata('https://93.184.216.34/redirect'));
        self::assertSame(1, $requests);
    }

    public function testOversizedPagesAndNonHtmlResponsesDoNotGenerateCards(): void
    {
        $preview = new ChatLinkPreview(new MockHttpClient([
            new MockResponse(str_repeat('a', 524289), ['response_headers' => ['content-type: text/html']]),
            new MockResponse('<title>Not a page</title>', ['response_headers' => ['content-type: application/json']]),
        ]), new ArrayAdapter());
        self::assertNull($preview->metadata('https://93.184.216.34/large'));
        self::assertNull($preview->metadata('https://93.184.216.34/json'));
    }
}
