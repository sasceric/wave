<?php

namespace App\Tests\Service;

use App\Service\AdminLogReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;

final class AdminLogReaderTest extends TestCase
{
    private string $directory;
    private AdminLogReader $reader;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/wave-tools-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->reader = new AdminLogReader($this->directory, new ArrayAdapter());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testMergedPagesAreStableWhenLogsGrow(): void
    {
        for ($index = 1; $index <= 60; ++$index) {
            file_put_contents($this->directory . '/' . ($index % 2 ? 'prod.log' : 'reminders.log'), sprintf('[2026-10-06T10:00:%02d+00:00] app.INFO: entry %d' . "\n", $index - 1, $index), FILE_APPEND);
        }
        $first = $this->reader->page('', '', 25);
        self::assertCount(25, $first['data']);
        self::assertStringEndsWith('entry 60', $first['data'][0]['message']);
        file_put_contents($this->directory . '/prod.log', '[2026-10-06T10:01:01+00:00] app.INFO: new entry' . "\n", FILE_APPEND);
        $second = $this->reader->page('', $first['meta']['nextCursor'], 25);
        $third = $this->reader->page('', $second['meta']['nextCursor'], 25);
        self::assertCount(10, $third['data']);
        self::assertFalse($third['meta']['hasMore']);
        $all = [...$first['data'], ...$second['data'], ...$third['data']];
        self::assertCount(60, array_unique(array_column($all, 'id')));
        self::assertSame($first['data'], $this->reader->page('', $first['meta']['cursor'], 25)['data']);
        self::assertStringEndsWith('new entry', $this->reader->page('', '', 25)['data'][0]['message']);
    }

    public function testNumberedPagesJumpToTheEndAndKeepTheirSnapshotWhileFilesGrow(): void
    {
        for ($index = 1; $index <= 61; ++$index) {
            $date = (new \DateTimeImmutable('2026-10-06T10:00:00+00:00'))->modify('+'.$index.' seconds');
            $line = json_encode(['datetime' => $date->format(DATE_ATOM), 'level_name' => 'INFO', 'message' => 'entry '.$index], JSON_THROW_ON_ERROR)."\n";
            file_put_contents($this->directory.'/'.($index % 2 ? 'prod.log' : 'reminders.log'), $line, FILE_APPEND);
        }
        $first = $this->reader->numberedPage('', '', 25, 1);
        self::assertSame(61, $first['meta']['total']);
        self::assertSame('entry 61', $first['data'][0]['message']);
        $last = $this->reader->numberedPage('', $first['meta']['cursor'], 25, 3);
        self::assertCount(11, $last['data']);
        self::assertSame('entry 11', $last['data'][0]['message']);
        self::assertSame('entry 1', $last['data'][10]['message']);
        self::assertFalse($last['meta']['hasMore']);
        file_put_contents($this->directory.'/prod.log', '[2026-10-06T11:00:00+00:00] app.INFO: newest' . "\n", FILE_APPEND);
        self::assertSame($first['data'], $this->reader->numberedPage('', $first['meta']['cursor'], 25, 1)['data']);
        $fresh = $this->reader->numberedPage('', '', 25, 1);
        self::assertSame(62, $fresh['meta']['total']);
        self::assertStringEndsWith('newest', $fresh['data'][0]['message']);
        self::assertSame(3, $this->reader->numberedPage('', $fresh['meta']['cursor'], 25, 999)['meta']['page']);
    }

    public function testNumberedPagesHandlePartialLinesRotationAndRedaction(): void
    {
        $path = $this->directory.'/prod.log';
        file_put_contents($path, '[2026-10-06T10:00:00+00:00] app.INFO: first' . "\n" . '[2026-10-06T11:00:00+00:00] app.ERROR: password=private');
        $first = $this->reader->numberedPage('prod.log', '', 25, 1);
        self::assertStringNotContainsString('private', $first['data'][0]['message']);
        file_put_contents($path, '-continued' . "\n", FILE_APPEND);
        $fresh = $this->reader->numberedPage('prod.log', '', 25, 1);
        self::assertSame(2, $fresh['meta']['total']);
        self::assertStringNotContainsString('private', $fresh['data'][0]['detail']);
        unlink($path);
        $rotated = $this->reader->numberedPage('prod.log', $first['meta']['cursor'], 25, 1);
        self::assertTrue($rotated['meta']['changed']);
        self::assertSame(0, $rotated['meta']['total']);
        self::assertSame([], $rotated['data']);
        $this->expectException(\InvalidArgumentException::class);
        $this->reader->numberedPage('', $fresh['meta']['cursor'], 25, 1);
    }

