<?php

namespace App\Tests\Search;

use App\Command\SearchKeysCommand;
use App\Search\Meilisearch;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearchKeysCommandTest extends TestCase
{
    public function testScopedKeysAreSavedPrivatelyAndNeverPrinted(): void
    {
        $path = sys_get_temp_dir().'/wave-key-fixture-'.bin2hex(random_bytes(8)).'.env';
        $calls = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$calls) {
            $body = json_decode($options['body'], true); $calls[] = $body;
            self::assertSame(['wave_fixture_search'], $body['indexes']);
            return new MockResponse(json_encode(['key' => count($calls) === 1 ? 'fake_admin_key' : 'fake_search_key']));
        });
        $meili = new Meilisearch($http, false, 'http://127.0.0.1:7700', '', '', 'wave_fixture');
        $test = new CommandTester(new SearchKeysCommand($http, $meili, 'http://127.0.0.1:7700', 'fake_master_key'));
        try {
            self::assertSame(0, $test->execute(['--output' => $path]));
            self::assertSame(['search'], $calls[1]['actions']);
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertStringContainsString('MEILISEARCH_ADMIN_KEY=fake_admin_key', file_get_contents($path));
            self::assertStringNotContainsString('fake_admin_key', $test->getDisplay());
            self::assertStringNotContainsString('fake_master_key', $test->getDisplay());
            self::assertSame(2, $test->execute(['--output' => $path]));
        } finally { if (is_file($path)) unlink($path); }
    }
}
