<?php

namespace App\Account;

use App\Service\SiteOrigin;
use InvalidArgumentException;
use RuntimeException;

final class EmailTemplateRenderer
{
    public function __construct(private readonly SiteOrigin $origin)
    {
    }

    private const TEMPLATES = [
        'campaign_alert' => 'campaign_alert.html',
        // One layout for all four profile tips emails; the text of each email is filled in per step.
        'profile_reminder' => 'profile_reminder.html',
        'credit_code_assigned' => 'credit_code_assigned.html',
        'credits_activated' => 'marketplace_notification.html',
        'account_action' => 'account_action.html',
        'verify' => 'account_action.html',
        'verification_reminder' => 'account_action.html',
        'profile_tip' => 'account_action.html',
        'reset' => 'account_action.html',
        'registered' => 'account_action.html',
        'approval' => 'account_action.html',
        'account_deleted' => 'account_action.html',
        'terms_updated' => 'account_action.html',
        'contact' => 'contact.html',
        'subscribed' => 'newsletter_subscribed.html',
        'application_received' => 'marketplace_notification.html',
        'creator_inquiry_received' => 'marketplace_notification.html',
        'creator_inquiry_accepted' => 'marketplace_notification.html',
        'creator_hired' => 'marketplace_notification.html',
        'unread_message_reminder' => 'marketplace_notification.html',
    ];

