<?php

namespace App\Controller\Api;

use App\Account\AccountEmailSender;
use App\Api\ApiAccess;
use App\Entity\User;
use App\Localization\{ApiMessages, LocaleContext};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminApprovalController
{
    #[Route('/api/admin/registrations/{id}/unapprove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unapprove(int $id, Request $request, Security $security, CsrfTokenManagerInterface $csrf, EntityManagerInterface $em, AccountEmailSender $emails, LoggerInterface $logger): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) return $admin;
        if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) return $error;
        $user = $em->find(User::class, $id);
        if (!$user instanceof User) return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);

        try {
            $result = $em->wrapInTransaction(function () use ($em, $user, $emails, $locale): array|JsonResponse {
                $em->lock($user, LockMode::PESSIMISTIC_WRITE);
                $em->refresh($user);
                if ($user->isDeleted() || $user->hasRole('ROLE_ADMIN') || $user->hasRole('ROLE_MODERATOR') || !in_array($user->getRoles()[0] ?? null, ['ROLE_CREATOR', 'ROLE_COMPANY'], true)) {
                    return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);
                }
                // Repeated clicks/retries do not queue another email or change visibility again.
                if (!$user->isApproved()) return ['id' => $user->getId(), 'approved' => false, 'changed' => false];
                $user->setApproved(false);
                $user->setHideMyAccount(true);
                $em->flush();
                // Production mail jobs share this transaction with the approval change.
                $emails->sendApprovalRevoked($user);
                return ['id' => $user->getId(), 'approved' => false, 'changed' => true];
            });
        } catch (TransportExceptionInterface $error) {
            $logger->error('Unable to send account unapproval email.', ['actorId' => $admin->getId(), 'targetId' => $id, 'exception' => $error]);
            return new JsonResponse(['error' => ApiMessages::get('unapproval_email_failed', $locale)], 503);
        }
        if ($result instanceof JsonResponse) return $result;
        if ($result['changed']) $logger->notice('admin.account_unapproved', ['actorId' => $admin->getId(), 'targetId' => $id]);
        return new JsonResponse(['data' => $result], headers: ['Cache-Control' => 'private, no-store']);
    }
}
