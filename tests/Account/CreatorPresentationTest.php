<?php

namespace App\Tests\Account;

use App\Account\CreatorPresentation;
use PHPUnit\Framework\TestCase;

final class CreatorPresentationTest extends TestCase
{
    public function testWorkIsPublicByDefaultButCanBeHiddenExplicitly(): void
    {
        self::assertTrue(CreatorPresentation::work([])['publicPermission']);
        self::assertFalse(CreatorPresentation::work(['publicPermission' => false])['publicPermission']);
    }

    public function testSocialMeasurementDateIsPreservedAndUntrustedVerificationIsDiscarded(): void
    {
        $old = [['platform' => 'Instagram', 'handle' => '@creator', 'followers' => 10, 'lastUpdated' => '2026-09-01']];
        $rows = CreatorPresentation::social([['platform' => 'Instagram', 'handle' => '@creator', 'followers' => 20, 'verified' => true, 'source' => 'verified', 'photo' => '/private/proof.png']], $old);
        self::assertSame('2026-09-01', $rows[0]['lastUpdated']);
        self::assertArrayNotHasKey('verified', $rows[0]);
        self::assertArrayNotHasKey('source', $rows[0]);
        self::assertArrayNotHasKey('photo', $rows[0]);
        self::assertNull($rows[0]['averageViews']);
        self::assertNull(CreatorPresentation::social([['platform' => 'Spotify', 'handle' => 'podcast', 'followers' => null]], [])[0]['followers']);
    }

    public function testAudienceCannotExceedOneHundredOrContainDuplicateCountries(): void
    {
        foreach ([[['countryCode' => 'BA', 'percent' => 70], ['countryCode' => 'HR', 'percent' => 40]], [['countryCode' => 'BA', 'percent' => 20], ['countryCode' => 'BA', 'percent' => 10]]] as $countries) {
            try { CreatorPresentation::social([['platform' => 'Instagram', 'handle' => 'creator', 'followers' => 10, 'audienceCountries' => $countries]], []); self::fail('Invalid audience accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testAvailabilityRequiresARealDateAndSafeVideoLink(): void
    {
        foreach ([['availability' => 'from'], ['availability' => 'from', 'availableFrom' => '2026-02-31'], ['introVideoUrl' => 'javascript:alert(1)'], ['introVideoUrl' => 'https://user:password@example.com/']] as $data) {
            try { CreatorPresentation::settings($data); self::fail('Invalid presentation accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        self::assertSame('2026-10-20', CreatorPresentation::settings(['availability' => 'from', 'availableFrom' => '2026-10-20'])['availableFrom']);
    }

    public function testSamplesCannotImpersonateClientsAndPackagesRequireAnAdvertisingTerm(): void
    {
        $work = CreatorPresentation::work(['source' => 'sample', 'client' => 'Fictional endorsement', 'agency' => 'Agency', 'campaignId' => 1]);
        self::assertSame('', $work['client']);
        self::assertSame('', $work['agency']);
        self::assertNull($work['campaignId']);
        $this->expectException(\InvalidArgumentException::class);
        CreatorPresentation::package(['usage' => 'ads']);
    }
}
