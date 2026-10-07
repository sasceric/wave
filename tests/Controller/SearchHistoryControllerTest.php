<?php

namespace App\Tests\Controller;

use App\Background\SafeLogProcessor;
use App\Controller\Api\SearchHistoryController;
use App\Service\AdminLogReader;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SearchHistoryControllerTest extends TestCase
{
    public function testSubmittedSearchCreatesDailyFileReadableInAdminTools(): void
    {
        $directory = sys_get_temp_dir() . '/wave-search-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $handler = new RotatingFileHandler($directory . '/search.log', 30);
        $handler->setFormatter(new JsonFormatter());
        $logger = new Logger('search', [$handler], [new SafeLogProcessor(new RequestStack())]);
        try {
            $response = $this->record(['type' => 'campaigns', 'query' => '  Ljetna kampanja  ', 'source' => 'submit'], $logger);
            self::assertSame(200, $response->getStatusCode());
            $handler->close();
            $reader = new AdminLogReader($directory, new ArrayAdapter());
            $files = $reader->files()['files'];
            self::assertCount(1, $files);
            self::assertMatchesRegularExpression('/^search-\d{4}-\d{2}-\d{2}\.log$/', $files[0]['name']);
            $rows = $reader->page($files[0]['name'], '', 25)['data'];
            self::assertCount(1, $rows);
            self::assertSame('Search campaigns: Ljetna kampanja', $rows[0]['message']);
            self::assertSame('search', $rows[0]['channel']);
            self::assertSame('en', json_decode($rows[0]['detail'], true, flags: JSON_THROW_ON_ERROR)['context']['locale']);
            self::assertStringNotContainsString('127.0.0.1', $rows[0]['detail']);
            $this->record(['type' => 'creators', 'query' => 'secret@example.com', 'source' => 'all'], $logger);
            $handler->close();
            self::assertStringNotContainsString('secret@example.com', file_get_contents($directory . '/' . $files[0]['name']));
        } finally {
            $handler->close();
            (new Filesystem())->remove($directory);
        }
    }

    public function testInvalidSubmissionsAndMissingCsrfNeverReachLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');
        foreach ([
            ['type' => 'users', 'query' => 'test', 'source' => 'all'],
            ['type' => 'creators', 'query' => '', 'source' => 'all'],
            ['type' => 'creators', 'query' => str_repeat('a', 201), 'source' => 'all'],
            ['type' => 'creators', 'query' => "test\nforged entry", 'source' => 'all'],
            ['type' => 'creators', 'query' => 'test', 'source' => 'suggestion'],
        ] as $data) {
            self::assertSame(400, $this->record($data, $logger)->getStatusCode());
        }
        $valid = ['type' => 'companies', 'query' => 'Natura', 'source' => 'all'];
        self::assertSame(403, $this->record($valid, $logger, csrfValid: false)->getStatusCode());
        self::assertSame(429, $this->record($valid, $logger, allowed: false)->getStatusCode());
        self::assertSame(400, $this->record($valid, $logger, locale: 'invalid')->getStatusCode());
    }

    private function record(array $data, LoggerInterface $logger, bool $csrfValid = true, bool $allowed = true, string $locale = 'en'): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $request = Request::create('/api/search/history?locale=' . $locale, 'POST', server: ['HTTP_X_CSRF_TOKEN' => 'test', 'REMOTE_ADDR' => '127.0.0.1'], content: json_encode($data, JSON_THROW_ON_ERROR));
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);
        $limit = $this->createStub(LimiterInterface::class);
        $limit->method('consume')->willReturn(new RateLimit(29, new \DateTimeImmutable(), $allowed, 30));
        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limit);

        return (new SearchHistoryController())->record($request, $csrf, $factory, $logger);
    }
}
