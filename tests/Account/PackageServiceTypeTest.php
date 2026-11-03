<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Account\CreatorPresentation;
use App\Listing\ServicePackages;
use PHPUnit\Framework\TestCase;

final class PackageServiceTypeTest extends TestCase
{
    public function testStoredTypeWins(): void
    {
        self::assertSame('photo', CreatorPresentation::serviceType(['serviceType' => 'photo', 'platform' => 'TikTok']));
    }

    public function testOldPackagesAreGuessed(): void
    {
        self::assertSame('tiktok', CreatorPresentation::serviceType(['platform' => 'TikTok']));
        self::assertSame('instagram', CreatorPresentation::serviceType(['platform' => 'Instagram']));
        self::assertSame('photo', CreatorPresentation::serviceType(['platform' => 'All', 'deliverables' => [['format' => 'photo']]]));
        self::assertSame('ugc', CreatorPresentation::serviceType(['platform' => 'All', 'mode' => 'content', 'deliverables' => [['format' => 'video']]]));
        self::assertSame('ugc', CreatorPresentation::serviceType(['platform' => 'All', 'title' => 'UGC video za tvoj brend']));
        self::assertSame('photo', CreatorPresentation::serviceType(['platform' => 'All', 'title' => 'Fotografije proizvoda']));
        self::assertSame('other', CreatorPresentation::serviceType(['platform' => 'All', 'serviceType' => 'unknown', 'title' => 'Mediakit']));
    }

    public function testSanitizerKeepsChosenTypeAndRejectsUnknown(): void
    {
        self::assertSame('ugc', CreatorPresentation::package(['serviceType' => 'ugc'])['serviceType']);
        self::assertSame('tiktok', CreatorPresentation::package(['platform' => 'TikTok'])['serviceType']);
        $this->expectException(\InvalidArgumentException::class);
        CreatorPresentation::package(['serviceType' => 'billboard']);
    }

    public function testFeaturedPackageThenCheapestPricedThenOriginalOrder(): void
    {
        $order = static fn (array $packages): array => array_column(ServicePackages::preferred($packages), 'id');
        self::assertSame(['b', 'c', 'a'], $order([['id' => 'a', 'price' => null], ['id' => 'b', 'price' => 300, 'featured' => true], ['id' => 'c', 'price' => 100]]));
        self::assertSame(['c', 'b', 'a'], $order([['id' => 'a', 'price' => null], ['id' => 'b', 'price' => 300], ['id' => 'c', 'price' => 100]]));
        self::assertSame(['a', 'b'], $order([['id' => 'a', 'price' => null], ['id' => 'b', 'price' => null]]));
        self::assertTrue(CreatorPresentation::package(['featured' => true])['featured']);
        self::assertFalse(CreatorPresentation::package(['featured' => 'yes'])['featured']);
    }
}
