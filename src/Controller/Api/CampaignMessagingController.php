<?php

namespace App\Controller\Api;

use App\Api\CampaignHiredCounts;
use App\Api\ApiAccess;
use App\Account\AccountDeletion;
use App\Api\CampaignConversationResource;
use App\Api\CampaignMessageResource;
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
use App\Service\ChatMessageHistory;
use App\Service\ChatReadReceipt;
use App\Service\NotificationDelivery;
use App\Service\UnreadInboxCounter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CampaignMessagingController
{
    #[Route('/api/me/conversations', name: 'api_my_campaign_conversations', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, Security $security, UnreadInboxCounter $counter): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $conversations = $entityManager->createQueryBuilder()->select('conversation')
            ->from(CampaignConversation::class, 'conversation')->join('conversation.creator', 'provider')
            ->join('conversation.campaign', 'campaign')->join('campaign.company', 'client')
            ->where('provider.owner = :user OR client.owner = :user')->setParameter('user', $user)
            ->orderBy('conversation.updatedAt', \SortDirection::Descending)->getQuery()->getResult();

        $hiredCounts = CampaignHiredCounts::forCampaigns($entityManager, array_map(static fn (CampaignConversation $conversation): Campaign => $conversation->getCampaign(), $conversations));
        $unread = $counter->conversations($user);
        $data = array_map(
            fn (CampaignConversation $conversation): array => CampaignConversationResource::fromEntity(
                $conversation,
                $unread[$conversation->getId()] ?? 0,
                $locale,
                $hiredCounts[$conversation->getCampaign()->getId()] ?? 0,
            ),
            $conversations,
        );

        return new JsonResponse(['data' => $data, 'unreadInquiryCount' => $counter->inquiryMessages($user)]);
    }

    #[Route('/api/company/campaigns/{slug}/conversations', name: 'api_company_campaign_conversation_start', methods: ['POST'])]
    public function startForApplicant(
        string $slug,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        NotificationDelivery $notificationDelivery,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
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
        if (in_array($campaign->getStatus(), ['finished', 'cancelled'], true)) {
            return new JsonResponse(['error' => ApiMessages::get($campaign->getStatus() === 'finished' ? 'campaign_chat_finished' : 'campaign_chat_closed', $locale)], 409);
        }
        $data = JsonPayload::fromRequest($request);
        $creatorId = is_array($data) ? ($data['creatorId'] ?? null) : null;
        $body = is_array($data) && is_string($data['message'] ?? null) ? trim($data['message']) : '';
        if (!is_int($creatorId) || mb_strlen($body) < 1 || mb_strlen($body) > 2000) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $creator = $entityManager->getRepository(Creator::class)->find($creatorId);
        $application = $creator instanceof Creator
            ? $entityManager->getRepository(Application::class)->findOneBy(['campaign' => $campaign, 'creator' => $creator])
            : null;
        if (!$creator instanceof Creator
            || !$creator->getOwner() instanceof User
            || !$creator->getOwner()->isApproved()
            || !$application instanceof Application
            || !in_array($application->getStatus(), ['shortlisted', 'offer_declined', 'offered', 'accepted'], true)
        ) {
            return new JsonResponse(['error' => ApiMessages::get('creator_not_found', $locale)], 404);
        }

        if (!\App\Marketplace\CampaignWork::chatOpen($campaign, $application->getOffer())) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_chat_closed', $locale)], 409);
        }

        return $this->sendCompanyMessage(
            $campaign,
            $creator,
            $user,
            $body,
            'chat_message',
            $locale,
            $entityManager,
            $notificationDelivery,
        );
    }

    #[Route('/api/me/conversations/{id}/messages', name: 'api_campaign_conversation_messages', methods: ['GET'])]
    public function messages(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        ChatMessageHistory $history,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $conversation = $entityManager->getRepository(CampaignConversation::class)->find($id);
        if (!$conversation instanceof CampaignConversation || !$this->canAccess($conversation, $user)) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 404);
        }

        try {
            $page = $history->page(CampaignMessage::class, 'conversation', $conversation, $request, $user);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $application = $entityManager->getRepository(Application::class)->findOneBy(['campaign' => $conversation->getCampaign(), 'creator' => $conversation->getCreator()]);
        $invitation = $entityManager->getRepository(CampaignInvitation::class)->findOneBy(['campaign' => $conversation->getCampaign(), 'creator' => $conversation->getCreator(), 'status' => 'accepted']);
        $engagement = [
            'canRequestReopening' => $user->getId() === $conversation->getCampaign()->getCompany()->getOwner()?->getId()
                && $conversation->getCampaign()->getStatus() === 'open'
                && $application?->getOffer()?->getStatus() === 'cancelled'
                && $conversation->getReopening()['status'] !== 'pending'
                && !AccountDeletion::sharedHistoryDeleted($conversation->getCreator(), $conversation->getCampaign()->getCompany()),
            'canFinishCampaign' => $user->getId() === $conversation->getCampaign()->getCompany()->getOwner()?->getId()
                && in_array($conversation->getCampaign()->getStatus(), ['open', 'closed'], true)
                && !\App\Marketplace\CampaignWork::hasUnfinishedHires($entityManager, $conversation->getCampaign())
                && !AccountDeletion::sharedHistoryDeleted($conversation->getCreator(), $conversation->getCampaign()->getCompany()),
            'canHire' => $user->getCompany()?->getId() === $conversation->getCampaign()->getCompany()->getId()
                && $conversation->getCampaign()->getStatus() === 'open'
                && CampaignHiredCounts::forCampaign($entityManager, $conversation->getCampaign()) < $conversation->getCampaign()->getCreatorCount()
                && !AccountDeletion::sharedHistoryDeleted($conversation->getCreator(), $conversation->getCampaign()->getCompany())
                && ($application ? in_array($application->getStatus(), ['shortlisted', 'offer_declined'], true) && ($application->getOffer() === null || $application->getOffer()->getStatus() === 'rejected') : $invitation !== null),
            'offer' => $application?->getOffer() ? \App\Api\OfferResource::fromEntity($application->getOffer(), $locale, CampaignHiredCounts::forCampaign($entityManager, $conversation->getCampaign())) : null,
        ];

        return new JsonResponse([
            'data' => array_map(CampaignMessageResource::fromEntity(...), $page['messages']),
            'meta' => $page['meta'],
            'readReceipt' => $page['readReceipt'],
            'conversation' => array_replace(CampaignConversationResource::fromEntity(
                $conversation,
                $this->unreadCount($entityManager, $conversation, $user),
                $locale,
                CampaignHiredCounts::forCampaign($entityManager, $conversation->getCampaign()),
            ), ['chatOpen' => \App\Marketplace\CampaignWork::chatOpen($conversation->getCampaign(), $application?->getOffer()), 'engagement' => $engagement, 'cancelled' => $application?->getOffer()?->getStatus() === 'cancelled' || $conversation->getCampaign()->getStatus() === 'cancelled', 'readOnly' => $application?->getOffer()?->getStatus() === 'cancelled' || $conversation->getCampaign()->getStatus() === 'cancelled' || AccountDeletion::sharedHistoryDeleted($conversation->getCreator(), $conversation->getCampaign()->getCompany())]),
        ]);
    }

    #[Route('/api/me/conversations/{id}/read', name: 'api_campaign_conversation_read', methods: ['POST'])]
    public function read(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        ChatReadReceipt $receipts,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $conversation = $entityManager->getRepository(CampaignConversation::class)->find($id);
        if (!$conversation instanceof CampaignConversation || !$this->canAccess($conversation, $user)) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 404);
        }
        $data = JsonPayload::fromRequest($request);
        try {
            $receipt = $receipts->acknowledge($conversation, $user, $data['throughId'] ?? null);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        return new JsonResponse([
            'data' => $receipt,
            'conversation' => CampaignConversationResource::fromEntity(
                $conversation, $this->unreadCount($entityManager, $conversation, $user), $locale,
                CampaignHiredCounts::forCampaign($entityManager, $conversation->getCampaign()),
            ),
        ]);
    }

    #[Route('/api/me/conversations/{id}/messages', name: 'api_campaign_conversation_send', methods: ['POST'])]
    public function sendMessage(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        NotificationDelivery $notificationDelivery,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $conversation = $entityManager->getRepository(CampaignConversation::class)->find($id);
        if (!$conversation instanceof CampaignConversation || !$this->canAccess($conversation, $user)) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 404);
        }
        $data = JsonPayload::fromRequest($request);
        $body = is_array($data) && is_string($data['body'] ?? null) ? trim($data['body']) : '';
        if (AccountDeletion::sharedHistoryDeleted($conversation->getCreator(), $conversation->getCampaign()->getCompany())) {
            return new JsonResponse(['error' => ApiMessages::get('account_deleted_history', $locale)], 409);
        }
        $engagement = $entityManager->getRepository(Application::class)->findOneBy(['campaign' => $conversation->getCampaign(), 'creator' => $conversation->getCreator()]);
        if (!\App\Marketplace\CampaignWork::chatOpen($conversation->getCampaign(), $engagement?->getOffer())) {
            return new JsonResponse(['error' => ApiMessages::get($conversation->getCampaign()->getStatus() === 'cancelled' ? 'campaign_cancelled' : ($conversation->getCampaign()->getStatus() === 'finished' ? 'campaign_chat_finished' : 'campaign_chat_closed'), $locale)], 409);
        }
        if ($conversation->getCampaign()->getStatus() === 'cancelled' || $engagement?->getOffer()?->getStatus() === 'cancelled') {
            return new JsonResponse(['error' => ApiMessages::get('collaboration_cancelled', $locale)], 409);
        }
        if ($data === null || mb_strlen($body) < 1 || mb_strlen($body) > 2000) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        if ($conversation->getCreator()->getOwner()?->getId() === $user->getId()) {
            $companyOwner = $conversation->getCampaign()->getCompany()->getOwner();
            $application = $entityManager->getRepository(Application::class)->findOneBy([
                'campaign' => $conversation->getCampaign(),
                'creator' => $conversation->getCreator(),
            ]);
            $acceptedInvitation = $entityManager->getRepository(CampaignInvitation::class)->findOneBy([
                'campaign' => $conversation->getCampaign(),
                'creator' => $conversation->getCreator(),
                'status' => 'accepted',
            ]);
            if (!$companyOwner instanceof User
                || (!$application instanceof Application
                    || !in_array($application->getStatus(), ['shortlisted', 'offer_declined', 'offered', 'accepted'], true))
                    && !$acceptedInvitation instanceof CampaignInvitation
            ) {
                return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
            }
        }

        $message = new CampaignMessage($conversation, $user, $body);
        if (!(new \App\Service\ChatMessageReplies($entityManager))->attach($message, $data['replyToId'] ?? null)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $entityManager->persist($message);
        $recipient = $this->otherParticipant($conversation, $user);
        $notification = null;
        if ($recipient instanceof User) {
            $notification = new Notification(
                $recipient,
                'chat_message',
                $user,
                $conversation->getCampaign(),
                $conversation,
            );
            $entityManager->persist($notification);
        }
        $entityManager->flush();
        if ($notification instanceof Notification) {
            $notificationDelivery->deliver($notification, $message);
        }

        return new JsonResponse(['data' => CampaignMessageResource::fromEntity($message)], 201);
    }

    private function sendCompanyMessage(
        Campaign $campaign,
        Creator $creator,
        User $sender,
        string $body,
        string $notificationType,
        string $locale,
        EntityManagerInterface $entityManager,
        NotificationDelivery $notificationDelivery,
    ): JsonResponse {
        $conversation = $entityManager->getRepository(CampaignConversation::class)->findOneBy([
            'campaign' => $campaign,
            'creator' => $creator,
        ]);
        $isNew = !$conversation instanceof CampaignConversation;
        if ($isNew) {
            $conversation = new CampaignConversation($campaign, $creator, $sender);
            $entityManager->persist($conversation);
        }

        $message = new CampaignMessage($conversation, $sender, $body);
        $entityManager->persist($message);
        $creatorOwner = $creator->getOwner();
        $notification = null;
        if ($creatorOwner instanceof User) {
            $notification = new Notification(
                $creatorOwner,
                $notificationType,
                $sender,
                $campaign,
                $conversation,
            );
            $entityManager->persist($notification);
        }
        $entityManager->flush();
        if ($notification instanceof Notification) {
            $notificationDelivery->deliver($notification, $message);
        }

        return new JsonResponse([
            'data' => CampaignConversationResource::fromEntity(
                $conversation,
                $this->unreadCount($entityManager, $conversation, $sender),
                $locale,
                CampaignHiredCounts::forCampaign($entityManager, $conversation->getCampaign()),
            ),
            'message' => CampaignMessageResource::fromEntity($message),
        ], $isNew ? 201 : 200);
    }

    private function canAccess(CampaignConversation $conversation, User $user): bool
    {
        $companyOwner = $conversation->getCampaign()->getCompany()->getOwner();
        $creatorOwner = $conversation->getCreator()->getOwner();

        return ($companyOwner instanceof User && $companyOwner->getId() === $user->getId())
            || ($creatorOwner instanceof User && $creatorOwner->getId() === $user->getId());
    }

    private function otherParticipant(CampaignConversation $conversation, User $user): ?User
    {
        $companyOwner = $conversation->getCampaign()->getCompany()->getOwner();
        if ($companyOwner instanceof User && $companyOwner->getId() === $user->getId()) {
            return $conversation->getCreator()->getOwner();
        }

        return $companyOwner;
    }

    private function unreadCount(EntityManagerInterface $entityManager, CampaignConversation $conversation, User $user): int
    {
        return (int) $entityManager->createQueryBuilder()
            ->select('COUNT(message.id)')
            ->from(CampaignMessage::class, 'message')
            ->where('message.conversation = :conversation')
            ->andWhere('message.sender != :viewer')
            ->andWhere('message.readAt IS NULL')
            ->setParameter('conversation', $conversation)
            ->setParameter('viewer', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
