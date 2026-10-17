<?php

namespace App\Controller\Api;

use App\Account\PersonalOverview;
use App\Api\ApiAccess;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PersonalOverviewController
{
    #[Route('/api/me/dashboard', methods: ['GET'])]
    public function overview(Request $request, Security $security, PersonalOverview $overview): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $user = $security->getUser();
        if (!$user instanceof User || $user->isDeleted()) return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        $role = $user->hasRole('ROLE_COMPANY') ? 'ROLE_COMPANY' : 'ROLE_CREATOR';
        $access = ApiAccess::requireRole($security, $role, $locale, false, false);
        if ($access instanceof JsonResponse) return $access;
        return new JsonResponse(['userId' => $user->getId(), 'data' => $overview->snapshot($user)], headers: ['Cache-Control' => 'private, no-store']);
    }
}
