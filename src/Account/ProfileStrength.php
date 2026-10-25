<?php

namespace App\Account;

use App\Api\UserResource;
use App\Entity\User;

/**
 * Profile strength: an encouragement score on top of the required basics.
 *
 * Mirrors frontend/src/lib/profileStrength.js and reads the same profile shape the
 * account API returns, so Pregled, reminder emails and the admin lists agree.
 */
final class ProfileStrength
{
    /** Weights add up to 100 per account type; order is the order steps are suggested. */
    public const STEPS = [
        'creator' => ['photo' => 20, 'works' => 20, 'bio' => 15, 'social' => 15, 'packages' => 15, 'audience' => 10, 'faq' => 5],
        'agency' => ['logo' => 15, 'about' => 15, 'services' => 15, 'works' => 15, 'packages' => 10, 'cover' => 10, 'links' => 10, 'faq' => 10],
        'brand' => ['logo' => 25, 'about' => 25, 'links' => 20, 'cover' => 15, 'industries' => 15],
    ];
    public const BIO_MIN_LENGTH = 150;
    public const ABOUT_MIN_LENGTH = 80;
    public const MIN_WORKS = 3;

    /**
     * @return array{kind: string, score: int, level: int, missing: list<array{key: string, points: int}>}|null
     */
    public static function forUser(User $user, string $locale = 'bs'): ?array
    {
        if ($user->isDeleted() || $user->hasRole('ROLE_ADMIN') || $user->hasRole('ROLE_MODERATOR')) {
            return null;
        }

        return self::fromResource(UserResource::fromEntity($user, $locale));
    }

    /**
     * @param array<string, mixed> $user
     * @return array{kind: string, score: int, level: int, missing: list<array{key: string, points: int}>}|null
     */
    public static function fromResource(array $user): ?array
    {
        $profile = $user['profile'] ?? null;
        if (!is_array($profile)) {
            return null;
        }
        $kind = match ($user['accountType'] ?? null) {
            'creator' => 'creator',
            'company' => ($profile['companyType'] ?? null) === 'agency' ? 'agency' : 'brand',
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        $done = self::checks($kind, $profile);
        $score = 0;
        $missing = [];
        foreach (self::STEPS[$kind] as $key => $points) {
            if ($done[$key]) {
                $score += $points;
            } else {
                $missing[] = ['key' => $key, 'points' => $points];
            }
        }

        return ['kind' => $kind, 'score' => $score, 'level' => $score >= 100 ? 4 : ($score >= 70 ? 3 : ($score >= 40 ? 2 : 1)), 'missing' => $missing];
    }

    public static function plainTextLength(mixed $html): int
    {
        if (!is_string($html) || $html === '') {
            return 0;
        }
        $text = preg_replace('/<[^>]*>/', ' ', $html) ?? '';
        $text = str_replace('&nbsp;', ' ', $text);
        $text = preg_replace('/&[a-z]+;|&#\d+;/i', 'x', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strlen($text);
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, bool>
     */
    private static function checks(string $kind, array $profile): array
    {
        $list = static fn (mixed $value): array => is_array($value) ? array_values($value) : [];
        $rows = static fn (mixed $value): array => array_values(array_filter($list($value), 'is_array'));
        $social = $rows($profile['socialProfiles'] ?? null);
        $packages = $rows($profile['packages'] ?? null);

        return [
            'photo' => !empty($profile['avatarUrl']) || !empty($profile['avatarMediaId']),
            'logo' => !empty($profile['logoUrl']) || !empty($profile['logoMediaId']),
            'cover' => !empty($profile['coverUrl']) || !empty($profile['coverMediaId']),
            'works' => count($list($profile['portfolio'] ?? null)) >= self::MIN_WORKS,
            'bio' => self::plainTextLength($profile['bio'] ?? null) >= self::BIO_MIN_LENGTH,
            'about' => self::plainTextLength($profile['about'] ?? null) >= self::ABOUT_MIN_LENGTH,
            'social' => array_filter($social, static fn (array $row): bool => trim((string) ($row['handle'] ?? '')) !== '' && (float) ($row['followers'] ?? 0) > 0) !== [],
            'audience' => array_filter($social, static fn (array $row): bool => (is_array($row['audienceCountries'] ?? null) && $row['audienceCountries'] !== [])
                || (float) ($row['averageViews'] ?? 0) > 0 || (float) ($row['engagementRate'] ?? 0) > 0) !== [],
            'packages' => $kind === 'creator'
                ? array_filter($packages, static fn (array $row): bool => is_numeric($row['price'] ?? null) && (float) $row['price'] > 0) !== []
                : $packages !== [],
            'services' => $list($profile['services'] ?? null) !== [],
            'links' => array_filter($rows($profile['socialLinks'] ?? null), static fn (array $row): bool => trim((string) ($row['url'] ?? '')) !== '') !== [],
            'industries' => $list($profile['industries'] ?? null) !== [],
            'faq' => $list($profile['faqs'] ?? null) !== [],
        ];
    }
}
