<?php

namespace App\Account;

/** PostgreSQL projection of the shared completion checklist, before directory pagination. */
final class ProfileStrengthSql
{
    public static function creator(string $alias = 'c'): string
    {
        return self::score('creator', [
            'photo' => "($alias.avatar_media_id IS NOT NULL OR COALESCE($alias.avatar_url, '') <> '')",
            'works' => self::length("$alias.portfolio").' >= '.ProfileStrength::MIN_WORKS,
            'bio' => self::textLength("$alias.bio").' >= '.ProfileStrength::BIO_MIN_LENGTH,
            'social' => self::exists("$alias.social_profiles", "TRIM(COALESCE(v->>'handle', '')) <> '' AND ".self::positive("v->>'followers'")),
            'packages' => self::exists("$alias.packages", self::positive("v->>'price'")),
            'audience' => self::exists("$alias.social_profiles", self::length("v->'audienceCountries'").' > 0 OR '.self::positive("v->>'averageViews'").' OR '.self::positive("v->>'engagementRate'")),
            'faq' => self::length("$alias.faqs").' > 0',
        ]);
    }

    public static function company(): string
    {
        $common = [
            'logo' => "(c.logo_media_id IS NOT NULL OR COALESCE(c.logo_url, '') <> '')",
            'about' => self::textLength('c.about').' >= '.ProfileStrength::ABOUT_MIN_LENGTH,
            'links' => self::exists('c.social_links', "COALESCE(v->>'platform', '') <> '' AND TRIM(COALESCE(v->>'url', '')) <> ''"),
            'cover' => 'c.cover_media_id IS NOT NULL',
        ];
        $brand = self::score('brand', $common + ['industries' => "COALESCE(c.industry, '') <> '' OR EXISTS (SELECT 1 FROM company_industry ci WHERE ci.company_id = c.id)"]);
        $agency = self::score('agency', $common + [
            'services' => self::length('c.services').' > 0 OR '.self::length('studio.tags').' > 0',
            'works' => self::length('studio.portfolio').' >= '.ProfileStrength::MIN_WORKS,
            'packages' => self::length('studio.packages').' > 0',
            'faq' => self::length('studio.faqs').' > 0',
        ]);
        return "CASE WHEN c.company_type = 'agency' THEN $agency ELSE $brand END";
    }

    private static function score(string $kind, array $conditions): string
    {
        $terms = [];
        foreach (ProfileStrength::STEPS[$kind] as $key => $weight) {
            $terms[] = 'CASE WHEN ('.$conditions[$key].") THEN $weight ELSE 0 END";
        }
        return '('.implode(' + ', $terms).')';
    }

    private static function list(string $column): string
    {
        $json = "CAST($column AS jsonb)";
        return "CASE jsonb_typeof($json) WHEN 'array' THEN $json WHEN 'object' THEN jsonb_path_query_array($json, '$.*') ELSE '[]'::jsonb END";
    }

    private static function length(string $column): string
    {
        return 'jsonb_array_length('.self::list($column).')';
    }

    private static function exists(string $column, string $condition): string
    {
        return 'EXISTS (SELECT 1 FROM jsonb_array_elements('.self::list($column).") AS v WHERE jsonb_typeof(v) = 'object' AND ($condition))";
    }

    private static function positive(string $value): string
    {
        return "CASE WHEN $value ~ '^[[:space:]]*[+]?([0-9]+([.][0-9]*)?|[.][0-9]+)([eE][+-]?[0-9]{1,3})?[[:space:]]*$' THEN CAST($value AS numeric) > 0 ELSE FALSE END";
    }

    private static function textLength(string $column): string
    {
        // Same tag/entity/whitespace normalization as ProfileStrength::plainTextLength().
        return "LENGTH(TRIM(regexp_replace(regexp_replace(REPLACE(regexp_replace(COALESCE($column, ''), '<[^>]*>', ' ', 'g'), '&nbsp;', ' '), '&[a-z]+;|&#[0-9]+;', 'x', 'gi'), '[[:space:]]+', ' ', 'g')))";
    }
}
