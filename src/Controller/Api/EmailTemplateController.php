<?php

namespace App\Controller\Api;

use App\Account\AccountEmailSender;
use App\Account\CampaignAlertDelivery;
use App\Account\ContactEmailCopy;
use App\Account\EmailTemplateRenderer;
use App\Api\ApiAccess;
use App\Background\ProfileReminderBatch;
use App\Api\JsonPayload;
use App\Entity\EmailTemplate;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Newsletter\NewsletterSubscriberEmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class EmailTemplateController
{
    private const TEMPLATE_KEYS = [
        'verify',
        'reset',
        'registered',
        'approval',
        'contact',
        'subscribed',
        'application_received',
        'creator_inquiry_received',
        'creator_inquiry_accepted',
        'creator_hired',
        'unread_message_reminder',
        'campaign_invitation',
        'credits_activated',
        'credit_code_assigned',
        'verification_reminder',
        'profile_tip',
        'profile_reminder',
        'campaign_alert',
        'account_deleted',
        'terms_updated',
        'approval_company',
        'admin_broadcast',
        'pre_approval',
    ];
    /** Text variables admins can reword without HTML ("Tekst" tab). */
    private const TEXT_KEYS = [
        'preheader', 'eyebrow', 'heading', 'message', 'buttonLabel', 'footer',
        'firstLabel', 'secondLabel', 'detailsLabel', 'scoreLabel', 'stepsLabel', 'guideLabel',
        'campaignsLabel', 'settingsLabel', 'unsubscribeLabel',
        'roleLabel', 'nameLabel', 'emailLabel', 'interestLabel', 'messageLabel',
        ...\App\Account\ApprovalWelcomeEmailCopy::TEXT_KEYS,
        ...\App\Account\ProfilePreparationEmailCopy::LAYOUT_KEYS,
    ];
    /** Text that is data, or that changes per email, stays out of the "Tekst" tab. */
    private const TEXT_EXCLUDED = [
        'contact' => ['message'],
        'profile_reminder' => ['preheader', 'eyebrow', 'heading', 'message'],
        // Written per send in Admin → Slanje e-mailova.
        'admin_broadcast' => ['preheader', 'heading', 'message', 'buttonLabel'],
    ];
    private const MULTILINE_KEYS = ['message', 'footer', 'imageText', 'bioText', 'socialText', 'nextText'];

    #[Route('/api/admin/email-templates', name: 'api_admin_email_templates', methods: ['GET'])]
    public function list(
        Request $request,
        Security $security,
        EntityManagerInterface $entityManager,
        AccountEmailSender $accountEmailSender,
        NewsletterSubscriberEmailSender $subscriberEmailSender,
        EmailTemplateRenderer $renderer,
        ProfileReminderBatch $profileReminders,
        CampaignAlertDelivery $campaignAlerts,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')] string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')] string $fromName,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $customizedLocales = [];
        foreach ($entityManager->getRepository(EmailTemplate::class)->findAll() as $row) {
            if (!$row->isEmpty()) {
                $customizedLocales[$row->getTemplateKey()][] = $row->getLocale();
            }
        }
        $templates = [];
        foreach (self::TEMPLATE_KEYS as $key) {
            $customization = $entityManager->getRepository(EmailTemplate::class)->findOneBy([
                'templateKey' => $key,
                'locale' => $locale,
            ]);
            $defaultSubject = $this->defaultSubject($key, $locale, $accountEmailSender, $subscriberEmailSender, $profileReminders, $campaignAlerts);
            $preview = $this->preview($key, $locale, $accountEmailSender, $subscriberEmailSender, $profileReminders, $campaignAlerts);
            $copy = $customization?->getCopy() ?? [];
            $text = [];
            foreach ($this->textKeys($key, $renderer, $preview) as $name) {
                $text[] = ['key' => $name, 'default' => $preview[$name], 'value' => $copy[$name] ?? $preview[$name], 'multiline' => in_array($name, self::MULTILINE_KEYS, true)];
            }
            $html = $customization?->getHtmlBody();
            $templates[] = [
                'key' => $key,
                'locale' => $locale,
                'subject' => $customization?->getSubject() ?? $defaultSubject,
                'defaultSubject' => $defaultSubject,
                'htmlBody' => $html !== null ? $renderer->prepareHtml($html) : $renderer->getDefaultHtml($key),
                'defaultHtmlBody' => $renderer->getDefaultHtml($key),
                'customized' => $customization instanceof EmailTemplate && !$customization->isEmpty(),
                'customizedLocales' => $customizedLocales[$key] ?? [],
                'text' => $text,
                'variables' => $renderer->variables($key),
                'preview' => $preview,
            ];
        }

        return new JsonResponse(['data' => ['templates' => $templates, 'sender' => ['name' => $fromName, 'email' => $fromAddress]]], headers: ['Cache-Control' => 'private, no-store']);
    }

    /** Body: {subject, htmlBody, text: {name: value}}. Values equal to the built-in ones are not stored. */
    #[Route('/api/admin/email-templates/{key}', name: 'api_admin_email_template_update', methods: ['PUT'])]
    public function update(
        string $key,
        Request $request,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        EntityManagerInterface $entityManager,
        AccountEmailSender $accountEmailSender,
        NewsletterSubscriberEmailSender $subscriberEmailSender,
        EmailTemplateRenderer $renderer,
        ProfileReminderBatch $profileReminders,
        CampaignAlertDelivery $campaignAlerts,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $input = in_array($key, self::TEMPLATE_KEYS, true)
            ? $this->input($key, $locale, JsonPayload::fromRequest($request), $renderer, $accountEmailSender, $subscriberEmailSender, $profileReminders, $campaignAlerts)
            : null;
        if ($input === null) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $template = $entityManager->getRepository(EmailTemplate::class)->findOneBy([
            'templateKey' => $key,
            'locale' => $locale,
        ]);
        if (!$template instanceof EmailTemplate) {
            $template = new EmailTemplate($key, $locale, $input['subject'], $input['htmlBody'], $input['copy']);
            $entityManager->persist($template);
        } else {
            $template->setSubject($input['subject']);
            $template->setHtmlBody($input['htmlBody']);
            $template->setCopy($input['copy']);
        }
        if ($template->isEmpty()) {
            $entityManager->remove($template);
        }
        $entityManager->flush();

        return new JsonResponse(['message' => 'Email template saved.', 'data' => ['customized' => !$template->isEmpty()]]);
    }

    /** "Vrati zadano": remove this language's changes; the built-in email is used again. */
    #[Route('/api/admin/email-templates/{key}', name: 'api_admin_email_template_reset', methods: ['DELETE'])]
    public function reset(string $key, Request $request, Security $security, CsrfTokenManagerInterface $tokenManager, EntityManagerInterface $entityManager): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        if (!in_array($key, self::TEMPLATE_KEYS, true)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $template = $entityManager->getRepository(EmailTemplate::class)->findOneBy(['templateKey' => $key, 'locale' => $locale]);
        if ($template instanceof EmailTemplate) {
            $entityManager->remove($template);
            $entityManager->flush();
        }

        return new JsonResponse(['data' => ['customized' => false]]);
    }

    /** "Pošalji test meni": the edited (unsaved) version with example data, to the signed-in admin only. */
    #[Route('/api/admin/email-templates/{key}/test', name: 'api_admin_email_template_test', methods: ['POST'])]
    public function test(
        string $key,
        Request $request,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        AccountEmailSender $accountEmailSender,
        NewsletterSubscriberEmailSender $subscriberEmailSender,
        EmailTemplateRenderer $renderer,
        ProfileReminderBatch $profileReminders,
        CampaignAlertDelivery $campaignAlerts,
        MailerInterface $mailer,
        #[Target('wave_email_template_test')] RateLimiterFactoryInterface $limiter,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')] string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')] string $fromName,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale, requireVerified: true);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $input = in_array($key, self::TEMPLATE_KEYS, true)
            ? $this->input($key, $locale, JsonPayload::fromRequest($request), $renderer, $accountEmailSender, $subscriberEmailSender, $profileReminders, $campaignAlerts)
            : null;
        if ($input === null) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        if (!$limiter->create('admin:'.$admin->getId())->consume()->isAccepted()) {
            return new JsonResponse(['error' => ApiMessages::get('rate_limited', $locale)], 429);
        }
        $preview = $this->preview($key, $locale, $accountEmailSender, $subscriberEmailSender, $profileReminders, $campaignAlerts);
        $variables = EmailTemplate::applyCopy($preview, $input['copy']);
        $subject = $input['subject'] ?? $this->defaultSubject($key, $locale, $accountEmailSender, $subscriberEmailSender, $profileReminders, $campaignAlerts);
        $subject = $renderer->renderSubject($subject, $variables);
        $mailer->send(new Email()
            ->from(new Address($fromAddress, $fromName))
            ->to(new Address($admin->getEmail()))
            ->subject('[Test] '.$subject)
            ->text(($variables['heading'] ?? $subject)."\n\n".($variables['message'] ?? ''))
            ->html($renderer->render($key, $variables, $input['htmlBody'])));

        return new JsonResponse(['data' => ['sentTo' => $admin->getEmail()]]);
    }

    /**
     * @return array{subject: ?string, htmlBody: ?string, copy: array<string, string>}|null
     */
    private function input(
        string $key,
        string $locale,
        ?array $data,
        EmailTemplateRenderer $renderer,
        AccountEmailSender $accountEmailSender,
        NewsletterSubscriberEmailSender $subscriberEmailSender,
        ProfileReminderBatch $profileReminders,
        CampaignAlertDelivery $campaignAlerts,
    ): ?array {
        $subject = is_array($data) && is_string($data['subject'] ?? null) ? trim($data['subject']) : '';
        $htmlBody = is_array($data) && is_string($data['htmlBody'] ?? null) ? $data['htmlBody'] : '';
        $text = is_array($data) ? ($data['text'] ?? []) : null;
        if (mb_strlen($subject) < 2
            || mb_strlen($subject) > 180
            || preg_match('/[\r\n\x00-\x1F\x7F]/', $subject) === 1
            || mb_strlen($htmlBody) < 20
            || mb_strlen($htmlBody) > 50000
            || $renderer->unknownVariables($key, $htmlBody) !== []
            || $renderer->unknownVariables($key, $subject) !== []
            || $renderer->containsUnsafeMarkup($htmlBody)
            || !is_array($text)
        ) {
            return null;
        }
        $preview = $this->preview($key, $locale, $accountEmailSender, $subscriberEmailSender, $profileReminders, $campaignAlerts);
        $allowed = $this->textKeys($key, $renderer, $preview);
        $copy = [];
        foreach ($text as $name => $value) {
            $multiline = in_array($name, self::MULTILINE_KEYS, true);
            if (!is_string($name) || !in_array($name, $allowed, true) || !is_string($value)) {
                return null;
            }
            $value = trim(str_replace("\r\n", "\n", $value));
            if ($value === '' || mb_strlen($value) > ($multiline ? 2000 : 300)
                || preg_match($multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $value) === 1) {
                return null;
            }
            if ($value !== $preview[$name]) {
                $copy[$name] = $value;
            }
        }
        $defaultSubject = $this->defaultSubject($key, $locale, $accountEmailSender, $subscriberEmailSender, $profileReminders, $campaignAlerts);
        $defaultHtml = $renderer->getDefaultHtml($key);

        return [
            'subject' => $subject === $defaultSubject ? null : $subject,
            'htmlBody' => trim($htmlBody) === trim($defaultHtml) ? null : $htmlBody,
            'copy' => $copy,
        ];
    }

    /**
     * @param array<string, string> $preview
     * @return list<string>
     */
    private function textKeys(string $key, EmailTemplateRenderer $renderer, array $preview): array
    {
        $variables = $renderer->variables($key);

        return array_values(array_filter(self::TEXT_KEYS, static fn (string $name): bool => in_array($name, $variables, true)
            && !in_array($name, self::TEXT_EXCLUDED[$key] ?? [], true)
            && is_string($preview[$name] ?? null) && trim($preview[$name]) !== ''));
    }

    /** @return array<string, string> */
    private function preview(
        string $key,
        string $locale,
        AccountEmailSender $accountEmailSender,
        NewsletterSubscriberEmailSender $subscriberEmailSender,
        ProfileReminderBatch $profileReminders,
        CampaignAlertDelivery $campaignAlerts,
    ): array {
        return match (true) {
            $key === 'contact' => ContactEmailCopy::previewVariables($locale),
            $key === 'subscribed' => $subscriberEmailSender->previewVariables($locale),
            $key === 'campaign_alert' => $campaignAlerts->previewVariables($locale),
            $key === 'profile_reminder' => $profileReminders->previewVariables($locale),
            default => $accountEmailSender->previewVariables($key, $locale),
        };
    }

    private function defaultSubject(
        string $key,
        string $locale,
        AccountEmailSender $accountEmailSender,
        NewsletterSubscriberEmailSender $subscriberEmailSender,
        ProfileReminderBatch $profileReminders,
        CampaignAlertDelivery $campaignAlerts,
    ): string
    {
        if ($key === 'campaign_alert') {
            return $campaignAlerts->defaultSubject($locale);
        }
        if ($key === 'profile_reminder') {
            return $profileReminders->defaultSubject();
        }
        if ($key === 'contact') {
            return ContactEmailCopy::forLocale($locale)['subject'];
        }
        if ($key === 'subscribed') {
            return $subscriberEmailSender->defaultSubject($locale);
        }

        return $accountEmailSender->defaultSubject($key, $locale);
    }
}
