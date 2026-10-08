<?php

namespace App\Tests\Service;

use App\Background\CachedSitemap;
use App\Background\JobDispatcher;
use App\Background\PayloadCipher;
use App\Localization\LocalizedRouteMap;
use App\Service\SiteOrigin;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

final class CachedSitemapTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/wave-sitemap-test-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testFailedPublicationPreservesPreviousIndexAndRemovesUnpublishedParts(): void
    {
        file_put_contents($this->directory . '/sitemap.xml', 'previous-complete-index');
        $filesystem = new class extends Filesystem {
            public function dumpFile(string $filename, $content): void
            {
                throw new \RuntimeException('Simulated publication failure.');
            }
        };
        try {
            $this->sitemap($filesystem)->generate();
            self::fail('Publication must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated publication failure.', $exception->getMessage());
        }
        self::assertSame('previous-complete-index', file_get_contents($this->directory . '/sitemap.xml'));
        self::assertCount(1, glob($this->directory . '/*.xml'));
    }

    public function testRetentionFailureKeepsPartsReferencedByThePublishedIndex(): void
    {
        $old = $this->directory . '/' . str_repeat('a', 32) . '-0.xml';
        file_put_contents($old, 'old part');
        touch($old, time() - 259200);
        $filesystem = new class extends Filesystem {
            public function remove(string|iterable $files): void
            {
                throw new \RuntimeException('Simulated retention failure.');
            }
        };
        try {
            $this->sitemap($filesystem)->generate();
            self::fail('Retention must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated retention failure.', $exception->getMessage());
        }
        $index = simplexml_load_file($this->directory . '/sitemap.xml');
        self::assertNotFalse($index);
        foreach ($index->sitemap as $part) {
            $name = basename((string) $part->loc);
            self::assertFileExists($this->directory . '/' . $name);
            self::assertNotFalse(simplexml_load_file($this->directory . '/' . $name));
        }
    }

    private function sitemap(Filesystem $filesystem): CachedSitemap
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn(0);
        $db->method('fetchAllAssociative')->willReturn([]);
        $jobs = new JobDispatcher($db, $this->createStub(MessageBusInterface::class), new PayloadCipher('test-only-secret'), new NullLogger());

        return new CachedSitemap($db, new LocalizedRouteMap(dirname(__DIR__, 2)), new SiteOrigin('https://wave.example'), $jobs, $filesystem, $this->directory, new \App\Service\RegionalSeoContent($db, dirname(__DIR__, 2)));
    }
}
