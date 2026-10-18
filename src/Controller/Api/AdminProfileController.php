<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\{AdminProfileResource, ApiAccess, JsonPayload, MarketplaceCategoryLabels};
use App\Entity\{Company, Creator, User};
use App\Localization\{ApiMessages, LocaleContext};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminProfileController
{
    #[Route('/api/admin/catalog/{kind}/{id}/profile', requirements: ['kind' => 'creators|companies|registrations', 'id' => '\d+'], methods: ['GET'])]
    #[Route('/api/admin/catalog/{kind}/{id}/visibility', requirements: ['kind' => 'creators|companies|registrations', 'id' => '\d+'], methods: ['PUT'])]
    public function profile(string $kind, int $id, Request $request, Security $security, EntityManagerInterface $em, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) return $admin;
        if ($request->isMethod('PUT') && ($error = ApiAccess::requireCsrf($request, $csrf, $locale))) return $error;
        $class = match ($kind) { 'creators' => Creator::class, 'companies' => Company::class, default => User::class };
        $item = $em->find($class, $id);
        if (!$item instanceof Creator && !$item instanceof Company && !$item instanceof User) return $this->missing($locale);
        $owner = AdminProfileResource::owner($item);
        if (($owner?->isDeleted()) || ($item instanceof Creator && $item->isAgencyProfile()) || ($item instanceof User && ($item->hasRole('ROLE_ADMIN') || $item->hasRole('ROLE_MODERATOR')))) return $this->missing($locale);

        if ($request->isMethod('PUT')) {
            $data = JsonPayload::fromRequest($request);
            $hidden = $data['hideMyAccount'] ?? null;
            if (!is_bool($hidden)) return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
            $summary = AdminProfileResource::summary($item);
            if ($owner === null || !$summary['canChangeVisibility']) return $this->missing($locale);
            if (!$hidden) {
                if (!$owner->isApproved()) return new JsonResponse(['error' => ApiMessages::get('account_pending_approval', $locale)], 400);
                if (!$owner->isEmailVerified()) return new JsonResponse(['error' => ApiMessages::get('approval_requires_verified_email', $locale)], 400);
                if (!$owner->hasCompleteProfile()) return new JsonResponse(['error' => ApiMessages::get('profile_incomplete', $locale)], 400);
            }
            $owner->setHideMyAccount($hidden);
            $em->flush();
            return new JsonResponse(['data' => AdminProfileResource::summary($item)], headers: ['Cache-Control' => 'private, no-store']);
        }

        return new JsonResponse(['data' => AdminProfileResource::details($item, $locale, MarketplaceCategoryLabels::forLocale($em, $locale))], headers: ['Cache-Control' => 'private, no-store']);
    }

    private function missing(string $locale): JsonResponse
    {
        return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);
    }
}
