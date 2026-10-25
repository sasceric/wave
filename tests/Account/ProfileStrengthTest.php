<?php

namespace App\Tests\Account;

use App\Account\ProfileStrength;
use PHPUnit\Framework\TestCase;

final class ProfileStrengthTest extends TestCase
{
    public function testWeightsAddUpToOneHundredForEveryAccountType(): void
    {
        foreach (ProfileStrength::STEPS as $steps) {
            self::assertSame(100, array_sum($steps));
        }
    }

    public function testCreatorStepsNeedMeaningfulContent(): void
    {
        $partial = ProfileStrength::fromResource(['accountType' => 'creator', 'profile' => [
            'avatarUrl' => '/a.webp', 'bio' => '<p>'.str_repeat('a', 149).'</p>', 'portfolio' => [[], []],
            'socialProfiles' => [['platform' => 'Instagram', 'handle' => 'wave', 'followers' => 0]],
            'packages' => [['title' => 'Reel', 'price' => 0]],
        ]]);
        self::assertSame(20, $partial['score']);
        self::assertSame(1, $partial['level']);
        self::assertSame(['works', 'bio', 'social'], array_slice(array_column($partial['missing'], 'key'), 0, 3));

        $full = ProfileStrength::fromResource(['accountType' => 'creator', 'profile' => [
            'avatarMediaId' => 4, 'bio' => '<p>'.str_repeat('a', 150).'</p>', 'portfolio' => [[], [], []],
            'socialProfiles' => [['platform' => 'Instagram', 'handle' => 'wave', 'followers' => 1200, 'engagementRate' => 3.2]],
            'packages' => [['title' => 'Reel', 'price' => '150']], 'faqs' => [['question' => 'Q', 'answer' => 'A']],
        ]]);
        self::assertSame(100, $full['score']);
        self::assertSame(4, $full['level']);
        self::assertSame([], $full['missing']);
    }

    public function testCompanyKindsFollowTheCompanyType(): void
    {
        $brand = ProfileStrength::fromResource(['accountType' => 'company', 'profile' => [
            'logoUrl' => '/l.webp', 'about' => '<p>'.str_repeat('a', 80).'</p>', 'socialLinks' => [['platform' => 'Website', 'url' => 'https://wave.ba']],
        ]]);
        self::assertSame('brand', $brand['kind']);
        self::assertSame(70, $brand['score']);
        self::assertSame(3, $brand['level']);

        $agency = ProfileStrength::fromResource(['accountType' => 'company', 'profile' => [
            'companyType' => 'agency', 'logoUrl' => '/l.webp', 'about' => '<p>'.str_repeat('a', 79).'</p>', 'services' => [['id' => 1]], 'packages' => [['title' => 'Pack']],
        ]]);
        self::assertSame('agency', $agency['kind']);
        self::assertSame(40, $agency['score']);
        self::assertNull(ProfileStrength::fromResource(['accountType' => 'creator', 'profile' => null]));
    }

    public function testPlainTextLengthIgnoresMarkup(): void
    {
        self::assertSame(8, ProfileStrength::plainTextLength('<p>Hi&nbsp;<b>there</b></p>'));
        self::assertSame(0, ProfileStrength::plainTextLength(null));
    }
}