    public function testStructuredLogColumnsAndModalDetailAreParsedAndMasked(): void
    {
        file_put_contents($this->directory . '/prod.log', json_encode(['datetime' => '2026-10-06T10:00:00+00:00', 'channel' => 'background', 'level_name' => 'INFO', 'message' => 'background.job.completed', 'context' => ['password' => 'hidden-password']], JSON_THROW_ON_ERROR) . "\n");
        $row = $this->reader->page('prod.log', '', 25)['data'][0];
        self::assertSame('background', $row['channel']);
        self::assertSame('INFO', $row['level']);
        self::assertSame('background.job.completed', $row['message']);
        self::assertSame('2026-10-06T10:00:00+00:00', $row['time']);
        self::assertStringNotContainsString('hidden-password', $row['detail']);
    }

    public function testPathsSymlinksAndNonLogsCannotBeSelected(): void
    {
        file_put_contents($this->directory . '/secrets.txt', 'private');
        symlink($this->directory . '/secrets.txt', $this->directory . '/linked.log');
        file_put_contents($this->directory . '/prod.log', 'visible');
        self::assertSame(['prod.log'], array_column($this->reader->files()['files'], 'name'));
        foreach (['../secrets.txt', 'secrets.txt', 'linked.log', '/etc/passwd'] as $file) {
            try {
                $this->reader->page($file, '', 25);
                self::fail('Unsafe log selection accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCredentialsAreMaskedAndMessagesArePlainText(): void
    {
        file_put_contents($this->directory . '/prod.log', '[2026-10-06T10:00:00+00:00] app.ERROR: postgresql://user:db-secret@localhost/db Authorization: Bearer token-secret password=secret-value <script>alert(1)</script>');
        $message = $this->reader->page('prod.log', '', 25)['data'][0]['message'];
        self::assertStringNotContainsString('db-secret', $message);
        self::assertStringNotContainsString('token-secret', $message);
        self::assertStringNotContainsString('secret-value', $message);
        self::assertStringContainsString('[redacted]', $message);
    }

    public function testRotationAndExpiredCursorsAreReported(): void
    {
        file_put_contents($this->directory . '/prod.log', "first\nsecond\n");
        $first = $this->reader->page('', '', 25);
        unlink($this->directory . '/prod.log');
        $rotated = $this->reader->page('', $first['meta']['cursor'], 25);
        self::assertTrue($rotated['meta']['changed']);
        self::assertSame([], $rotated['data']);
        $this->expectException(\InvalidArgumentException::class);
        $this->reader->page('', str_repeat('0', 32), 25);
    }

    public function testQuotedSecretsAndPrivateKeyLinesAreMasked(): void
    {
        file_put_contents($this->directory . '/prod.log', "{\"password\":\"secret,with,commas\",\"api_key\":\"api-secret\"}\n" . str_repeat('a', 64) . "\n");
        $rows = $this->reader->page('', '', 25)['data'];
        self::assertSame('[redacted private key]', $rows[0]['message']);
        self::assertStringNotContainsString('with,commas', $rows[1]['message']);
        self::assertStringNotContainsString('api-secret', $rows[1]['message']);
    }

    public function testLargeLinesAndMissingFinalNewlineAreBounded(): void
    {
        file_put_contents($this->directory . '/prod.log', "first\n" . str_repeat('x', 200000));
        $result = $this->reader->page('', '', 25);
        self::assertFalse($result['meta']['hasMore']);
        self::assertLessThanOrEqual(10, count($result['data']));
        self::assertTrue($result['data'][0]['truncated']);
        self::assertLessThanOrEqual(8000, strlen($result['data'][0]['message']));
        self::assertSame('first', $result['data'][array_key_last($result['data'])]['message']);
    }

    public function testTruncatedAndRegrownFilesDoNotReuseOldOffsets(): void
    {
        file_put_contents($this->directory . '/prod.log', "old line\n");
        $snapshot = $this->reader->page('', '', 25);
        file_put_contents($this->directory . '/prod.log', "a completely different and longer line\n");
        $changed = $this->reader->page('', $snapshot['meta']['cursor'], 25);
        self::assertTrue($changed['meta']['changed']);
        self::assertSame([], $changed['data']);
    }

    public function testOnlyLatestFilesAreRetainedInTheBoundedCatalog(): void
    {
        for ($index = 0; $index < 260; ++$index) {
            $path = $this->directory . '/prod-' . $index . '.log';
            file_put_contents($path, 'entry');
            touch($path, 1000 + $index);
        }
        $result = $this->reader->files();
        self::assertTrue($result['limited']);
        self::assertCount(256, $result['files']);
        self::assertSame('prod-259.log', $result['files'][0]['name']);
    }
}
