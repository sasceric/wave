<?php

namespace App\Account;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;

/**
 * Optional email categories and their signed, login-free management links.
 *
 * A link carries the account id and an HMAC of it, so the recipient can unsubscribe
 * in one click (RFC 8058) or open the preferences page without signing in. Account,
 * security and collaboration emails are not optional and never use these links.
 */
final class EmailPreferences
{
    public const PROFILE_TIPS = 'profile_tips';
    public const NEWSLETTER = 'newsletter';
    public const CAMPAIGN_ALERTS = 'campaign_alerts';
    public const CATEGORIES = [self::PROFILE_TIPS, self::NEWSLETTER, self::CAMPAIGN_ALERTS];

    public function __construct(
        private readonly Connection $db,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%env(APP_BASE_URL)%')] private readonly string $baseUrl,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function signature(int $userId): string
    {
        $hash = hash_hmac('sha256', 'wave-email-preferences:'.$userId, $this->secret, true);

        return rtrim(strtr(base64_encode(substr($hash, 0, 24)), '+/', '-_'), '=');
    }

    public function verify(mixed $userId, mixed $signature): ?int
    {
        if (!is_string($signature) || $signature === '' || !(is_int($userId) || (is_string($userId) && ctype_digit($userId)))) {
            return null;
        }
        $id = (int) $userId;

        return $id > 0 && hash_equals($this->signature($id), $signature) ? $id : null;
    }

    /** Endpoint for mail clients' one-click unsubscribe (POST, no login, no page). */
    public function oneClickUrl(int $userId, string $category): string
    {
        return rtrim($this->baseUrl, '/').'/api/email/unsubscribe?'.http_build_query(['u' => $userId, 'c' => $category, 's' => $this->signature($userId)]);
    }

    /** Page linked from the email footer: unsubscribes from $category and shows all choices. */
    public function pageUrl(int $userId, ?string $category, string $locale): string
    {
        $query = ['u' => $userId, 's' => $this->signature($userId)];
        if ($category !== null) {
            $query['c'] = $category;
        }

        return $this->routeUrl('account-email-preferences', $locale, $query);
    }

    /** @param array<string, int|string> $query */
    public function routeUrl(string $routeName, string $locale, array $query = []): string
    {
        $routes = json_decode((string) file_get_contents($this->projectDir.'/config/localized_routes.json'), true, flags: JSON_THROW_ON_ERROR);
        $prefixes = json_decode((string) file_get_contents($this->projectDir.'/config/localized_route_prefixes.json'), true, flags: JSON_THROW_ON_ERROR);
        $locale = isset($routes[$locale]) ? $locale : 'bs';
        $path = $routes[$locale][$routeName] ?? $routes['bs'][$routeName] ?? throw new \InvalidArgumentException('Unknown route.');

        return rtrim($this->baseUrl, '/').(!empty($prefixes[$locale]) ? '/'.$prefixes[$locale] : '').'/'.$path.($query === [] ? '' : '?'.http_build_query($query));
    }

    public function addUnsubscribeHeaders(Email $email, int $userId, string $category): void
    {
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$this->oneClickUrl($userId, $category).'>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }

    /**
     * @return array{email: string, profileTips: bool, newsletter: bool, campaignAlerts: ?bool}|null
     */
    public function states(int $userId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT u.email, u.profile_tips_enabled, u.newsletter_enabled, p.enabled AS alerts FROM wave_user u LEFT JOIN campaign_alert_preference p ON p.user_id = u.id WHERE u.id = ? AND u.deleted_at IS NULL', [$userId]);
        if (!$row) {
            return null;
        }

        return [
            'email' => self::maskedEmail((string) $row['email']),
            'profileTips' => self::bool($row['profile_tips_enabled']),
            'newsletter' => self::bool($row['newsletter_enabled']),
            'campaignAlerts' => $row['alerts'] === null ? null : self::bool($row['alerts']),
        ];
    }

    public function set(int $userId, string $category, bool $enabled): bool
    {
        return match ($category) {
            self::PROFILE_TIPS => $this->db->executeStatement('UPDATE wave_user SET profile_tips_enabled = ? WHERE id = ? AND deleted_at IS NULL', [$enabled, $userId], [\Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::INTEGER]) > 0,
            self::NEWSLETTER => $this->setNewsletter($userId, $enabled),
            // Alerts keep their categories and frequency; only delivery is switched.
            self::CAMPAIGN_ALERTS => $this->db->executeStatement('UPDATE campaign_alert_preference SET enabled = ? WHERE user_id = ?', [$enabled, $userId], [\Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::INTEGER]) > 0,
            default => throw new \InvalidArgumentException('Unknown email category.'),
        };
    }

    private function setNewsletter(int $userId, bool $enabled): bool
    {
        return $this->db->transactional(function () use ($userId, $enabled): bool {
            $lock = $this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform ? ' FOR UPDATE' : '';
            $row = $this->db->fetchAssociative('SELECT legal_records, preferred_locale FROM wave_user WHERE id = ? AND deleted_at IS NULL'.$lock, [$userId]);
            if ($row === false) return false;
            $records = $row['legal_records'] === null ? [] : json_decode($row['legal_records'], true, flags: JSON_THROW_ON_ERROR);
            $records['newsletter'] = \App\Legal\LegalPolicies::emailRecord($records['newsletter'] ?? [], $enabled, 'signed_email_preferences', $row['preferred_locale']);
            return $this->db->executeStatement('UPDATE wave_user SET newsletter_enabled = ?, legal_records = ? WHERE id = ?', [$enabled, json_encode($records, JSON_THROW_ON_ERROR), $userId], [\Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER]) > 0;
        });
    }

    public static function maskedEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($name, 0, min(2, max(1, mb_strlen($name) - 1)));

        return $visible.str_repeat('•', max(1, min(6, mb_strlen($name) - mb_strlen($visible)))).($domain !== '' ? '@'.$domain : '');
    }

    private static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
