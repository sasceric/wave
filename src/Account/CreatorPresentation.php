<?php

declare(strict_types=1);

namespace App\Account;

use DateTimeImmutable;
use InvalidArgumentException;

/** Whitelisted, optional presentation data. Never accept client-supplied verification flags. */
final class CreatorPresentation
{
    public const PLATFORMS = ['TikTok', 'Instagram', 'YouTube', 'Twitch', 'Spotify'];
    public const LANGUAGES = ['bs', 'hr', 'sr', 'sl', 'cnr', 'en', 'de', 'fr', 'it', 'es', 'tr', 'ar'];

    public static function settings(mixed $value): array
    {
        $data = self::object($value);
        $languages = self::list($data['languages'] ?? [], 10);
        foreach ($languages as $language) self::choice($language, self::LANGUAGES);
        $availability = self::choice($data['availability'] ?? 'unspecified', ['unspecified', 'available', 'from', 'busy']);
        $date = self::date($data['availableFrom'] ?? null);
        if ($availability === 'from' && $date === null) throw new InvalidArgumentException('availableFrom');

        return [
            'languages' => array_values(array_unique($languages)),
            'availability' => $availability,
            'availableFrom' => $availability === 'from' ? $date : null,
            'introVideoUrl' => self::url($data['introVideoUrl'] ?? ''),
        ];
    }

    public static function social(mixed $value, array $previous): array
    {
        $result = [];
        foreach (self::list($value, 5) as $entry) {
            $data = self::object($entry);
            $platform = self::choice($data['platform'] ?? null, self::PLATFORMS);
            $handle = self::text($data['handle'] ?? '', 120);
            if ($handle === '') throw new InvalidArgumentException('handle');
            if (str_contains($handle, '://')) self::url($handle);
            $countries = [];
            $total = 0.0;
            foreach (self::list($data['audienceCountries'] ?? [], 5) as $country) {
                $country = self::object($country);
                $code = $country['countryCode'] ?? null;
                if (!is_string($code) || !CountryCode::isSupported($code) || isset($countries[$code])) throw new InvalidArgumentException('countryCode');
                $percent = self::number($country['percent'] ?? null, 0, 100);
                if ($percent === null) throw new InvalidArgumentException('percent');
                $total += $percent;
                $countries[$code] = ['countryCode' => $code, 'percent' => $percent];
            }
            if ($total > 100.00001) throw new InvalidArgumentException('audienceCountries');
            $item = [
                'platform' => $platform, 'handle' => $handle,
                'followers' => self::integer($data['followers'] ?? null, 0, 200_000_000),
                'averageViews' => self::integer($data['averageViews'] ?? null, 0, 2_000_000_000),
                'sampleSize' => self::integer($data['sampleSize'] ?? 10, 1, 1000),
                'engagementRate' => self::number($data['engagementRate'] ?? null, 0, 100),
                'engagementBasis' => self::choice($data['engagementBasis'] ?? 'followers', ['followers', 'reach', 'views', 'platform']),
                'audienceCountries' => array_values($countries),
            ];
            $old = array_find($previous, static fn (array $row): bool => ($row['platform'] ?? null) === $platform && ($row['handle'] ?? null) === $handle);
            $date = self::date($data['lastUpdated'] ?? null);
            // A bio edit must not make old audience figures look freshly measured.
            $item['lastUpdated'] = $date ?? ($old['lastUpdated'] ?? (new DateTimeImmutable('today'))->format('Y-m-d'));
            $result[] = $item;
        }

        return $result;
    }

