<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\CampaignConversationResource;
use App\Api\CampaignHiredCounts;
use App\Api\CreatorInquiryResource;
use App\Entity\CampaignConversation;
use App\Entity\CreatorInquiry;
use App\Entity\InquiryMessage;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\InboxHistory;
use App\Service\UnreadInboxCounter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class InboxController
{
    #[Route('/api/me/inbox', name: 'api_my_inbox', methods: ['GET'])]
    #[Route('/api/me/inbox/unread', name: 'api_my_inbox_unread', methods: ['GET'])]
    #[Route('/api/me/inbox/{type}/{id}', name: 'api_my_inbox_thread', requirements: ['type' => 'campaign|inquiry', 'id' => '\d+'], methods: ['GET'])]
    public function index(Request $request, Security $security, InboxHistory $history, UnreadInboxCounter $counter, EntityManagerInterface $entityManager, ?string $type = null, ?int $id = null): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if ($request->attributes->get('_route') === 'api_my_inbox_unread') {
            return new JsonResponse(['unreadCount' => $counter->messages($user)]);
        }
        if ($type !== null && $id !== null) {
            $thread = $entityManager->find($type === 'campaign' ? CampaignConversation::class : CreatorInquiry::class, $id);
            $company = $thread instanceof CampaignConversation ? $thread->getCampaign()->getCompany() : $thread?->getCompany();
            if ($thread === null || ($thread->getCreator()->getOwner()?->getId() !== $user->getId() && $company?->getOwner()?->getId() !== $user->getId())
                || ($thread instanceof CreatorInquiry && $thread->getStatus() !== 'accepted')) {
                return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 404);
            }
            if ($thread instanceof CampaignConversation) {
                $unread = $counter->conversations($user, $id);
                $data = CampaignConversationResource::fromEntity($thread, $unread[$id] ?? 0, $locale, CampaignHiredCounts::forCampaign($entityManager, $thread->getCampaign()));
            } else {
                $latest = $entityManager->getRepository(InquiryMessage::class)->findOneBy(['inquiry' => $thread], ['createdAt' => 'DESC', 'id' => 'DESC']);
                $data = [...CreatorInquiryResource::fromEntity($thread, $user, $latest, $locale), 'unreadCount' => $counter->inquiryMessages($user, $id)];
            }

            return new JsonResponse(['data' => [...$data, 'threadType' => $type, 'threadKey' => $type . '-' . $id]]);
        }
        try {
            return new JsonResponse($history->page($user, $request, $locale));
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
    }
}
