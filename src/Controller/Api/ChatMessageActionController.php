<?php

namespace App\Controller\Api;

use App\Account\AccountDeletion;
use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\Application;
use App\Entity\CampaignMessage;
use App\Entity\InquiryMessage;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Marketplace\CampaignWork;
use App\Service\RealtimeUpdatePublisher;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ChatMessageActionController
{
    #[Route('/api/me/{kind}/{id}/messages/{messageId}/heart', name: 'api_chat_message_heart', requirements: ['kind' => 'conversations|inquiries', 'id' => '\d+', 'messageId' => '\d+'], methods: ['POST'])]
    #[Route('/api/me/{kind}/{id}/messages/{messageId}', name: 'api_chat_message_change', requirements: ['kind' => 'conversations|inquiries', 'id' => '\d+', 'messageId' => '\d+'], methods: ['PATCH', 'DELETE'])]
    public function change(string $kind, int $id, int $messageId, Request $request, EntityManagerInterface $entityManager, Security $security, CsrfTokenManagerInterface $tokenManager, RealtimeUpdatePublisher $publisher): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($error = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $error;
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $message = $entityManager->find($kind === 'conversations' ? CampaignMessage::class : InquiryMessage::class, $messageId);
        if (!$message instanceof CampaignMessage && !$message instanceof InquiryMessage) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 404);
        }
        $thread = $message instanceof CampaignMessage ? $message->getConversation() : $message->getInquiry();
        $creator = $thread->getCreator();
        $company = $message instanceof CampaignMessage ? $thread->getCampaign()->getCompany() : $thread->getCompany();
        $owners = [$creator->getOwner(), $company->getOwner()];
        if ($thread->getId() !== $id || !in_array($user->getId(), array_map(static fn (?User $owner): ?int => $owner?->getId(), $owners), true)) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 404);
        }
        $open = $message instanceof CampaignMessage
            ? CampaignWork::chatOpen($thread->getCampaign(), $entityManager->getRepository(Application::class)->findOneBy(['campaign' => $thread->getCampaign(), 'creator' => $creator])?->getOffer())
            : $thread->getStatus() === 'accepted' && $thread->getOffer()?->getStatus() !== 'cancelled';
        if (!$open || AccountDeletion::sharedHistoryDeleted($creator, $company)) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 409);
        }
        $data = JsonPayload::fromRequest($request);
        $heart = $request->isMethod('POST');
        $delete = $request->isMethod('DELETE');
        $body = is_string($data['body'] ?? null) ? trim($data['body']) : '';
        if (($heart && !is_bool($data['active'] ?? null)) || (!$heart && !$delete && (mb_strlen($body) < 1 || mb_strlen($body) > 2000))) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        if (!$heart && $message->getSender()->getId() !== $user->getId()) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        }
        $changed = $entityManager->getConnection()->transactional(function () use ($entityManager, $message, $user, $data, $heart, $delete, $body): bool {
            $entityManager->refresh($message, LockMode::PESSIMISTIC_WRITE);
            if ($message->getDeletedAt() !== null || (!$heart && !$message->canModify($user))) {
                return false;
            }
            if ($heart) {
                $message->setHeart($user, $data['active']);
            } else {
                if ($delete) $message->deleteBody();
                else $message->editBody($body);
                if ($message instanceof CampaignMessage) {
                    $latest = $entityManager->createQueryBuilder()->select('MAX(m.id)')->from(CampaignMessage::class, 'm')
                        ->where('m.conversation = :thread')->setParameter('thread', $message->getConversation())->getQuery()->getSingleScalarResult();
                    if ((int) $latest === $message->getId()) {
                        $message->getConversation()->reviseLastMessagePreview($message->getBody());
                    }
                }
            }
            $entityManager->flush();

            return true;
        });
        if (!$changed) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 409);
        }
        $resource = $heart ? ['id' => $messageId, 'hearts' => $message->getHearts()]
            : ($message instanceof CampaignMessage ? \App\Api\CampaignMessageResource::fromEntity($message) : CreatorInquiryController::messageResource($message, $thread));
        foreach ($owners as $owner) {
            if ($owner instanceof User) {
                $publisher->publishEvent($owner, [
                    'type' => $heart ? 'chat_reaction' : 'chat_message_updated',
                    $kind === 'conversations' ? 'conversationId' : 'inquiryId' => $id,
                    'message' => $resource,
                ]);
            }
        }

        return new JsonResponse(['data' => $resource]);
    }
}
