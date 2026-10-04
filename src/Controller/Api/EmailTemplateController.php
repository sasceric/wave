<?php

namespace App\Controller\Api;

use App\Account\AccountEmailSender;
use App\Account\ContactEmailCopy;
use App\Account\EmailTemplateRenderer;
use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\EmailTemplate;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class EmailTemplateController
{
    private const TEMPLATE_KEYS = ['verify', 'reset', 'registered', 'approval', 'contact'];
    #[Route('/api/admin/email-templates', name: 'api_admin_email_templates', methods: ['GET'])]
    public function list(
        Request $request,
        Security $security,
        EntityManagerInterface $entityManager,
        AccountEmailSender $accountEmailSender,
        EmailTemplateRenderer $renderer,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $templates = [];
        foreach (self::TEMPLATE_KEYS as $key) {
            $customization = $entityManager->getRepository(EmailTemplate::class)->findOneBy([
                'templateKey' => $key,
                'locale' => $locale,
            ]);
            $templates[] = [
                'key' => $key,
                'locale' => $locale,
                'subject' => $customization instanceof EmailTemplate
                    ? $customization->getSubject()
                    : $this->defaultSubject($key, $locale, $accountEmailSender),
                'htmlBody' => $customization instanceof EmailTemplate
                    ? $customization->getHtmlBody()
                    : $renderer->getDefaultHtml($key),
                'variables' => $renderer->variables($key),
                'preview' => $key === 'contact'
                    ? ContactEmailCopy::previewVariables($locale)
                    : $accountEmailSender->previewVariables($key, $locale),
            ];
        }

        return new JsonResponse(['data' => ['templates' => $templates]]);
    }

    #[Route('/api/admin/email-templates/{key}', name: 'api_admin_email_template_update', methods: ['PUT'])]
    public function update(
        string $key,
        Request $request,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        EntityManagerInterface $entityManager,
        AccountEmailSender $accountEmailSender,
        EmailTemplateRenderer $renderer,
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

        $data = JsonPayload::fromRequest($request);
        $subject = is_array($data) && is_string($data['subject'] ?? null) ? trim($data['subject']) : '';
        $htmlBody = is_array($data) && is_string($data['htmlBody'] ?? null) ? $data['htmlBody'] : '';
        if (!in_array($key, self::TEMPLATE_KEYS, true)
            || mb_strlen($subject) < 2
            || mb_strlen($subject) > 180
            || preg_match('/[\r\n\x00-\x1F\x7F]/', $subject) === 1
            || mb_strlen($htmlBody) < 20
            || mb_strlen($htmlBody) > 50000
            || $renderer->unknownVariables($key, $htmlBody) !== []
            || $renderer->containsUnsafeMarkup($htmlBody)
        ) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $template = $entityManager->getRepository(EmailTemplate::class)->findOneBy([
            'templateKey' => $key,
            'locale' => $locale,
        ]);
        if (!$template instanceof EmailTemplate) {
            $template = new EmailTemplate($key, $locale, $subject, $htmlBody);
            $entityManager->persist($template);
        } else {
            $template->setSubject($subject);
            $template->setHtmlBody($htmlBody);
        }
        $entityManager->flush();

        return new JsonResponse(['message' => 'Email template saved.']);
    }

    private function defaultSubject(string $key, string $locale, AccountEmailSender $accountEmailSender): string
    {
        if ($key === 'contact') {
            return ContactEmailCopy::forLocale($locale)['subject'];
        }

        return $accountEmailSender->defaultSubject($key, $locale);
    }
}
