<?php

namespace App\Tests\Service;

use App\Service\FrontendAssets;
use PHPUnit\Framework\TestCase;

final class FrontendAssetsTest extends TestCase
{
    public function testOnlyHomepageStylesAreReturnedAndUnsafePathsAreIgnored(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wave-assets-');
        self::assertIsString($path);
        try {
            file_put_contents($path, json_encode([
                'src/views/HomeView.vue' => [
                    'css' => ['build/assets/HomeView-123.css', 'build/assets/HomeView-123.css', '../private.css', 'https://example.com/style.css'],
                    'imports' => ['_AccountView.js'],
                ],
                '_AccountView.js' => ['css' => ['build/assets/AccountView-456.css']],
            ], JSON_THROW_ON_ERROR));
            self::assertSame(['/build/assets/HomeView-123.css'], (new FrontendAssets($path))->homepageStyles());
        } finally {
            unlink($path);
        }
    }

    public function testAnUnavailableOrInvalidManifestDoesNotBreakThePage(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wave-assets-');
        self::assertIsString($path);
        try {
            $assets = new FrontendAssets($path);
            foreach (['', '{broken', 'null', '{"src/views/HomeView.vue":{"css":"invalid"}}'] as $content) {
                file_put_contents($path, $content);
                self::assertSame([], $assets->homepageStyles());
            }
        } finally {
            unlink($path);
        }
        self::assertSame([], (new FrontendAssets($path))->homepageStyles());
    }
}
