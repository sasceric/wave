<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\CreatorReferral;
use App\Entity\ReferralReward;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Referral\ReferralProgram;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Pozovi kreatore: the creator's referral page, who invited a visitor, and Admin → Preporuke. */
final class ReferralController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReferralProgram $referrals,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {}

    /** Shown on registration: "Amar Hodžić vas poziva na Wave". Only the public name and avatar. */
    #[Route('/api/referrals/{code}', requirements: ['code' => '[A-Za-z0-9]{3,24}'], methods: ['GET'])]
    public function inviter(string $code, Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request) ?? 'bs';
        $referrer = $this->referrals->referrer($code);
        $creator = $referrer?->getCreator();
        if ($creator === null || !$referrer->isApproved()) return $this->error('invalid_request', $locale, 404);

        return new JsonResponse(['data' => [
            'code' => $referrer->getReferralCode(),
            'name' => $creator->getDisplayName(),
            'avatarUrl' => $creator->getAvatarMedia()?->getUrl() ?? $creator->getAvatarUrl(),
        ]], 200, ['Cache-Control' => 'public, max-age=300']);
    }

    #[Route('/api/me/referrals', methods: ['GET'])]
    public function mine(Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->error('unsupported_language', 'bs', 400);
        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isDeleted()) return $this->error('authentication_required', $locale, 401);
        if (!$user->hasRole('ROLE_CREATOR') || $user->getCreator() === null) return $this->error('referrals_creators_only', $locale, 403);
        // Remember this creator's network so a sign-up from the same connection is checked by an admin.
        $user->setReferralNetworkHash($this->referrals->networkHash($request->getClientIp()));
        $this->em->flush();

        return new JsonResponse(['userId' => $user->getId(), 'data' => $this->referrals->resource($user)], 200, ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/admin/referrals', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->error('unsupported_language', 'bs', 400);
        $admin = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) return $admin;

        return new JsonResponse(['data' => $this->referrals->adminOverview()], 200, ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/admin/referral-rewards/{id}/approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(int $id, Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->error('unsupported_language', 'bs', 400);
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) return $error;
        $admin = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) return $admin;
        $reward = $this->em->getRepository(ReferralReward::class)->find($id);
        if (!$reward instanceof ReferralReward || $reward->getUser()->isDeleted()) return $this->error('invalid_request', $locale, 404);
        $this->referrals->approve($reward);

        return new JsonResponse(['message' => ApiMessages::get('referral_reward_approved', $locale), 'data' => $this->referrals->adminOverview()]);
    }

    #[Route('/api/admin/referrals/{id}/decision', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decide(int $id, Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->error('unsupported_language', 'bs', 400);
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) return $error;
        $admin = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) return $admin;
        $decision = (JsonPayload::fromRequest($request) ?? [])['decision'] ?? null;
        $referral = $this->em->getRepository(CreatorReferral::class)->find($id);
        if (!$referral instanceof CreatorReferral || !in_array($decision, ['accept', 'reject'], true)) return $this->error('invalid_request', $locale, 400);
        $this->referrals->decide($referral, $decision === 'accept');

        return new JsonResponse(['message' => ApiMessages::get($decision === 'accept' ? 'referral_accepted' : 'referral_rejected', $locale), 'data' => $this->referrals->adminOverview()]);
    }

    private function error(string $key, string $locale, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ApiMessages::get($key, $locale), 'code' => $key], $status);
    }
}
