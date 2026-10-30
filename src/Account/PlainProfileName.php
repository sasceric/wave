<?php

namespace App\Account;

/** Compatibility normalization removes pasted mathematical/font variants, preserving real letters and accents. */
final class PlainProfileName
{
    public static function normalize(string $name): string
    {
        return trim(\Normalizer::normalize($name, \Normalizer::FORM_KC) ?: $name);
    }
}
