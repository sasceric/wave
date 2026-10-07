<?php

namespace App\Tests\Account;

use App\Account\CreatorBirthday;
use PHPUnit\Framework\TestCase;

final class CreatorBirthdayTest extends TestCase
{
    public function testEmptyBirthdayCanBeClearedAndDatesRoundTrip(): void
    {
        self::assertNull(CreatorBirthday::parse(null));
        self::assertNull(CreatorBirthday::parse(''));
        self::assertSame('2000-02-29', CreatorBirthday::parse('2000-02-29')->format('Y-m-d'));
        self::assertSame('1900-01-01', CreatorBirthday::parse('1900-01-01')->format('Y-m-d'));
    }

    public function testMalformedImpossibleAndFutureDatesAreRejected(): void
    {
        foreach (['2023-02-29', '2000-13-01', '2000-02-30', '01/02/2000', '2000-2-01', '1899-12-31', '2099-01-01', 20000101, ['2000-01-01']] as $value) {
            self::assertFalse(CreatorBirthday::parse($value));
        }
    }
}
