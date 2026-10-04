<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\CampaignConversationResource;
use App\Api\CampaignInvitationResource;
use App\Api\JsonPayload;
use App\Entity\Application;
use App\Entity\Campaign;
use App\Entity\CampaignConversation;
use App\Entity\CampaignInvitation;
use App\Entity\CampaignMessage;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\Notification;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\NotificationDelivery;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CampaignInvitationController
{
    #[Route('/api/company/campaigns/{slug}/invitations', name: 'api_company_campaign_invite', methods: ['POST'])]
    public function invite(
        string $slug,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        NotificationDelivery $notificationDelivery,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if (null === $locale) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireRole($security, 'ROLE_COMPANY', $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $company = $user->getCompany();
        $campaign = $company instanceof Company
            ? $entityManager->getRepository(Campaign::class)->findOneBy(['slug' => $slug, 'company' => $company])
            : null;
        if (!$campaign instanceof Campaign) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_not_found', $locale)], 404);
        }
        if ('open' !== $campaign->getStatus() || $campaign->getClosesAt() < new DateTimeImmutable('today')) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_closed', $locale)], 409);
        }

        $data = JsonPayload::fromRequest($request);
        $creatorId = is_array($data) ? ($data['creatorId'] ?? null) : null;
        $message = is_array($data) && is_string($data['message'] ?? null) ? trim($data['message']) : '';
        if (!is_int($creatorId) || mb_strlen($message) < 1 || mb_strlen($message) > 2000) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $creator = $entityManager->getRepository(Creator::class)->find($creatorId);
        $creatorOwner = $creator instanceof Creator ? $creator->getOwner() : null;
        if (!$creator instanceof Creator
            || !$creatorOwner instanceof User
            || !$creatorOwner->isApproved()
            || !$creatorOwner->isEmailVerified()
            || $creatorOwner->isHideMyAccount()
        ) {
            return new JsonResponse(['error' => ApiMessages::get('creator_not_found', $locale)], 404);
        }
        if ($entityManager->getRepository(Application::class)->findOneBy([
            'campaign' => $campaign,
            'creator' => $creator,
        ]) instanceof Application || $entityManager->getRepository(CampaignInvitation::class)->findOneBy([
            'campaign' => $campaign,
            'creator' => $creator,
        ]) instanceof CampaignInvitation) {
            return new JsonResponse(['error' => ApiMessages::get('invitation_exists', $locale)], 409);
        }

        $invitation = new CampaignInvitation($campaign, $creator, $user, $message);
        $notification = new Notification($creatorOwner, 'campaign_invitation', $user, $campaign);
        $entityManager->persist($invitation);
        $entityManager->persist($notification);
        $entityManager->flush();
        $notificationDelivery->deliver($notification);

        return new JsonResponse(['data' => CampaignInvitationResource::fromEntity($invitation, $locale)], 201);
    }

    #[Route('/api/me/invitations', name: 'api_my_campaign_invitations', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if (null === $locale) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireRole($security, 'ROLE_CREATOR', $locale);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $creator = $user->getCreator();
        if (!$creator instanceof Creator) {
            return new JsonResponse(['error' => ApiMessages::get('profile_unavailable', $locale)], 409);
        }

        $invitations = $entityManager->getRepository(CampaignInvitation::class)->findBy(
            ['creator' => $creator],
            ['createdAt' => 'DESC'],
        );

        return new JsonResponse([
            'data' => array_map(
                static fn (CampaignInvitation $invitation): array => CampaignInvitationResource::fromEntity($invitation, $locale),
                $invitations,
            ),
        ]);
    }

    #[Route('/api/me/invitations/{id}/respond', name: 'api_creator_invitation_respond', methods: ['POST'])]
    public function respond(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        NotificationDelivery $notificationDelivery,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if (null === $locale) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireRole($security, 'ROLE_CREATOR', $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $creator = $user->getCreator();
        $invitation = $entityManager->getRepository(CampaignInvitation::class)->find($id);
        if (!$creator instanceof Creator
            || !$invitation instanceof CampaignInvitation
            || $invitation->getCreator()->getId() !== $creator->getId()
        ) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 404);
        }
        if ('pending' !== $invitation->getStatus()) {
            return new JsonResponse(['error' => ApiMessages::get('application_not_pending', $locale)], 409);
        }

        $data = JsonPayload::fromRequest($request);
        $decision = is_array($data) ? ($data['decision'] ?? null) : null;
        if (!in_array($decision, ['accept', 'decline'], true)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $invitation->respond('accept' === $decision ? 'accepted' : 'declined');
        $conversation = null;
        $companyOwner = $invitation->getCampaign()->getCompany()->getOwner();
        if ('accept' === $decision) {
            $conversation = $entityManager->getRepository(CampaignConversation::class)->findOneBy([
                'campaign' => $invitation->getCampaign(),
                'creator' => $creator,
            ]);
            if (!$conversation instanceof CampaignConversation) {
                $conversation = new CampaignConversation($invitation->getCampaign(), $creator, $invitation->getInviter());
                $entityManager->persist($conversation);
                $entityManager->persist(new CampaignMessage(
                    $conversation,
                    $invitation->getInviter(),
                    $invitation->getMessage(),
                ));
            }
        }

        $notification = null;
        if ($companyOwner instanceof User) {
            $notification = new Notification(
                $companyOwner,
                'accept' === $decision ? 'invitation_accepted' : 'invitation_declined',
                $user,
                $invitation->getCampaign(),
                $conversation,
            );
            $entityManager->persist($notification);
        }
        $entityManager->flush();
        if ($notification instanceof Notification) {
            $notificationDelivery->deliver($notification);
        }

        return new JsonResponse([
            'data' => CampaignInvitationResource::fromEntity($invitation, $locale),
            'conversation' => $conversation instanceof CampaignConversation
                ? CampaignConversationResource::fromEntity($conversation, 0, $locale)
                : null,
        ]);
    }
}
