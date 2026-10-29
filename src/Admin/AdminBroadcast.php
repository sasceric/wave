<?php

declare(strict_types=1);

namespace App\Admin;

use App\Account\AccountEmailSender;
use App\Account\EmailPreferences;
use App\Account\EmailRecipient;
use App\Media\ImageVariants;
use App\Entity\User;
use App\Localization\LocalizedRouteMap;
use App\Service\SiteOrigin;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Admin → Slanje e-mailova: one message to all users, newsletter subscribers,
 * hand-picked accounts or typed addresses. Emails are queued, never sent inline.
 */
final class AdminBroadcast
{
    public const AUDIENCES = ['all', 'newsletter', 'users', 'emails'];
    public const ROLES = ['all', 'creators', 'companies'];
    public const VARIABLES = ['name', 'profileUrl'];
    public const SUBJECT_MAX = 150;
    public const MESSAGE_MAX = 10000;
    public const EMAILS_MAX = 2000;
    private const CHUNK = 100;
    /** Active, confirmed, real accounts (never presentation demos). */
    private const ACCOUNT = 'u.deleted_at IS NULL AND u.email_verified = TRUE AND u.presentation_demo_key IS NULL';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountEmailSender $emails,
        private readonly EmailPreferences $preferences,
        private readonly LocalizedRouteMap $routes,
        private readonly SiteOrigin $origin,
    ) {
    }

    /** @return array{all: int, newsletter: int} */
    public function counts(string $role): array
    {
        [$roleSql, $roleParams] = self::roleFilter($role);

        return [
            'all' => (int) $this->db()->fetchOne('SELECT COUNT(*) FROM wave_user u WHERE '.self::ACCOUNT.$roleSql, $roleParams),
            'newsletter' => count($this->userIds('newsletter', $role, null)) + ($role === 'all' ? count($this->standaloneSubscribers()) : 0),
        ];
    }

    /**
     * Accounts for the hand-picked list (MultiSelect), 20 per page, newest first.
     *
     * @return array{rows: list<array{value: int, label: string, description: string, image: ?string}>, hasMore: bool}
     */
    public function userOptions(string $query, int $page): array
    {
        $params = ['limit' => 21, 'offset' => max(0, $page - 1) * 20];
        $sql = 'SELECT u.id, u.email, u.role, COALESCE(NULLIF(co.name, \'\'), NULLIF(cr.display_name, \'\'), u.email) AS name,
                cr.avatar_media_id AS creator_media, cr.avatar_url AS creator_avatar, co.logo_media_id AS company_media, co.logo_url AS company_logo
            FROM wave_user u LEFT JOIN creator cr ON cr.owner_id = u.id LEFT JOIN company co ON co.owner_id = u.id
            WHERE '.self::ACCOUNT;
        if (trim($query) !== '') {
            $sql .= ' AND (LOWER(u.email) LIKE :q OR LOWER(COALESCE(co.name, \'\')) LIKE :q OR LOWER(COALESCE(cr.display_name, \'\')) LIKE :q)';
            $params['q'] = '%'.mb_strtolower(trim($query)).'%';
        }
        $rows = $this->db()->fetchAllAssociative($sql.' ORDER BY u.id DESC LIMIT :limit OFFSET :offset', $params, ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]);
        $hasMore = count($rows) > 20;

        return [
            'rows' => array_map(static fn (array $row): array => [
                'value' => (int) $row['id'],
                'label' => (string) $row['name'],
                'description' => (string) $row['email'],
                'image' => match (true) {
                    $row['creator_media'] !== null => '/api/media/'.$row['creator_media'].'/thumbnail/'.ImageVariants::VERSION.'/96',
                    $row['company_media'] !== null => '/api/media/'.$row['company_media'].'/thumbnail/'.ImageVariants::VERSION.'/96',
                    default => ($row['creator_avatar'] ?: $row['company_logo']) ?: null,
                },
            ], array_slice($rows, 0, 20)),
            'hasMore' => $hasMore,
        ];
    }

    /**
     * @param array{subject: string, heading: string, message: string, buttonLabel: string, buttonUrl: string} $content
     * @param list<int>|null $userIds
     * @param list<string>|null $emails
     * @return array{sent: int, skipped: int}
     */
    public function send(array $content, string $audience, string $role, ?array $userIds, ?array $emails, string $locale): array
    {
        $sent = 0;
        $skipped = 0;
        $addresses = [];
        $ids = $audience === 'emails' ? [] : $this->userIds($audience, $role, $userIds);
        if ($audience === 'users' && $userIds !== null) {
            $skipped += count(array_unique($userIds)) - count($ids);
        }
        if ($audience === 'emails') {
            foreach ($emails ?? [] as $email) {
                $addresses[mb_strtolower($email)] = $email;
            }
            // Typed addresses of existing accounts are personalised like any account.
            $ids = $addresses === [] ? [] : array_map('intval', $this->db()->fetchFirstColumn(
                'SELECT u.id FROM wave_user u WHERE '.self::ACCOUNT.' AND LOWER(u.email) IN (:emails)',
                ['emails' => array_keys($addresses)],
                ['emails' => ArrayParameterType::STRING],
            ));
        }
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ($this->entityManager->getRepository(User::class)->findBy(['id' => $chunk]) as $user) {
                unset($addresses[mb_strtolower($user->getEmail())]);
                $userLocale = $user->getPreferredLocale();
                $kind = $audience === 'newsletter' ? 'newsletter' : 'account';
                $this->deliver($content, $user->getEmail(), EmailRecipient::name($user), $userLocale, $this->profileUrl($user, $userLocale), $kind, [
                    'settingsUrl' => $this->preferences->pageUrl((int) $user->getId(), $kind === 'newsletter' ? EmailPreferences::NEWSLETTER : null, $userLocale),
                    'oneClickUrl' => $kind === 'newsletter' ? $this->preferences->oneClickUrl((int) $user->getId(), EmailPreferences::NEWSLETTER) : null,
                ]);
                ++$sent;
            }
            $this->entityManager->clear();
        }
        if ($audience === 'newsletter' && $role === 'all') {
            foreach ($this->standaloneSubscribers() as $subscriber) {
                $this->deliver($content, $subscriber['email'], '', $subscriber['locale'], $this->origin->url('/'), 'newsletter', [
                    'settingsUrl' => $this->preferences->subscriberPageUrl($subscriber['id'], $subscriber['locale']),
                    'oneClickUrl' => $this->preferences->subscriberOneClickUrl($subscriber['id']),
                ]);
                ++$sent;
            }
        }
        foreach ($addresses as $email) {
            $this->deliver($content, $email, '', $locale, $this->origin->url($this->routes->localizedPath('home', $locale)), 'external', [
                'settingsUrl' => $this->origin->url($this->routes->localizedPath('home', $locale)),
                'oneClickUrl' => null,
            ]);
            ++$sent;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /** A test send to the admin's own address. */
    public function sendTest(array $content, User $admin): void
    {
        $locale = $admin->getPreferredLocale();
        $this->deliver($content, $admin->getEmail(), EmailRecipient::name($admin), $locale, $this->profileUrl($admin, $locale), 'account', [
            'settingsUrl' => $this->preferences->pageUrl((int) $admin->getId(), null, $locale),
            'oneClickUrl' => null,
        ]);
    }

    /** Fills {{name}} and {{profileUrl}}; without a name, "Pozdrav {{name}}," becomes "Pozdrav,". */
    public static function personalise(string $text, string $name, string $profileUrl): string
    {
        $text = preg_replace_callback('/\{\{\s*(name|profileUrl)\s*\}\}/', static fn (array $match): string => $match[1] === 'name' ? $name : $profileUrl, $text) ?? $text;
        if ($name === '') {
            $text = preg_replace('/[ \t]+([,!.?])/u', '$1', $text) ?? $text;
        }

        return trim($text);
    }

    /** @return list<string> */
    public static function unknownVariables(string ...$texts): array
    {
        preg_match_all('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/', implode("\n", $texts), $matches);

        return array_values(array_unique(array_diff($matches[1], self::VARIABLES)));
    }

    /**
     * @param array{subject: string, heading: string, message: string, buttonLabel: string, buttonUrl: string} $content
     * @param array{settingsUrl: string, oneClickUrl: ?string} $links
     */
    private function deliver(array $content, string $email, string $name, string $locale, string $profileUrl, string $kind, array $links): void
    {
        $fill = static fn (string $value): string => self::personalise($value, $name, $profileUrl);
        $this->emails->sendBroadcast($email, $name, $locale, [
            'subject' => $fill($content['subject']),
            'heading' => $fill($content['heading']),
            'message' => $fill($content['message']),
            'buttonLabel' => $fill($content['buttonLabel']),
            'buttonUrl' => $fill($content['buttonUrl']),
        ], $kind, $links['settingsUrl'], $links['oneClickUrl']);
    }

    /** Public profile when it is visible, otherwise the account overview. */
    private function profileUrl(User $user, string $locale): string
    {
        $public = $user->isApproved() && !$user->isHideMyAccount();
        if ($public && $user->getCompany() !== null && $user->hasRole('ROLE_COMPANY')) {
            return $this->origin->url($this->routes->localizedPath('company-profile', $locale, ['slug' => $user->getCompany()->getSlug()]));
        }
        if ($public && $user->getCreator() !== null) {
            return $this->origin->url($this->routes->localizedPath('creator-profile', $locale, ['slug' => $user->getCreator()->getSlug()]));
        }

        return $this->origin->url($this->routes->localizedPath('account-dashboard', $locale));
    }

    /**
     * @param list<int>|null $userIds
     * @return list<int>
     */
    private function userIds(string $audience, string $role, ?array $userIds): array
    {
        [$roleSql, $params] = self::roleFilter($role);
        $types = [];
        $sql = 'SELECT u.id FROM wave_user u WHERE '.self::ACCOUNT.$roleSql;
        if ($audience === 'newsletter') {
            $sql .= ' AND u.newsletter_enabled = TRUE';
        }
        if ($audience === 'users') {
            if ($userIds === null || $userIds === []) return [];
            $sql .= ' AND u.id IN (:ids)';
            $params['ids'] = array_values(array_unique($userIds));
            $types['ids'] = ArrayParameterType::INTEGER;
        }

        return array_map('intval', $this->db()->fetchFirstColumn($sql.' ORDER BY u.id', $params, $types));
    }

    /**
     * Footer-form subscribers who confirmed and have no account (accounts use their own setting).
     *
     * @return list<array{id: int, email: string, locale: string}>
     */
    private function standaloneSubscribers(): array
    {
        $rows = $this->db()->fetchAllAssociative('SELECT n.id, n.email, n.locale, n.legal_records FROM newsletter_subscriber n WHERE NOT EXISTS (SELECT 1 FROM wave_user u WHERE LOWER(u.email) = LOWER(n.email) AND u.deleted_at IS NULL) ORDER BY n.id');
        $subscribers = [];
        foreach ($rows as $row) {
            $records = $row['legal_records'] === null ? [] : json_decode((string) $row['legal_records'], true);
            if (is_array($records) && ($records['newsletter']['enabled'] ?? false) === true) {
                $subscribers[] = ['id' => (int) $row['id'], 'email' => (string) $row['email'], 'locale' => (string) $row['locale']];
            }
        }

        return $subscribers;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function roleFilter(string $role): array
    {
        return match ($role) {
            'creators' => [' AND u.role = :role', ['role' => 'ROLE_CREATOR']],
            'companies' => [' AND u.role = :role', ['role' => 'ROLE_COMPANY']],
            default => ['', []],
        };
    }

    private function db(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
