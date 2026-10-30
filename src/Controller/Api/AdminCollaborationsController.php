<?php

namespace App\Controller\Api;

use App\Admin\Collaborations;
use App\Api\ApiAccess;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AdminCollaborationsController
{
    #[Route('/api/admin/collaborations', methods: ['GET'])]
    public function index(Request $request, Security $security, Collaborations $collaborations): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $user = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale, true);
        if ($user instanceof JsonResponse) return $user;
        try {
            return new JsonResponse(['userId' => $user->getId()] + $collaborations->page($request, $locale), headers: ['Cache-Control' => 'private, no-store']);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
    }
}
