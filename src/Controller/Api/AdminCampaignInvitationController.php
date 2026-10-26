<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Admin\CampaignInviteCreators;
use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Admin → Pozivnice: invite many creators to one of Wave's own campaigns. */
#[Route('/api/admin/campaign-invitations')]
final class AdminCampaignInvitationController
{
    public function __construct(
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly CampaignInviteCreators $invites,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function campaigns(Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $admin = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) return $admin;

        return new JsonResponse(['data' => $this->invites->campaigns()], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/{id}/creators', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function creators(int $id, Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $admin = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) return $admin;
        $campaign = $this->invites->campaign($id);
        if ($campaign === null) return new JsonResponse(['error' => ApiMessages::get('campaign_not_found', $locale)], 404);
        try {
            return new JsonResponse(['data' => $this->invites->page($request, $campaign)], headers: ['Cache-Control' => 'private, no-store']);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
    }

    /** Body: {message, creatorIds: int[]} or {message, all: true}. */
    #[Route('/{id}', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function invite(int $id, Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $admin = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale, requireVerified: true);
        if ($admin instanceof JsonResponse) return $admin;
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) return $error;
        $campaign = $this->invites->campaign($id);
        if ($campaign === null) return new JsonResponse(['error' => ApiMessages::get('campaign_not_found', $locale)], 404);

        $data = JsonPayload::fromRequest($request) ?? [];
        $message = is_string($data['message'] ?? null) ? trim(str_replace("\r\n", "\n", $data['message'])) : '';
        $all = ($data['all'] ?? false) === true;
        $ids = $data['creatorIds'] ?? null;
        $validIds = is_array($ids) && array_is_list($ids) && count($ids) <= 5000
            && array_filter($ids, static fn (mixed $value): bool => !is_int($value) || $value < 1) === [];
        if (mb_strlen($message) < 10 || mb_strlen($message) > CampaignInviteCreators::MESSAGE_MAX
            || ($all ? $ids !== null : !$validIds || $ids === [])) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        if (CampaignInviteCreators::unknownVariables($message) !== []) {
            return new JsonResponse(['error' => ApiMessages::get('invitation_unknown_variable', $locale)], 400);
        }

        /** @var list<int>|null $ids */
        $result = $this->invites->invite($campaign, $message, $all ? null : array_values(array_unique($ids)));
        $this->logger->notice('admin.campaign_invitations_sent', ['actorId' => $admin->getId(), 'campaignId' => $id, 'all' => $all] + $result);

        return new JsonResponse(['data' => $result], headers: ['Cache-Control' => 'private, no-store']);
    }
}
