<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\CampaignConversationResource;
use App\Api\CampaignMessageResource;
use App\Api\JsonPayload;
use App\Entity\Application;
use App\Entity\Campaign;
use App\Entity\CampaignConversation;
use App\Entity\CampaignMessage;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\Notification;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CampaignMessagingController
{
    #[Route('/api/me/conversations', name: 'api_my_campaign_conversations', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if ($user->getCreator() instanceof Creator) {
            $conversations = $entityManager->getRepository(CampaignConversation::class)->findBy(
                ['creator' => $user->getCreator()],
                ['updatedAt' => 'DESC'],
            );
        } elseif ($user->getCompany() instanceof Company) {
            $conversations = $entityManager->createQueryBuilder()
                ->select('conversation')
                ->from(CampaignConversation::class, 'conversation')
                ->join('conversation.campaign', 'campaign')
                ->where('campaign.company = :company')
                ->setParameter('company', $user->getCompany())
                ->orderBy('conversation.updatedAt', 'DESC')
                ->getQuery()
                ->getResult();
        } else {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        }

        $data = array_map(
            fn (CampaignConversation $conversation): array => CampaignConversationResource::fromEntity(
                $conversation,
                $this->unreadCount($entityManager, $conversation, $user),
                $locale,
            ),
            $conversations,
        );

        return new JsonResponse(['data' => $data]);
    }

    #[Route('/api/company/campaigns/{slug}/conversations', name: 'api_company_campaign_conversation_start', methods: ['POST'])]
    public function startForApplicant(
        string $slug,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
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
        $data = JsonPayload::fromRequest($request);
        $creatorId = is_array($data) ? ($data['creatorId'] ?? null) : null;
        $body = is_array($data) && is_string($data['message'] ?? null) ? trim($data['message']) : '';
        if (!is_int($creatorId) || mb_strlen($body) < 1 || mb_strlen($body) > 2000) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $creator = $entityManager->getRepository(Creator::class)->find($creatorId);
        if (!$creator instanceof Creator
            || !$creator->getOwner() instanceof User
            || !$creator->getOwner()->isApproved()
            || !$entityManager->getRepository(Application::class)->findOneBy(['campaign' => $campaign, 'creator' => $creator]) instanceof Application
        ) {
            return new JsonResponse(['error' => ApiMessages::get('creator_not_found', $locale)], 404);
        }

        return $this->sendCompanyMessage(
            $campaign,
            $creator,
            $user,
            $body,
            'chat_message',
            $locale,
            $entityManager,
        );
    }

    #[Route('/api/company/campaigns/{slug}/invitations', name: 'api_company_campaign_invite', methods: ['POST'])]
    public function invite(
        string $slug,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
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
        if ($campaign->getStatus() !== 'open' || $campaign->getClosesAt() < new DateTimeImmutable('today')) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_closed', $locale)], 409);
        }

        $data = JsonPayload::fromRequest($request);
        $creatorId = is_array($data) ? ($data['creatorId'] ?? null) : null;
        $body = is_array($data) && is_string($data['message'] ?? null) ? trim($data['message']) : '';
        if (!is_int($creatorId) || mb_strlen($body) < 1 || mb_strlen($body) > 2000) {
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

        return $this->sendCompanyMessage(
            $campaign,
            $creator,
            $user,
            $body,
            'campaign_invitation',
            $locale,
            $entityManager,
        );
    }

    #[Route('/api/me/conversations/{id}/messages', name: 'api_campaign_conversation_messages', methods: ['GET'])]
    public function messages(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
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

        $messages = $entityManager->getRepository(CampaignMessage::class)->findBy(
            ['conversation' => $conversation],
            ['createdAt' => 'ASC'],
        );
        $hasChanges = false;
        foreach ($messages as $message) {
            if ($message->getSender()->getId() !== $user->getId() && $message->getReadAt() === null) {
                $message->markRead();
                $hasChanges = true;
            }
        }
        $notifications = $entityManager->getRepository(Notification::class)->findBy([
            'recipient' => $user,
            'conversation' => $conversation,
            'readAt' => null,
        ]);
        foreach ($notifications as $notification) {
            $notification->markRead();
            $hasChanges = true;
        }
        if ($hasChanges) {
            $entityManager->flush();
        }

        return new JsonResponse([
            'data' => array_map(CampaignMessageResource::fromEntity(...), $messages),
            'conversation' => CampaignConversationResource::fromEntity(
                $conversation,
                $this->unreadCount($entityManager, $conversation, $user),
                $locale,
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
        if ($data === null || mb_strlen($body) < 1 || mb_strlen($body) > 2000) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        if ($user->getCreator() instanceof Creator) {
            $companyOwner = $conversation->getCampaign()->getCompany()->getOwner();
            $companyStartedChat = $companyOwner instanceof User
                && $entityManager->getRepository(CampaignMessage::class)->findOneBy([
                    'conversation' => $conversation,
                    'sender' => $companyOwner,
                ]) instanceof CampaignMessage;
            if (!$companyStartedChat) {
                return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
            }
        }

        $message = new CampaignMessage($conversation, $user, $body);
        $entityManager->persist($message);
        $recipient = $this->otherParticipant($conversation, $user);
        if ($recipient instanceof User) {
            $entityManager->persist(new Notification(
                $recipient,
                'chat_message',
                $user,
                $conversation->getCampaign(),
                $conversation,
            ));
        }
        $entityManager->flush();

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
    ): JsonResponse {
        $conversation = $entityManager->getRepository(CampaignConversation::class)->findOneBy([
            'campaign' => $campaign,
            'creator' => $creator,
        ]);
        $isNew = !($conversation instanceof CampaignConversation);
        if ($isNew) {
            $conversation = new CampaignConversation($campaign, $creator);
            $entityManager->persist($conversation);
        }

        $message = new CampaignMessage($conversation, $sender, $body);
        $entityManager->persist($message);
        $creatorOwner = $creator->getOwner();
        if ($creatorOwner instanceof User) {
            $entityManager->persist(new Notification(
                $creatorOwner,
                $notificationType,
                $sender,
                $campaign,
                $conversation,
            ));
        }
        $entityManager->flush();

        return new JsonResponse([
            'data' => CampaignConversationResource::fromEntity(
                $conversation,
                $this->unreadCount($entityManager, $conversation, $sender),
                $locale,
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
