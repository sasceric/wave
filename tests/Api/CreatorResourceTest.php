<?php

namespace App\Tests\Api;

use App\Api\CreatorResource;
use App\Entity\Creator;
use App\Localization\DemoTranslations;
use PHPUnit\Framework\TestCase;

final class CreatorResourceTest extends TestCase
{
    public function testCardsChooseTheLargestSingleAudienceAndKeepPrivateSocialDetailsOut(): void
    {
        $profiles = [
            ['platform' => 'Instagram', 'followers' => 10000, 'handle' => 'instagram-name'],
            ['platform' => 'TikTok', 'followers' => 20000, 'handle' => 'tiktok-name'],
            ['platform' => 'YouTube', 'followers' => null],
        ];
        $creator = new Creator('audience', 'Audience', 'Travel', 'Sarajevo', '', $profiles);
        self::assertSame([['platform' => 'TikTok', 'followers' => 20000]], CreatorResource::fromEntity($creator, 'bs', card: true)['socialProfiles']);
        self::assertSame($profiles, $creator->getSocialProfiles());
        self::assertCount(3, CreatorResource::fromEntity($creator, 'bs')['socialProfiles']);
        foreach ([[], [['platform' => 'Instagram', 'followers' => null]], [['platform' => 'YouTube', 'followers' => 'not a count']]] as $missing) {
            $creator = new Creator('missing', 'Missing', 'Travel', 'Sarajevo', '', $missing);
            self::assertSame([], CreatorResource::fromEntity($creator, 'bs', card: true)['socialProfiles']);
        }
        self::assertSame(['platform' => 'Instagram', 'followers' => 0], \App\Account\SocialAudience::largest([['platform' => 'Instagram', 'followers' => 0]]));
        self::assertSame(1200, \App\Account\SocialAudience::largest([['followers' => null], ['followers' => '1200']])['followers']);
    }

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
