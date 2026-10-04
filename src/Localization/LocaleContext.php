<?php

namespace App\Localization;

use Symfony\Component\HttpFoundation\Request;

final class LocaleContext
{
    public const SUPPORTED = ['bs', 'hr', 'sr', 'cnr', 'sl', 'en'];

    public static function fromRequest(Request $request): ?string
    {
        $locale = $request->query->getString('locale', 'bs');

        return in_array($locale, self::SUPPORTED, true) ? $locale : null;
    }
}
