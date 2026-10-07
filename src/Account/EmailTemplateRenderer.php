<?php

namespace App\Account;

use InvalidArgumentException;
use RuntimeException;

final class EmailTemplateRenderer
{
    private const TEMPLATES = [
        'credits_activated' => 'marketplace_notification.html',
        'account_action' => 'account_action.html',
        'verify' => 'account_action.html',
        'reset' => 'account_action.html',
        'registered' => 'account_action.html',
        'approval' => 'account_action.html',
        'contact' => 'contact.html',
        'subscribed' => 'newsletter_subscribed.html',
        'application_received' => 'marketplace_notification.html',
        'creator_inquiry_received' => 'marketplace_notification.html',
        'creator_inquiry_accepted' => 'marketplace_notification.html',
        'creator_hired' => 'marketplace_notification.html',
        'unread_message_reminder' => 'marketplace_notification.html',
    ];

    private const REQUIRED_VARIABLES = [
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
        if (!isset(self::TEMPLATES[$template])) {
            throw new InvalidArgumentException(sprintf('Unknown email template "%s".', $template));
        }
        foreach (self::REQUIRED_VARIABLES[$template] as $name) {
            if (!isset($variables[$name]) || !is_string($variables[$name])) {
                throw new InvalidArgumentException(sprintf('Email template variable "%s" must be a string.', $name));
            }
        }

        $source ??= $this->getDefaultHtml($template);
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

        return $html;
    }

    /**
     * @return list<string>
     */
    public function variables(string $template): array
    {
        if (!isset(self::REQUIRED_VARIABLES[$template])) {
            throw new InvalidArgumentException(sprintf('Unknown email template "%s".', $template));
        }

        return self::REQUIRED_VARIABLES[$template];
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
