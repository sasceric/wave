<?php

declare(strict_types=1);

namespace App\Legal;

use App\Entity\User;

final class LegalPolicies
{
    public const VERSION = '2026-10-26.2';

    /** Date the current terms apply from, shared with the frontend (config/legal_documents.json). */
    public static function effectiveDate(): string
    {
        $config = self::config();

        return is_string($config['effectiveDate'] ?? null) ? $config['effectiveDate'] : self::VERSION;
    }

    /** Whether existing accounts should be emailed about the current terms (set when the text is approved). */
    public static function notifyExistingUsers(): bool
    {
        return (self::config()['notifyExistingUsers'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $config = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/config/legal_documents.json'), true);

        return is_array($config) ? $config : [];
    }

    /** Fingerprint of the immutable, localized documents associated with this version. */
    public static function archiveHash(): string
    {
        $hash = hash_file('sha256', dirname(__DIR__, 2).'/config/legal/'.self::VERSION.'.json');
        if ($hash === false) throw new \RuntimeException('The published legal archive is missing.');

        return $hash;
    }

    public static function accepted(array $payload): bool
    {
        return ($payload['termsAccepted'] ?? null) === true && ($payload['termsVersion'] ?? null) === self::VERSION;
    }

    public static function registration(User $user, string $locale, string $source): void
    {
        $at = gmdate(DATE_ATOM);
        $user->setTermsNoticeVersion(self::VERSION);
        $user->setLegalRecords([
            'terms' => ['version' => self::VERSION, 'archiveHash' => self::archiveHash(), 'acceptedAt' => $at, 'locale' => $locale, 'source' => $source],
            'newsletter' => self::emailRecord([], $user->isNewsletterEnabled(), $source, $locale),
        ]);
    }

    public static function emailRecord(array $previous, bool $enabled, string $source, string $locale): array
    {
        $at = gmdate(DATE_ATOM);
        return array_merge($previous, [
            'enabled' => $enabled,
            'version' => self::VERSION,
            'archiveHash' => self::archiveHash(),
            'locale' => $locale,
            'source' => $source,
            'updatedAt' => $at,
            $enabled ? 'grantedAt' : (isset($previous['grantedAt']) ? 'withdrawnAt' : 'declinedAt') => $at,
        ]);
    }
}
