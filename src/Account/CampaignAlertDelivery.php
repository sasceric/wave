<?php

namespace App\Account;

use App\Background\JobDispatcher;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\{Address, Email};

final class CampaignAlertDelivery
{
    public function __construct(
        private readonly Connection $db, private readonly JobDispatcher $jobs,
        private readonly TransportInterface $transport, private readonly EmailTemplateRenderer $templates,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')] private readonly string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')] private readonly string $fromName,
        #[Autowire('%env(APP_BASE_URL)%')] private readonly string $baseUrl,
        private readonly EmailPreferences $preferences,
    ) {}

    public function schedule(int $after = 0): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rows = $this->db->fetchAllAssociative("SELECT p.user_id, p.checked_at FROM campaign_alert_preference p JOIN wave_user u ON u.id = p.user_id WHERE p.user_id > :after AND p.enabled = TRUE AND u.deleted_at IS NULL AND u.email_verified = TRUE AND u.approved = TRUE AND ((p.frequency = 'daily' AND p.checked_at <= :daily) OR (p.frequency = 'weekly' AND p.checked_at <= :weekly)) ORDER BY p.user_id LIMIT 100", ['after' => $after, 'daily' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'weekly' => $now->modify('-7 days')->format('Y-m-d H:i:s')]);
        foreach ($rows as $row) $this->jobs->enqueue('CampaignAlertEmailMessage', ['userId' => (int) $row['user_id'], 'since' => $row['checked_at']], 'campaign-alert:'.$row['user_id'].':'.$row['checked_at']);
        if (count($rows) === 100) $this->jobs->enqueue('CampaignAlertTask', ['after' => (int) end($rows)['user_id']]);
    }

    public function deliver(int $id, string $since): void
    {
        // Lock and reread preferences at delivery time: an opt-out or account switch cannot leak mail.
        $this->db->transactional(function () use ($id, $since): void {
            $lock = $this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform ? '' : ' FOR UPDATE';
            $preference = $this->db->fetchAssociative('SELECT * FROM campaign_alert_preference WHERE user_id = ?'.$lock, [$id]);
            if (!$preference || !filter_var($preference['enabled'], FILTER_VALIDATE_BOOLEAN) || $preference['checked_at'] !== $since) return;
            $user = $this->db->fetchAssociative("SELECT u.*, COALESCE(NULLIF(TRIM(p.name), ''), r.display_name) AS recipient_name FROM wave_user u JOIN creator r ON r.owner_id = u.id LEFT JOIN company p ON p.owner_id = u.id WHERE u.id = ? AND u.deleted_at IS NULL AND u.email_verified = TRUE AND u.approved = TRUE AND u.phone IS NOT NULL AND u.phone <> '' AND u.city IS NOT NULL AND u.city <> '' AND u.country_code IS NOT NULL AND u.country_code <> '' AND r.category <> ''", [$id]);
            if (!$user) {
                $this->db->executeStatement('UPDATE campaign_alert_preference SET checked_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s'), $id]);
                return;
            }
            $until = gmdate('Y-m-d H:i:s');
            $rows = $this->matches($id, $since, $until, json_decode($preference['categories'], true, flags: JSON_THROW_ON_ERROR), json_decode($preference['countries'], true, flags: JSON_THROW_ON_ERROR));
            if ($rows !== []) {
                $locale = $user['preferred_locale'];
                $copy = CampaignAlertEmailCopy::forLocale($locale);
                // Signed page: switching alerts off works without signing in.
                $settingsUrl = $this->preferences->pageUrl($id, null, $locale);
                $details = implode("\n", array_map(fn (array $row): string => $row['title'].' · '.$row['name'].' · '.$row['budget_min'].'–'.$row['budget_max'].' '.$row['currency']."\n".rtrim($this->baseUrl, '/').'/kampanje/'.$row['slug'], $rows));
                $url = rtrim($this->baseUrl, '/').'/kampanje/'.$rows[0]['slug'];
                $variables = [
                    'locale' => $locale, 'preheader' => $copy['message'], 'eyebrow' => 'WAVE', 'heading' => $copy['subject'],
                    'greeting' => EmailRecipient::greeting($copy['greeting'], $user['recipient_name']), 'message' => $copy['message'],
                    'recipientName' => EmailRecipient::cleanName($user['recipient_name']),
                    'campaignsLabel' => $copy['campaigns'], 'campaigns' => $details, 'buttonLabel' => $copy['view'], 'actionUrl' => $url,
                    'footer' => $copy['footer'], 'settingsUrl' => $settingsUrl, 'settingsLabel' => $copy['settings'],
                ];
                // Admins can edit this email per language (Admin → Email templates).
                $custom = $this->db->fetchAssociative('SELECT subject, html_body FROM email_template WHERE template_key = ? AND locale = ?', ['campaign_alert', $locale]);
                $email = new Email()->from(new Address($this->fromAddress, $this->fromName))->to(new Address($user['email'], EmailRecipient::cleanName($user['recipient_name'])))->subject($custom ? (string) $custom['subject'] : $copy['subject'])
                    ->text($variables['greeting']."\n\n".$copy['message']."\n\n".$details."\n\n".$variables['footer'].' '.$settingsUrl)
                    ->html($this->templates->render('campaign_alert', $variables, $custom ? (string) $custom['html_body'] : null));
                $this->preferences->addUnsubscribeHeaders($email, $id, EmailPreferences::CAMPAIGN_ALERTS);
                $this->transport->send($email);
            }
            // Empty windows advance too, preventing repeated scans or empty digests.
            $this->db->executeStatement('UPDATE campaign_alert_preference SET checked_at = ? WHERE user_id = ?', [$until, $id]);
        });
    }

    public function defaultSubject(string $locale): string
    {
        return CampaignAlertEmailCopy::forLocale($locale)['subject'];
    }

    /** @return array<string, string> */
    public function previewVariables(string $locale): array
    {
        $copy = CampaignAlertEmailCopy::forLocale($locale);

        return [
            'locale' => $locale, 'preheader' => $copy['message'], 'eyebrow' => 'WAVE', 'heading' => $copy['subject'],
            'greeting' => EmailRecipient::greeting($copy['greeting'], 'Amina Kovač'), 'message' => $copy['message'], 'recipientName' => 'Amina Kovač',
            'campaignsLabel' => $copy['campaigns'],
            'campaigns' => "Summer campaign · Wave Studio · 300–800 BAM\nhttps://wave.ba/kampanje/summer-campaign\nCafé stories · Kafeterija Mlin · 150–250 BAM\nhttps://wave.ba/kampanje/cafe-stories",
            'buttonLabel' => $copy['view'], 'actionUrl' => 'https://wave.ba/kampanje/summer-campaign',
            'footer' => $copy['footer'], 'settingsUrl' => 'https://wave.ba/example-email-settings', 'settingsLabel' => $copy['settings'],
        ];
    }

    public function matches(int $id, string $since, string $until, array $categories, array $countries): array
    {
        $params = ['id' => $id, 'since' => $since, 'until' => $until, 'today' => gmdate('Y-m-d')];
        $conditions = ["c.status = 'open'", 'c.closes_at >= :today', 'c.published_at > :since', 'c.published_at <= :until', 'u.approved = TRUE', 'u.email_verified = TRUE', 'u.deleted_at IS NULL', 'u.hide_my_account = FALSE', 'u.presentation_demo_key IS NULL',
            'NOT EXISTS (SELECT 1 FROM user_block b WHERE b.cleared_at IS NULL AND ((b.blocker_id = :id AND b.blocked_id = u.id) OR (b.blocked_id = :id AND b.blocker_id = u.id)))',
            'NOT EXISTS (SELECT 1 FROM campaign_application a JOIN creator r ON r.id = a.creator_id WHERE a.campaign_id = c.id AND r.owner_id = :id)'];
        if ($categories !== []) {
            $parts = [];
            foreach ($categories as $index => $value) {
                $parts[] = "(c.category = :cat$index OR CAST(c.categories AS TEXT) LIKE :json$index ESCAPE '!')";
                $params['cat'.$index] = $value;
                $params['json'.$index] = '%'.strtr(json_encode($value, JSON_THROW_ON_ERROR), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            }
            $conditions[] = '('.implode(' OR ', $parts).')';
        }
        if ($countries !== []) {
            $parts = [];
            foreach ($countries as $index => $value) { $parts[] = ':country'.$index; $params['country'.$index] = $value; }
            $conditions[] = 'c.country_code IN ('.implode(', ', $parts).')';
        }
        return $this->db->fetchAllAssociative('SELECT c.title, c.slug, c.budget_min, c.budget_max, c.currency, p.name FROM campaign c JOIN company p ON p.id = c.company_id JOIN wave_user u ON u.id = p.owner_id WHERE '.implode(' AND ', $conditions).' ORDER BY c.published_at DESC, c.id DESC LIMIT 5', $params);
    }
}
