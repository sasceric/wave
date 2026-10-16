<?php

declare(strict_types=1);

namespace App\Localization;

final class GermanTranslations
{
    public static function section(string $name): array
    {
        static $catalog = null;
        $catalog ??= json_decode((string) file_get_contents(__DIR__.'/../../config/translations/de.json'), true, flags: JSON_THROW_ON_ERROR);
        return $catalog[$name] ?? [];
    }
}
