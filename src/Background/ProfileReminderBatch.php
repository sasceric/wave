<?php

namespace App\Background;

use App\Account\EmailPreferences;
use App\Account\EmailRecipient;
use App\Account\EmailTemplateRenderer;
use App\Account\ProfileReminderEmailCopy;
use App\Account\ProfileStrength;
use App\Entity\EmailTemplate;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Four profile tips emails while a profile is incomplete: 1, 4, 10 and 21 days after an
 * approved account is first seen. The series stops at 100% or when tips are switched off.
 */
final class ProfileReminderBatch
{
    public const SCHEDULE_DAYS = [1, 4, 10, 21];
    private const BATCH = 100;

    /** @var array<string, array<string, mixed>> */
    private array $catalogs = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly EmailTemplateRenderer $templates,
        private readonly EmailPreferences $preferences,
        private readonly JobDispatcher $jobs,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')] private readonly string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')] private readonly string $fromName,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function run(bool $continue = true): int
    {
        $db = $this->entityManager->getConnection();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rows = $db->fetchAllAssociative(
            "SELECT id, profile_reminder_stage, profile_reminder_next_at FROM wave_user WHERE deleted_at IS NULL AND approved = TRUE AND email_verified = TRUE AND profile_tips_enabled = TRUE AND admin = FALSE AND moderator = FALSE AND presentation_demo_key IS NULL AND role IN ('ROLE_CREATOR', 'ROLE_COMPANY') AND profile_reminder_stage < ? AND (profile_reminder_next_at IS NULL OR profile_reminder_next_at <= ?) ORDER BY id LIMIT ".self::BATCH,
            [count(self::SCHEDULE_DAYS), $now->format('Y-m-d H:i:s')],
        );
        $sent = 0;
        foreach ($rows as $row) {
            $sent += $this->process($db, $row, $now);
        }
        if ($continue && count($rows) === self::BATCH) {
            $this->jobs->enqueue('ProfileReminderTask');
        }

        return $sent;
    }

    /** @param array<string, mixed> $row */
    private function process(Connection $db, array $row, \DateTimeImmutable $now): int
    {
        $id = (int) $row['id'];
        $stage = (int) $row['profile_reminder_stage'];
        $nextAt = $row['profile_reminder_next_at'];
        // First time an approved account is seen: start its series from now.
        if ($nextAt === null) {
            $db->executeStatement('UPDATE wave_user SET profile_reminder_next_at = ? WHERE id = ? AND profile_reminder_next_at IS NULL', [$this->after($now, self::SCHEDULE_DAYS[$stage] - ($stage > 0 ? self::SCHEDULE_DAYS[$stage - 1] : 0)), $id]);

            return 0;
        }
        $user = $this->entityManager->find(User::class, $id);
        $locale = $user instanceof User && in_array($user->getPreferredLocale(), ProfileReminderEmailCopy::locales(), true) ? $user->getPreferredLocale() : 'bs';
        $strength = $user instanceof User ? ProfileStrength::forUser($user, $locale) : null;
        if (!$user instanceof User || $strength === null || $strength['score'] >= 100) {
            // Nothing left to suggest: close the series instead of rescanning this account.
            $db->executeStatement('UPDATE wave_user SET profile_reminder_stage = ?, profile_reminder_next_at = NULL WHERE id = ? AND profile_reminder_stage = ?', [count(self::SCHEDULE_DAYS), $id, $stage]);

            return 0;
        }
        $following = $stage + 1 < count(self::SCHEDULE_DAYS) ? $this->after($now, self::SCHEDULE_DAYS[$stage + 1] - self::SCHEDULE_DAYS[$stage]) : null;
        // Claim this stage first so overlapping runs never send it twice.
        $claimed = $db->executeStatement('UPDATE wave_user SET profile_reminder_stage = ?, profile_reminder_next_at = ? WHERE id = ? AND profile_reminder_stage = ? AND profile_reminder_next_at = ?', [$stage + 1, $following, $id, $stage, $nextAt]);
        if ($claimed !== 1) {
            return 0;
        }
        try {
            $this->send($user, $locale, $stage + 1, $strength);
        } catch (\Throwable $error) {
            $db->executeStatement('UPDATE wave_user SET profile_reminder_stage = ?, profile_reminder_next_at = ? WHERE id = ? AND profile_reminder_stage = ?', [$stage, $nextAt, $id, $stage + 1]);
            throw $error;
        }
        $this->record($id, $stage + 1, false);

        return 1;
    }

    /**
     * Admin "send now": repeat the last reminder or send the next one early.
     * Returns an ApiMessages key when the email cannot be sent, null when it was sent.
     */
    public function sendManually(User $user, int $stage): ?string
    {
        if ($user->isDeleted() || !$user->isApproved() || !$user->isEmailVerified()) {
            return 'profile_reminder_unavailable';
        }
        if (!$user->isProfileTipsEnabled()) {
            return 'profile_tips_disabled';
        }
        $sent = $user->getProfileReminderStage();
        if ($stage < 1 || $stage > count(self::SCHEDULE_DAYS) || ($stage !== $sent && $stage !== $sent + 1)) {
            return 'invalid_request';
        }
        $locale = in_array($user->getPreferredLocale(), ProfileReminderEmailCopy::locales(), true) ? $user->getPreferredLocale() : 'bs';
        $strength = ProfileStrength::forUser($user, $locale);
        if ($strength === null || $strength['score'] >= 100) {
            return 'profile_already_complete';
        }
        $db = $this->entityManager->getConnection();
        if ($stage === $sent + 1) {
            // Sending the next email early moves the automatic series on from today.
            $following = $stage < count(self::SCHEDULE_DAYS)
                ? $this->after(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), self::SCHEDULE_DAYS[$stage] - self::SCHEDULE_DAYS[$stage - 1])
                : null;
            if ($db->executeStatement('UPDATE wave_user SET profile_reminder_stage = ?, profile_reminder_next_at = ? WHERE id = ? AND profile_reminder_stage = ?', [$stage, $following, $user->getId(), $sent]) !== 1) {
                return 'invalid_request';
            }
        }
        $this->send($user, $locale, $stage, $strength);
        $this->record((int) $user->getId(), $stage, true);
        $this->entityManager->refresh($user);

        return null;
    }

    /** Keep the latest sends for the admin overview (bounded, newest last). */
    private function record(int $id, int $stage, bool $manual): void
    {
        $db = $this->entityManager->getConnection();
        $raw = $db->fetchOne('SELECT profile_reminder_log FROM wave_user WHERE id = ?', [$id]);
        $log = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $log = is_array($log) ? $log : [];
        $log[] = ['stage' => $stage, 'at' => gmdate(DATE_ATOM), 'manual' => $manual];
        $db->executeStatement('UPDATE wave_user SET profile_reminder_log = ? WHERE id = ?', [json_encode(array_slice($log, -20), JSON_THROW_ON_ERROR), $id]);
    }

    /**
     * @param array{kind: string, score: int, level: int, missing: list<array{key: string, points: int}>} $strength
     */
    public function send(User $user, string $locale, int $stage, array $strength): void
    {
        $id = (int) $user->getId();
        $name = EmailRecipient::name($user);
        $variables = $this->variables($locale, $strength['kind'] === 'creator' ? 'creator' : 'company', $stage, $strength['score'], $strength['missing'], $name, [
            'unsubscribeUrl' => $this->preferences->pageUrl($id, EmailPreferences::PROFILE_TIPS, $locale),
            'settingsUrl' => $this->preferences->pageUrl($id, null, $locale),
        ]);
        $key = self::templateKey($stage);
        // Admins can edit each reminder per language (Admin → Email templates).
        $custom = $this->entityManager->getRepository(EmailTemplate::class)->findOneBy(['templateKey' => $key, 'locale' => $locale]);
        $subject = $custom instanceof EmailTemplate
            ? strtr($custom->getSubject(), ['{{score}}' => $variables['score'], '{{firstStep}}' => $variables['firstStep']])
            : $variables['subject'];
        unset($variables['subject']);
        $text = implode("\n\n", [
            $variables['greeting'], $variables['message'], $variables['scoreLabel'].': '.$variables['score'].'%',
            $variables['stepsLabel'].":\n".$variables['steps'], $variables['buttonLabel'].":\n".$variables['actionUrl'],
            $variables['guideLabel'].":\n".$variables['guideUrl'],
            $variables['footer']."\n".$variables['unsubscribeLabel'].': '.$variables['unsubscribeUrl'],
        ]);
        $email = new Email()
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($user->getEmail(), $name))
            ->subject($subject)
            ->text($text)
            ->html($this->templates->render($key, $variables, $custom instanceof EmailTemplate ? $custom->getHtmlBody() : null));
        $this->preferences->addUnsubscribeHeaders($email, $id, EmailPreferences::PROFILE_TIPS);
        $this->mailer->send($email);
    }

    public static function templateKey(int $stage): string
    {
        return 'profile_reminder_'.max(1, min(count(self::SCHEDULE_DAYS), $stage));
    }

    /** Subject shown in the admin editor; {{score}} and {{firstStep}} are filled in when sending. */
    public function defaultSubject(int $stage, string $locale): string
    {
        return ProfileReminderEmailCopy::forStage($locale, 'creator', $stage, '{{score}}', '{{firstStep}}')['subject'];
    }

    /** @return array<string, string> */
    public function previewVariables(int $stage, string $locale): array
    {
        $variables = $this->variables($locale, 'creator', $stage, 35, [['key' => 'works', 'points' => 20], ['key' => 'bio', 'points' => 15]], 'Amina Kovač', [
            'unsubscribeUrl' => 'https://wave.ba/example-unsubscribe', 'settingsUrl' => 'https://wave.ba/example-email-settings',
        ]);
        unset($variables['subject']);

        return $variables;
    }

    /**
     * @param list<array{key: string, points: int}> $missing
     * @param array{unsubscribeUrl: string, settingsUrl: string} $links
     * @return array<string, string>
     */
    private function variables(string $locale, string $audience, int $stage, int $score, array $missing, string $name, array $links): array
    {
        $locale = in_array($locale, ProfileReminderEmailCopy::locales(), true) ? $locale : 'bs';
        $steps = $this->steps($locale);
        $missing = array_slice($missing, 0, 2);
        $first = $steps[$missing[0]['key'] ?? '']['title'] ?? '';
        $copy = ProfileReminderEmailCopy::forStage($locale, $audience, $stage, $score, $first);
        $lines = array_map(static fn (array $step): string => '• '.($steps[$step['key']]['title'] ?? $step['key']).' (+'.$step['points'].'%)'
            .(isset($steps[$step['key']]['why']) ? "\n".$steps[$step['key']]['why'] : ''), $missing);

        return [
            'subject' => $copy['subject'],
            'locale' => match ($locale) { 'sr' => 'sr-Latn', 'cnr' => 'cnr-Latn-ME', default => $locale },
            'preheader' => $copy['preheader'], 'eyebrow' => $copy['eyebrow'], 'heading' => $copy['heading'],
            'greeting' => EmailRecipient::greeting($copy['greeting'], $name), 'message' => $copy['message'], 'recipientName' => $name,
            'scoreLabel' => $copy['scoreLabel'], 'score' => (string) $score, 'firstStep' => $first,
            'stepsLabel' => $copy['stepsLabel'], 'steps' => implode("\n\n", $lines),
            'buttonLabel' => $copy['buttonLabel'], 'actionUrl' => $this->preferences->routeUrl('account-dashboard', $locale),
            'guideLabel' => $copy['guideLabel'], 'guideUrl' => $this->preferences->routeUrl('account-profile-guide', $locale),
            'footer' => $copy['footer'],
            'unsubscribeLabel' => $copy['unsubscribeLabel'], 'unsubscribeUrl' => $links['unsubscribeUrl'],
            'settingsLabel' => $copy['settingsLabel'], 'settingsUrl' => $links['settingsUrl'],
        ];
    }

    private function after(\DateTimeImmutable $now, int $days): string
    {
        return $now->modify('+'.max(1, $days).' days')->format('Y-m-d H:i:s');
    }

    /**
     * Step titles and reasons shared with the account pages (frontend catalogs).
     *
     * @return array<string, array{title?: string, why?: string}>
     */
    private function steps(string $locale): array
    {
        if (!isset($this->catalogs[$locale])) {
            $catalog = json_decode((string) file_get_contents($this->projectDir.'/frontend/src/locales/'.$locale.'.json'), true, flags: JSON_THROW_ON_ERROR);
            $steps = is_array($catalog['profileStrength']['steps'] ?? null) ? $catalog['profileStrength']['steps'] : [];
            // vue-i18n escapes literal characters as {'x'}; emails need plain text.
            array_walk_recursive($steps, static function (mixed &$value): void {
                if (is_string($value)) $value = preg_replace("/\\{'(.)'\\}/u", '$1', $value) ?? $value;
            });
            $this->catalogs[$locale] = $steps;
        }

        return $this->catalogs[$locale];
    }
}
