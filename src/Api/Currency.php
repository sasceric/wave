<?php

namespace App\Api;

final class Currency
{
    public const SUPPORTED = ['BAM', 'EUR', 'RSD'];

    public static function isSupported(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::SUPPORTED, true);
    }
}
