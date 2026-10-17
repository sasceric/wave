<?php

namespace App\Api;

use App\Entity\User;
use App\Entity\Company;
use App\Entity\Creator;
use App\Localization\ApiMessages;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ApiAccess
{
    public static function requireRole(Security $security, string $role, string $locale, bool $requireVerified = false, bool $requireApproved = true): User|JsonResponse
    {
        $user = $security->getUser();
        if (!$user instanceof User || $user->isDeleted()) {
            return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        }
        if (!$user->hasRole($role)) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        }
        if ($requireApproved && !$user->hasRole('ROLE_ADMIN') && !$user->isApproved()) {
            return new JsonResponse(['error' => ApiMessages::get('account_pending_approval', $locale)], 403);
        }
        if ($requireVerified && !$user->isEmailVerified()) {
            return new JsonResponse(['error' => ApiMessages::get('email_not_verified', $locale)], 403);
        }

        return $user;
    }

    public static function requireProvider(Security $security, string $locale, bool $requireVerified = false, bool $requireApproved = true, bool $active = false): User|JsonResponse
    {
        $user = $security->getUser();
        if (!$user instanceof User) return self::requireRole($security, 'ROLE_CREATOR', $locale, $requireVerified, $requireApproved);
        if (!$user->canOfferServices() && ($active || !$user->getCreator()?->isAgencyProfile())) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        }
        return self::requireRole($security, $user->hasRole('ROLE_COMPANY') ? 'ROLE_COMPANY' : 'ROLE_CREATOR', $locale, $requireVerified, $requireApproved);
    }

    public static function requireParticipant(Security $security, string $locale, bool $requireVerified = false): User|JsonResponse
    {
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        }
        $role = $user->getCompany() instanceof Company ? 'ROLE_COMPANY' : ($user->getCreator() instanceof Creator ? 'ROLE_CREATOR' : null);
        if ($role === null) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        }

        return self::requireRole($security, $role, $locale, $requireVerified);
    }

    public static function requireCsrf(Request $request, CsrfTokenManagerInterface $tokenManager, string $locale): ?JsonResponse
    {
        $value = $request->headers->get('X-CSRF-Token');
        if (!is_string($value) || !$tokenManager->isTokenValid(new CsrfToken('wave', $value))) {
            return new JsonResponse(['error' => ApiMessages::get('csrf_invalid', $locale)], 403);
        }

        return null;
    }
}
