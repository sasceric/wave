<?php

namespace App\Localization;

/** Human-readable dates in generated chat bodies, inbox previews and device notifications. */
final class DisplayDate
{
    public static function text(string $text): string
    {
        return preg_replace_callback('~(?<![\w/:?=&%\-])(\d{4})-(\d{2})-(\d{2})\b(?![T\d/])~u', static function (array $match): string {
            return checkdate((int) $match[2], (int) $match[3], (int) $match[1]) ? $match[3].'.'.$match[2].'.'.$match[1] : $match[0];
        }, $text) ?? $text;
    }
}
