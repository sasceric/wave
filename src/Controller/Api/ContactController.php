<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Account\ContactEmailCopy;
use App\Account\EmailTemplateRenderer;
use App\Entity\EmailTemplate;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class ContactController
{
    #[Route('/api/contact', name: 'api_contact', methods: ['POST'])]
    public function send(
        Request $request,
        CsrfTokenManagerInterface $tokenManager,
        #[Target('wave_contact_ip')] RateLimiterFactoryInterface $contactLimiter,
        MailerInterface $mailer,
        LoggerInterface $logger,
        EntityManagerInterface $entityManager,
        EmailTemplateRenderer $templates,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')] string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')] string $fromName,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }

        $limit = $contactLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return new JsonResponse(
                ['error' => ApiMessages::get('rate_limited', $locale)],
                429,
                ['Retry-After' => (string) $retryAfter],
            );
        }

        $data = JsonPayload::fromRequest($request);
        $trap = is_array($data) && is_string($data['trap'] ?? null) ? trim($data['trap']) : '';
        if ($trap !== '') {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $role = is_array($data) && is_string($data['role'] ?? null) ? trim($data['role']) : '';
        $name = is_array($data) && is_string($data['name'] ?? null) ? trim($data['name']) : '';
        $emailAddress = is_array($data) && is_string($data['email'] ?? null) ? trim($data['email']) : '';
        $interest = is_array($data) && is_string($data['interest'] ?? null) ? trim($data['interest']) : '';
        $message = is_array($data) && is_string($data['message'] ?? null) ? trim($data['message']) : '';
        $copy = ContactEmailCopy::forLocale($locale);
        $roleLabels = $copy['roles'];
        $interestLabels = $copy['interests'];
        if ($data === null
            || !array_key_exists($role, $roleLabels)
            || mb_strlen($name) < 2
            || mb_strlen($name) > 120
            || preg_match('/[\r\n\x00-\x1F\x7F]/', $name) === 1
            || mb_strlen($emailAddress) > 180
            || filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false
            || !array_key_exists($interest, $interestLabels)
            || mb_strlen($message) < 20
            || mb_strlen($message) > 4000
        ) {
            return new JsonResponse(['error' => ApiMessages::get('contact_invalid', $locale)], 400);
        }

        $template = $entityManager->getRepository(EmailTemplate::class)->findOneBy([
            'templateKey' => 'contact',
            'locale' => $locale,
        ]);
        $variables = [
            'locale' => ContactEmailCopy::languageTag($locale),
            'heading' => $copy['heading'],
            'preheader' => $copy['preheader'],
            'eyebrow' => $copy['eyebrow'],
            'footer' => $copy['footer'],
            'roleLabel' => $copy['roleLabel'],
            'nameLabel' => $copy['nameLabel'],
            'emailLabel' => $copy['emailLabel'],
            'interestLabel' => $copy['interestLabel'],
            'messageLabel' => $copy['messageLabel'],
            'role' => $roleLabels[$role],
            'name' => $name,
            'email' => $emailAddress,
            'interest' => $interestLabels[$interest],
            'message' => $message,
        ];
        $email = (new Email())
            ->from(new Address($fromAddress, $fromName))
            ->to('info@wave.ba')
            ->replyTo(new Address($emailAddress, $name))
            ->subject($template instanceof EmailTemplate ? $template->getSubject() : $copy['subject'])
            ->text(
                "{$copy['roleLabel']}: {$roleLabels[$role]}\n"
                ."{$copy['nameLabel']}: {$name}\n"
                ."{$copy['emailLabel']}: {$emailAddress}\n"
                ."{$copy['interestLabel']}: {$interestLabels[$interest]}\n\n"
                ."{$copy['messageLabel']}:\n{$message}",
            )
            ->html($templates->render(
                'contact',
                $variables,
                $template instanceof EmailTemplate ? $template->getHtmlBody() : null,
            ));

        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $logger->error('Unable to send the Wave contact form message.', ['exception' => $exception]);

            return new JsonResponse(['error' => ApiMessages::get('contact_failed', $locale)], 503);
        }

        return new JsonResponse(['message' => ApiMessages::get('contact_sent', $locale)]);
    }
}
