<?php

namespace App\Tests\Localization;

use App\Localization\DisplayDate;
use PHPUnit\Framework\TestCase;

final class DisplayDateTest extends TestCase
{
    public function testGeneratedMessageAndNotificationDatesHaveTheSameDisplayFormat(): void
    {
        self::assertSame('300 KM · 31.12.2026', DisplayDate::text('300 KM · 2026-12-31'));
        self::assertSame('29.02.2028 / 01.03.2028', DisplayDate::text('2028-02-29 / 2028-03-01'));
        self::assertSame('2026-02-30', DisplayDate::text('2026-02-30'));
        self::assertSame('2026-12-31T14:00:00Z', DisplayDate::text('2026-12-31T14:00:00Z'));
        self::assertSame('https://example.test/2026-12-31', DisplayDate::text('https://example.test/2026-12-31'));
    }
}