    private const REQUIRED_VARIABLES = [
        'verification_reminder' => ['locale', 'preheader', 'eyebrow', 'heading', 'greeting', 'message', 'buttonLabel', 'actionUrl', 'expiration', 'security', 'fallback', 'footer'],
        'profile_tip' => ['locale', 'preheader', 'eyebrow', 'heading', 'greeting', 'message', 'buttonLabel', 'actionUrl', 'expiration', 'security', 'fallback', 'footer'],
        'profile_reminder' => ['locale', 'preheader', 'eyebrow', 'heading', 'greeting', 'message', 'scoreLabel', 'score', 'stepsLabel', 'steps', 'buttonLabel', 'actionUrl', 'guideLabel', 'guideUrl', 'footer', 'unsubscribeLabel', 'unsubscribeUrl', 'settingsLabel', 'settingsUrl', 'firstStep', 'subject'],
        'campaign_alert' => ['locale', 'preheader', 'eyebrow', 'heading', 'greeting', 'message', 'campaignsLabel', 'campaigns', 'buttonLabel', 'actionUrl', 'footer', 'settingsLabel', 'settingsUrl'],
        'credit_code_assigned' => ['locale', 'preheader', 'eyebrow', 'heading', 'greeting', 'message', 'name', 'amount', 'code', 'firstLabel', 'firstValue', 'secondLabel', 'secondValue', 'detailsLabel', 'details', 'buttonLabel', 'actionUrl', 'footer'],
        'credits_activated' => ['locale', 'preheader', 'eyebrow', 'heading', 'greeting', 'message', 'firstLabel', 'firstValue', 'secondLabel', 'secondValue', 'detailsLabel', 'details', 'applicationLabel', 'campaignLabel', 'buttonLabel', 'actionUrl', 'footer'],
        'account_action' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'greeting',
            'message',
            'buttonLabel',
            'actionUrl',
            'expiration',
            'security',
            'fallback',
            'footer',
        ],
        'verify' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'greeting',
            'message',
            'buttonLabel',
            'actionUrl',
            'expiration',
            'security',
            'fallback',
            'footer',
        ],
        'reset' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'greeting',
            'message',
            'buttonLabel',
            'actionUrl',
            'expiration',
            'security',
            'fallback',
            'footer',
        ],
        'registered' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'greeting',
            'message',
            'buttonLabel',
            'actionUrl',
            'expiration',
            'security',
            'fallback',
            'footer',
        ],
        'approval' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'greeting',
            'message',
            'buttonLabel',
            'actionUrl',
            'expiration',
            'security',
            'fallback',
            'footer',
        ],
        'account_deleted' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'greeting',
            'message',
            'buttonLabel',
            'actionUrl',
            'expiration',
            'security',
            'fallback',
            'footer',
        ],
        'terms_updated' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'greeting',
            'message',
            'buttonLabel',
            'actionUrl',
            'expiration',
            'security',
            'fallback',
            'footer',
        ],
        'contact' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'roleLabel',
            'nameLabel',
            'emailLabel',
            'interestLabel',
            'messageLabel',
            'role',
            'name',
            'email',
            'interest',
            'message',
            'footer',
        ],
        'subscribed' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'greeting',
            'message',
            'footer',
        ],
        'application_received' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'greeting',
            'message',
            'firstLabel',
            'firstValue',
            'secondLabel',
            'secondValue',
            'detailsLabel',
            'details',
            'buttonLabel',
            'actionUrl',
            'footer',
        ],
        'creator_inquiry_received' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'greeting',
            'message',
            'firstLabel',
            'firstValue',
            'secondLabel',
            'secondValue',
            'detailsLabel',
            'details',
            'buttonLabel',
            'actionUrl',
            'footer',
        ],
        'creator_inquiry_accepted' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'greeting',
            'message',
            'firstLabel',
            'firstValue',
            'secondLabel',
            'secondValue',
            'detailsLabel',
            'details',
            'buttonLabel',
            'actionUrl',
            'footer',
        ],
        'creator_hired' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'greeting',
            'message',
            'firstLabel',
            'firstValue',
            'secondLabel',
            'secondValue',
            'detailsLabel',
            'details',
            'buttonLabel',
            'actionUrl',
            'footer',
        ],
        'unread_message_reminder' => [
            'locale',
            'preheader',
            'eyebrow',
            'heading',
            'greeting',
            'message',
            'firstLabel',
            'firstValue',
            'secondLabel',
            'secondValue',
            'detailsLabel',
            'details',
            'buttonLabel',
            'actionUrl',
            'footer',
        ],
    ];

    /**
     * @param array<string, string> $variables
     */
    public function render(string $template, array $variables, ?string $source = null): string
    {
        // Optional for older/custom callers that only know an email address.
        $variables += ['recipientName' => ''];
        if (!isset(self::TEMPLATES[$template])) {
            throw new InvalidArgumentException(sprintf('Unknown email template "%s".', $template));
        }
        foreach (self::REQUIRED_VARIABLES[$template] as $name) {
            if (!isset($variables[$name]) || !is_string($variables[$name])) {
                throw new InvalidArgumentException(sprintf('Email template variable "%s" must be a string.', $name));
            }
        }

        $source = $source === null ? $this->getDefaultHtml($template) : $this->prepareHtml($source);
        $html = preg_replace_callback(
            '/\{\{\s*([A-Za-z][A-Za-z0-9_]*)\s*\}\}/',
            static function (array $matches) use ($variables): string {
                $name = $matches[1];
                if (!array_key_exists($name, $variables)) {
                    throw new InvalidArgumentException(sprintf('Unknown email template variable "%s".', $name));
                }

                return htmlspecialchars($variables[$name], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $source,
        );
        if (!is_string($html)) {
            throw new RuntimeException('Could not render the email template.');
        }

        return $html;
    }

    /** Upgrade only the original stock header; preserve custom copy and layouts. */
    public function prepareHtml(string $source): string
    {
        $pattern = '~<!-- wave:brand -->|<table role="presentation" cellspacing="0" cellpadding="0" border="0">\s*<tr>\s*<td[^>]*background-color:\s*#e7967c[^>]*>w</td>\s*<td[^>]*>wave</td>\s*</tr>\s*</table>~';
        $html = preg_replace_callback($pattern, function (): string {
            $header = file_get_contents(dirname(__DIR__, 2).'/templates/emails/_brand.html');
            if (!is_string($header)) {
                throw new RuntimeException('Could not read the email brand header.');
            }

            return strtr($header, [
                '{{brandIconUrl}}' => htmlspecialchars($this->origin->url('/images/email/wave-icon-v1.png'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{{brandLogoUrl}}' => htmlspecialchars($this->origin->url('/images/email/wave-logo-v1.png'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ]);
        }, $source);
        if (!is_string($html)) {
            throw new RuntimeException('Could not prepare the email brand header.');
        }

        return $html;
    }

    public function getDefaultHtml(string $template): string
    {
        if (!isset(self::TEMPLATES[$template])) {
            throw new InvalidArgumentException(sprintf('Unknown email template "%s".', $template));
        }

        $templatePath = dirname(__DIR__, 2).'/templates/emails/'.self::TEMPLATES[$template];
        $html = file_get_contents($templatePath);
        if (!is_string($html)) {
            throw new RuntimeException(sprintf('Could not read email template "%s".', $template));
        }

        return $this->prepareHtml($html);
    }

    /**
     * @return list<string>
     */
    public function variables(string $template): array
    {
        if (!isset(self::REQUIRED_VARIABLES[$template])) {
            throw new InvalidArgumentException(sprintf('Unknown email template "%s".', $template));
        }

        return array_values(array_unique([...self::REQUIRED_VARIABLES[$template], 'recipientName']));
    }

    /**
     * @return list<string>
     */
    public function unknownVariables(string $template, string $source): array
    {
        $variables = $this->variables($template);
        preg_match_all('/\{\{\s*([A-Za-z][A-Za-z0-9_]*)\s*\}\}/', $source, $matches);

        return array_values(array_diff(array_unique($matches[1]), $variables));
    }

    public function containsUnsafeMarkup(string $source): bool
    {
        return preg_match(
            '/<\s*(?:script|iframe|object|embed|form|svg|math|video|audio)\b'
            .'|\bon[a-z]+\s*=|\bsrcdoc\s*=|\b(?:javascript|vbscript|data)\s*:/i',
            $source,
        ) === 1;
    }
}
