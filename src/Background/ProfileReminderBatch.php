<?php

namespace App\Background;

use App\Account\EmailPreferences;
use App\Account\EmailRecipient;
use App\Account\EmailTemplateRenderer;
use App\Account\ProfileReminderEmailCopy;
use App\Account\ProfileStrength;
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

        return 1;
    }

    /**
     * @param array{kind: string, score: int, level: int, missing: list<array{key: string, points: int}>} $strength
     */
    public function send(User $user, string $locale, int $stage, array $strength): void
    {
        $audience = $strength['kind'] === 'creator' ? 'creator' : 'company';
        $steps = $this->steps($locale);
        $missing = array_slice($strength['missing'], 0, 2);
        $first = $steps[$missing[0]['key'] ?? '']['title'] ?? '';
        $copy = ProfileReminderEmailCopy::forStage($locale, $audience, $stage, $strength['score'], $first);
        $lines = array_map(static fn (array $step): string => '• '.($steps[$step['key']]['title'] ?? $step['key']).' (+'.$step['points'].'%)'
            .(isset($steps[$step['key']]['why']) ? "\n".$steps[$step['key']]['why'] : ''), $missing);
        $id = (int) $user->getId();
        $name = EmailRecipient::name($user);
        $variables = [
            'locale' => match ($locale) { 'sr' => 'sr-Latn', 'cnr' => 'cnr-Latn-ME', default => $locale },
            'preheader' => $copy['preheader'], 'eyebrow' => $copy['eyebrow'], 'heading' => $copy['heading'],
            'greeting' => EmailRecipient::greeting($copy['greeting'], $name), 'message' => $copy['message'], 'recipientName' => $name,
            'scoreLabel' => $copy['scoreLabel'], 'score' => (string) $strength['score'],
            'stepsLabel' => $copy['stepsLabel'], 'steps' => implode("\n\n", $lines),
            'buttonLabel' => $copy['buttonLabel'], 'actionUrl' => $this->preferences->routeUrl('account-dashboard', $locale),
            'guideLabel' => $copy['guideLabel'], 'guideUrl' => $this->preferences->routeUrl('account-profile-guide', $locale),
            'footer' => $copy['footer'],
            'unsubscribeLabel' => $copy['unsubscribeLabel'], 'unsubscribeUrl' => $this->preferences->pageUrl($id, EmailPreferences::PROFILE_TIPS, $locale),
            'settingsLabel' => $copy['settingsLabel'], 'settingsUrl' => $this->preferences->pageUrl($id, null, $locale),
        ];
        $text = implode("\n\n", [
            $variables['greeting'], $copy['message'], $copy['scoreLabel'].': '.$strength['score'].'%',
            $copy['stepsLabel'].":\n".$variables['steps'], $copy['buttonLabel'].":\n".$variables['actionUrl'],
            $copy['guideLabel'].":\n".$variables['guideUrl'],
            $copy['footer']."\n".$copy['unsubscribeLabel'].': '.$variables['unsubscribeUrl'],
        ]);
        $email = new Email()
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($user->getEmail(), $name))
            ->subject($copy['subject'])
            ->text($text)
            ->html($this->templates->render('profile_reminder', $variables));
        $this->preferences->addUnsubscribeHeaders($email, $id, EmailPreferences::PROFILE_TIPS);
        $this->mailer->send($email);
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
