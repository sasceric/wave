<?php

namespace App\Controller\Api;

use App\Account\AccountDeletion;
use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Api\OfferResource;
use App\Entity\Notification;
use App\Entity\Offer;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Marketplace\CampaignOfferTimeline;
use App\Safety\BlockPolicy;
use App\Service\NotificationDelivery;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CampaignWorkController
{
    #[Route('/api/me/offers/{id}/work', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request, EntityManagerInterface $em, Security $security, CsrfTokenManagerInterface $csrf, CampaignOfferTimeline $timeline, NotificationDelivery $delivery, BlockPolicy $blocks): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) return $error;
        $actor = ApiAccess::requireParticipant($security, $locale, true);
        if ($actor instanceof JsonResponse) return $actor;
        $offer = $em->find(Offer::class, $id);
        $application = $offer?->getApplication();
        $campaign = $application?->getCampaign();
        $provider = $application?->getCreator()->getOwner();
        $client = $campaign?->getCompany()->getOwner();
        if (!$offer || !$application || !$campaign || !$provider || !$client || !in_array($actor->getId(), [$provider->getId(), $client->getId()], true)) {
            return new JsonResponse(['error' => ApiMessages::get('offer_not_found', $locale)], 404);
        }
        $data = JsonPayload::fromRequest($request);
        $action = $data['action'] ?? null;
        $reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : '';
        if (!in_array($action, ['submit', 'changes', 'complete'], true)) return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        if (($action === 'submit' ? $provider : $client)->getId() !== $actor->getId()) return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);

        $result = $em->wrapInTransaction(function () use ($em, $offer, $application, $campaign, $provider, $client, $actor, $action, $reason, $locale, $blocks, $timeline): array|JsonResponse {
            // Same campaign lock as offers, cancellation and campaign finalization.
            $em->lock($campaign, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($campaign);
            $em->refresh($application);
            $em->refresh($offer);
            if (AccountDeletion::sharedHistoryDeleted($application->getCreator(), $campaign->getCompany()) || $blocks->pair($provider->getId(), $client->getId())) {
                return new JsonResponse(['error' => ApiMessages::get('blocked_history', $locale)], 409);
            }
            if ($application->getOffer()?->getId() !== $offer->getId() || $offer->getStatus() !== 'accepted' || in_array($campaign->getStatus(), ['finished', 'cancelled'], true)) {
                return new JsonResponse(['error' => ApiMessages::get('work_unavailable', $locale)], 409);
            }
            if (($action === 'submit' && $offer->getWorkStatus() === 'submitted') || ($action === 'complete' && $offer->getWorkCompletedAt() !== null)) return [];
            if ($offer->getWorkCompletedAt() !== null || ($action !== 'submit' && $offer->getWorkStatus() !== 'submitted')) {
                return new JsonResponse(['error' => ApiMessages::get('work_unavailable', $locale)], 409);
            }
            if ($action === 'changes' && (mb_strlen($reason) < 10 || mb_strlen($reason) > 2000)) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_work_reason', $locale)], 400);
            }
            match ($action) {
                'submit' => $offer->submitWork(),
                'changes' => $offer->requestWorkChanges($reason),
                'complete' => $offer->completeWork(),
            };
            $recipient = $actor->getId() === $provider->getId() ? $client : $provider;
            $type = match ($action) { 'submit' => 'work_submitted', 'changes' => 'work_changes_requested', 'complete' => 'work_completed' };
            $message = $timeline->record($offer, $actor, $type, $recipient->getPreferredLocale());
            $notification = new Notification($recipient, $type, $actor, $campaign, $message->getConversation());
            $em->persist($notification);
            return ['message' => $message, 'notification' => $notification];
        });
        if ($result instanceof JsonResponse) return $result;
        if ($result !== []) $delivery->deliver($result['notification'], $result['message']);
        return new JsonResponse(['data' => OfferResource::fromEntity($offer, $locale, \App\Api\CampaignHiredCounts::forCampaign($em, $campaign))]);
    }
}
