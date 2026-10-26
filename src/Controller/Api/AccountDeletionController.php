<?php

namespace App\Controller\Api;

use App\Account\AccountDeletion;
use App\Account\AccountEmailSender;
use App\Account\EmailRecipient;
use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AccountDeletionController
{
    #[Route('/api/me/account', name: 'api_my_account_delete', methods: ['DELETE'])]
    public function delete(
        Request $request,
        Security $security,
        CsrfTokenManagerInterface $tokens,
        TokenStorageInterface $tokenStorage,
        AccountDeletion $deletion,
        AccountEmailSender $emails,
        LoggerInterface $logger,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($error = ApiAccess::requireCsrf($request, $tokens, $locale)) {
            return $error;
        }
        $user = $security->getUser();
        if (!$user instanceof User || $user->isDeleted()) {
            return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        }
        if ($user->hasRole('ROLE_ADMIN') || $user->hasRole('ROLE_MODERATOR')) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        }
        $data = JsonPayload::fromRequest($request);
        if (($data['confirmed'] ?? null) !== true) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        // Read the address before deletion anonymizes the account.
        [$email, $name, $emailLocale] = [$user->getEmail(), EmailRecipient::name($user), $user->getPreferredLocale()];
        $deletion->delete([$user]);
        try {
            $emails->sendAccountDeleted($email, $name, $emailLocale);
        } catch (\Throwable $exception) {
            // The account is already deleted; a failed confirmation must not undo or block that.
            $logger->error('Unable to send account deletion confirmation.', ['exception' => $exception]);
        }
        $request->getSession()->invalidate();
        $tokenStorage->setToken(null);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }
}
