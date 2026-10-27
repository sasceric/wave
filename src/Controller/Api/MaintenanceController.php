<?php

namespace App\Controller\Api;

use App\Api\{ApiAccess, JsonPayload};
use App\Entity\User;
use App\Localization\{ApiMessages, LocaleContext};
use App\Service\MaintenanceMode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class MaintenanceController
{
    #[Route('/api/maintenance', methods: ['GET'])]
    public function status(Request $request, MaintenanceMode $mode, Security $security): JsonResponse
    {
        if (LocaleContext::fromRequest($request) === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $user = $security->getUser();
        return new JsonResponse(['data' => [
            'enabled' => $mode->enabled(),
            'bypass' => $user instanceof User && !$user->isDeleted() && $user->hasRole('ROLE_ADMIN'),
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/admin/maintenance', methods: ['PUT'])]
    public function update(Request $request, MaintenanceMode $mode, Security $security, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) return $admin;
        if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) return $error;
        $body = JsonPayload::fromRequest($request);
        if (!is_bool($body['enabled'] ?? null)) return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        $mode->setEnabled($body['enabled']);
        return new JsonResponse(['data' => ['enabled' => $body['enabled'], 'bypass' => true]], headers: ['Cache-Control' => 'private, no-store']);
    }
}
