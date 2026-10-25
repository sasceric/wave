<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Entity\CampaignMessage;
use App\Entity\InquiryMessage;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\ChatLinkPreview;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ChatLinkPreviewController
{
    #[Route('/api/me/{kind}/{id}/messages/{messageId}/preview', name: 'api_chat_link_preview', requirements: ['kind' => 'conversations|inquiries', 'id' => '\d+', 'messageId' => '\d+'], defaults: ['image' => false], methods: ['GET'])]
    #[Route('/api/me/{kind}/{id}/messages/{messageId}/preview-image', name: 'api_chat_link_preview_image', requirements: ['kind' => 'conversations|inquiries', 'id' => '\d+', 'messageId' => '\d+'], defaults: ['image' => true], methods: ['GET'])]
    public function preview(string $kind, int $id, int $messageId, bool $image, Request $request, EntityManagerInterface $entityManager, Security $security, ChatLinkPreview $previews, #[Target('wave_chat_preview')] RateLimiterFactoryInterface $limiter): Response
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) return $user;
        $message = $entityManager->find($kind === 'conversations' ? CampaignMessage::class : InquiryMessage::class, $messageId);
        if (!$message instanceof CampaignMessage && !$message instanceof InquiryMessage) return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 404);
        $thread = $message instanceof CampaignMessage ? $message->getConversation() : $message->getInquiry();
        $company = $message instanceof CampaignMessage ? $thread->getCampaign()->getCompany() : $thread->getCompany();
        if ($thread->getId() !== $id || !in_array($user->getId(), [$company->getOwner()?->getId(), $thread->getCreator()->getOwner()?->getId()], true)
            || $message instanceof InquiryMessage && $thread->getStatus() !== 'accepted' || $message->getDeletedAt() !== null) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 404);
        }
        if (!$limiter->create((string) $user->getId())->consume()->isAccepted()) return new JsonResponse(['error' => ApiMessages::get('rate_limited', $locale)], 429);
        if ($image) {
            $data = $previews->image($message->getBody());
            return $data === null ? new Response('', 404) : new Response($data['body'], 200, ['Content-Type' => $data['type'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        }
        $data = $previews->metadata($message->getBody());
        if ($data !== null) { $data['hasImage'] = $data['image'] !== null; unset($data['image']); }

        return new JsonResponse(['data' => $data], headers: ['Cache-Control' => 'private, no-store']);
    }
}
