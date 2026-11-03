<?php

declare(strict_types=1);

namespace App\Account;

/** Legacy campaign locations remain readable while new writes use a JSON list. */
final class CampaignCities
{
    /** @return list<string> */
    public static function fromLegacy(string $value): array
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^(svi|sve|svi gradovi|svi gradovi u bih|cijela bih|cela bih|cijela bosna i hercegovina|bih|all|all cities|alle|vsi|vsa mesta)$/iu', $value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $city): bool => $city !== ''));
    }
}
