<?php

namespace App\Account;

final class CreatorTypes
{
    /** @return list<string> */
    public static function values(): array
    {
        static $values = null;
        if ($values === null) {
            $json = file_get_contents(__DIR__ . '/../../config/creator_types.json');
            if ($json === false) {
                throw new \RuntimeException('Creator types catalog is unavailable.');
            }
            $values = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        }

        return $values;
    }

    /** @return list<string>|null */
    public static function parse(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > count(self::values())) {
            return null;
        }
        foreach ($value as $type) {
            if (!is_string($type) || !in_array($type, self::values(), true)) {
                return null;
            }
        }

        return array_values(array_unique($value));
    }
}
