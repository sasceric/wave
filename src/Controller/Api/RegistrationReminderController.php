<?php

namespace App\Controller\Api;

use App\Account\AccountEmailSender;
use App\Account\UserActionTokenManager;
use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class RegistrationReminderController
{
    #[Route('/api/admin/registrations/{id}/reminder', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function send(
        int $id,
        Request $request,
        Security $security,
        CsrfTokenManagerInterface $csrf,
        EntityManagerInterface $entityManager,
        UserActionTokenManager $tokens,
        AccountEmailSender $emails,
        LoggerInterface $logger,
        #[Target('wave_registration_reminder')] RateLimiterFactoryInterface $reminders,
        #[Target('wave_verification_identity')] RateLimiterFactoryInterface $verification,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) return $admin;
        if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) return $error;

        $payload = JsonPayload::fromRequest($request);
        $kind = $payload['kind'] ?? null;
        if (!in_array($kind, ['verification', 'profile', 'pre_approval'], true)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $user = $entityManager->find(User::class, $id);
        if (!$user instanceof User || $user->isDeleted() || $user->hasRole('ROLE_ADMIN') || $user->hasRole('ROLE_MODERATOR')
            || !in_array($user->getRoles()[0] ?? null, ['ROLE_CREATOR', 'ROLE_COMPANY'], true)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);
        }
        if ($kind === 'verification' && ($user->isApproved() || $user->isEmailVerified())) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        if ($kind === 'pre_approval' && $user->isApproved()) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $limiter = $reminders->create($id . ':' . $kind);
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            return new JsonResponse(['error' => ApiMessages::get('rate_limited', $locale), 'availableAt' => $limit->getRetryAfter()->format(DATE_ATOM)], 429,
                ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
        }
        if ($kind === 'verification') {
            $limit = $verification->create(hash('sha256', $user->getEmail()))->consume();
            if (!$limit->isAccepted()) {
                $limiter->reset();
                return new JsonResponse(['error' => ApiMessages::get('rate_limited', $locale)], 429);
            }
        }

        try {
            if ($kind === 'verification') {
                $token = $tokens->issue($user, 'verify_email');
                $emails->sendVerificationReminder($user, $token);
            } elseif ($kind === 'pre_approval') {
                $emails->sendPreApproval($user);
            } else {
                $emails->sendProfileTip($user);
            }
        } catch (TransportExceptionInterface $exception) {
            $limiter->reset();
            $logger->error('Unable to send registration reminder.', ['userId' => $id, 'kind' => $kind, 'exception' => $exception]);
            return new JsonResponse(['error' => ApiMessages::get('registration_email_failed', $locale)], 503);
        }

        return new JsonResponse(['data' => ['id' => $id, 'kind' => $kind]], headers: ['Cache-Control' => 'private, no-store']);
    }
}
