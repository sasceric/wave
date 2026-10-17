<?php

namespace App\Controller\Api;

use App\Admin\OverviewStatistics;
use App\Api\ApiAccess;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AdminOverviewController
{
    #[Route('/api/admin/overview', methods: ['GET'])]
    public function overview(Request $request, Security $security, OverviewStatistics $statistics): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $user = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale, true);
        if ($user instanceof JsonResponse) return $user;
        $days = $request->query->getString('days', '30');
        if (!in_array($days, ['7', '30', '90'], true)) return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        $accountType = $request->query->getString('accountType', 'all');
        if (!in_array($accountType, ['all', 'creator', 'company', 'brand', 'agency'], true)) return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        return new JsonResponse(['userId' => $user->getId(), 'data' => $statistics->snapshot((int) $days, $accountType)], headers: ['Cache-Control' => 'private, no-store']);
    }
}
