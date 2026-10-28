<?php

namespace App\Account;

/** Card badges and discovery use the largest audience on one platform, never a cross-platform sum. */
final class SocialAudience
{
    public static function largest(array $profiles): ?array
    {
        $largest = null;
        foreach ($profiles as $profile) {
            if (!is_array($profile)) continue;
            $count = $profile['followers'] ?? null;
            if (!is_int($count) && !(is_string($count) && preg_match('/^[0-9]{1,10}$/D', $count))) continue;
            if ((int) $count < 0 || (int) $count > 9999999999) continue;
            $count = min((int) $count, 2147483647);
            if ($largest === null || $count > $largest['followers']) {
                $largest = array_replace($profile, ['followers' => $count]);
            }
        }
        return $largest;
    }

    /** $column is a trusted SQL column expression, never request input. */
    public static function followersSql(string $column): string
    {
        return "(SELECT COALESCE(MAX(CASE WHEN profile->>'followers' ~ '^[0-9]{1,10}$' THEN LEAST((profile->>'followers')::BIGINT, 2147483647) ELSE 0 END), 0) FROM jsonb_array_elements(CAST($column AS jsonb)) profile)";
    }
}
