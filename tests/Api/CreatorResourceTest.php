<?php

namespace App\Tests\Api;

use App\Api\CreatorResource;
use App\Entity\Creator;
use App\Localization\DemoTranslations;
use PHPUnit\Framework\TestCase;

final class CreatorResourceTest extends TestCase
{
    public function testDemoCreatorCopyIsLocalizedForEverySupportedLocale(): void
    {
        $translations = DemoTranslations::creator('maya-chen');
        $creator = new Creator(
            'maya-chen',
            'Maya Chen',
            'Travel',
            'Sarajevo',
            $translations['en']['bio'],
            [],
            [],
            translations: $translations,
            packages: [[
                'id' => 'maya-story',
                'copyKey' => 'instagram-story',
                'platform' => 'Instagram',
                'title' => 'Instagram story set',
                'description' => 'Three story frames.',
                'price' => 450,
            ]],
        );

        foreach (['bs', 'hr', 'sr', 'sl', 'en'] as $locale) {
            $profile = CreatorResource::fromEntity($creator, $locale);

            self::assertSame($translations[$locale]['bio'], $profile['bio']);
            self::assertSame($translations[$locale]['tagline'], $profile['tagline']);
            self::assertNotSame('Three story frames.', $profile['packages'][0]['description']);
            self::assertNotSame('', $profile['packages'][0]['title']);
            self::assertSame('BAM', $profile['packages'][0]['currency']);
        }
    }
}
