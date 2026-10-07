<?php

declare(strict_types=1);

namespace App\Account;

use DateTimeImmutable;

final class CreatorBirthday
{
    public static function parse(mixed $value): DateTimeImmutable|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '1900-01-01' || $date > new DateTimeImmutable('today')) {
            return false;
        }

        return $date;
    }
}