    public static function work(array $data): array
    {
        $source = self::choice($data['source'] ?? 'sample', ['wave', 'external', 'sample']);
        $campaignId = $source === 'wave' ? self::integer($data['campaignId'] ?? null, 1, PHP_INT_MAX) : null;
        if ($source === 'wave' && $campaignId === null) throw new InvalidArgumentException('campaignId');
        $results = [];
        foreach (self::list($data['results'] ?? [], 8) as $result) {
            $result = self::object($result);
            $metric = self::text($result['metric'] ?? '', 80);
            $value = self::number($result['value'] ?? null, 0, 2_000_000_000);
            if ($metric === '' || $value === null) throw new InvalidArgumentException('results');
            $results[] = ['metric' => $metric, 'value' => $value, 'period' => self::text($result['period'] ?? '', 120)];
        }
        $permission = $data['publicPermission'] ?? true;
        $featured = $data['featured'] ?? false;
        if (!is_bool($permission) || !is_bool($featured)) throw new InvalidArgumentException('publicPermission');

        return [
            'summary' => self::text($data['summary'] ?? '', 300),
            'source' => $source, 'campaignId' => $campaignId,
            'client' => $source === 'sample' ? '' : self::text($data['client'] ?? '', 120),
            'agency' => $source === 'sample' ? '' : self::text($data['agency'] ?? '', 120),
            'brief' => self::text($data['brief'] ?? '', 1500),
            'contribution' => self::text($data['contribution'] ?? '', 1500),
            'projectDate' => self::date($data['projectDate'] ?? null),
            'postUrl' => self::url($data['postUrl'] ?? ''),
            'deliverables' => self::deliverables($data['deliverables'] ?? []),
            'results' => $results, 'measuredAt' => self::date($data['measuredAt'] ?? null),
            'featured' => $featured, 'publicPermission' => $permission,
        ];
    }

    public static function package(array $data): array
    {
        $mode = self::choice($data['mode'] ?? 'posting', ['content', 'posting']);
        $usage = self::choice($data['usage'] ?? 'agreement', ['organic', 'ads', 'agreement']);
        $days = self::integer($data['usageDays'] ?? null, 1, 3650);
        if ($usage === 'ads' && $days === null) throw new InvalidArgumentException('usageDays');

        return [
            'mode' => $mode,
            'priceMode' => self::choice($data['priceMode'] ?? (isset($data['price']) ? 'fixed' : 'agreement'), ['fixed', 'from', 'agreement']),
            'deliveryDays' => self::integer($data['deliveryDays'] ?? null, 1, 365),
            'revisions' => self::integer($data['revisions'] ?? null, 0, 100),
            'usage' => $usage, 'usageDays' => $usage === 'ads' ? $days : null,
            'deliverables' => self::deliverables($data['deliverables'] ?? []),
        ];
    }

    public static function deliverables(mixed $value): array
    {
        $result = [];
        foreach (self::list($value, 12) as $row) {
            $row = self::object($row);
            $quantity = self::integer($row['quantity'] ?? null, 1, 1000);
            if ($quantity === null) throw new InvalidArgumentException('quantity');
            $result[] = [
                'quantity' => $quantity,
                'format' => self::choice($row['format'] ?? null, ['video', 'photo', 'story', 'podcast', 'stream']),
                'duration' => self::integer($row['duration'] ?? null, 1, 86400),
            ];
        }

        return $result;
    }

    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) throw new InvalidArgumentException('date');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value || $value < '1900-01-01' || $value > '2200-12-31') throw new InvalidArgumentException('date');
        return $value;
    }

    public static function url(mixed $value): string
    {
        $value = self::text($value, 1000);
        if ($value !== '' && (filter_var($value, FILTER_VALIDATE_URL) === false || parse_url($value, PHP_URL_SCHEME) !== 'https' || parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null)) throw new InvalidArgumentException('url');
        return $value;
    }

    public static function integer(mixed $value, int $min, int $max): ?int
    {
        if ($value === null || $value === '') return null;
        if (!is_int($value) || $value < $min || $value > $max) throw new InvalidArgumentException('integer');
        return $value;
    }

    private static function number(mixed $value, float $min, float $max): ?float
    {
        if ($value === null || $value === '') return null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max) throw new InvalidArgumentException('number');
        return (float) $value;
    }

    private static function object(mixed $value): array
    {
        if (!is_array($value)) throw new InvalidArgumentException('object');
        return $value;
    }

    private static function list(mixed $value, int $max): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) throw new InvalidArgumentException('list');
        return $value;
    }

    private static function text(mixed $value, int $max): string
    {
        if (!is_string($value) || mb_strlen($value) > $max) throw new InvalidArgumentException('text');
        return trim($value);
    }

    private static function choice(mixed $value, array $values): string
    {
        if (!is_string($value) || !in_array($value, $values, true)) throw new InvalidArgumentException('choice');
        return $value;
    }
}
