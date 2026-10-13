<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AdminNavigationController
{
    #[Route('/api/admin/navigation-counts', methods: ['GET'])]
    public function counts(Request $request, Security $security, Connection $db): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale, true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $counts = $db->fetchAssociative("SELECT
            (SELECT COUNT(*) FROM wave_user WHERE deleted_at IS NULL AND approved = FALSE AND admin = FALSE AND moderator = FALSE AND role IN ('ROLE_CREATOR', 'ROLE_COMPANY')) AS approvals,
            (SELECT COUNT(*) FROM marketplace_report WHERE status IN ('received', 'processing')) AS reports,
            (SELECT COUNT(*) FROM wave_support_ticket WHERE status IN ('open', 'in_progress', 'on_hold')) AS tickets");

        return new JsonResponse(['userId' => $user->getId(), 'data' => array_map(intval(...), $counts ?: [])], headers: ['Cache-Control' => 'private, no-store']);
    }
}
