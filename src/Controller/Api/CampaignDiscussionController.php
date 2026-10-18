<?php

namespace App\Controller\Api;

use App\Account\AccountDeletion;
use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\Application;
use App\Entity\CampaignConversation;
use App\Entity\CampaignMessage;
use App\Entity\Notification;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Safety\BlockPolicy;
use App\Service\NotificationDelivery;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CampaignDiscussionController
{
    #[Route('/api/me/conversations/{id}/reopen', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reopen(int $id, Request $request, EntityManagerInterface $em, Security $security, CsrfTokenManagerInterface $csrf, BlockPolicy $blocks, NotificationDelivery $delivery): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) return $error;
        $actor = ApiAccess::requireParticipant($security, $locale, true);
        if ($actor instanceof JsonResponse) return $actor;
        $conversation = $em->find(CampaignConversation::class, $id);
        $campaign = $conversation?->getCampaign();
        $provider = $conversation?->getCreator()->getOwner();
        $client = $campaign?->getCompany()->getOwner();
        if (!$conversation || !$campaign || !$provider || !$client || !in_array($actor->getId(), [$provider->getId(), $client->getId()], true)) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 404);
        }
        $data = JsonPayload::fromRequest($request);
        $action = $data['action'] ?? null;
        $body = is_string($data['message'] ?? null) ? trim($data['message']) : '';
        if (!in_array($action, ['request', 'accept', 'reject'], true)) return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        if (($action === 'request' ? $client : $provider)->getId() !== $actor->getId()) return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        if ($action === 'request' && (mb_strlen($body) < 10 || mb_strlen($body) > 1000)) return new JsonResponse(['error' => ApiMessages::get('invalid_reopening_message', $locale)], 400);

        $result = $em->wrapInTransaction(function () use ($em, $conversation, $campaign, $provider, $client, $actor, $action, $body, $data, $locale, $blocks): array|JsonResponse {
            // Serialize with hiring, cancellation and campaign status changes.
            $em->lock($campaign, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($campaign);
            $em->refresh($conversation);
            $application = $em->getRepository(Application::class)->findOneBy(['campaign' => $campaign, 'creator' => $conversation->getCreator()]);
            if ($application) $em->refresh($application);
            $offer = $application?->getOffer();
            if ($offer) $em->refresh($offer);
            if (AccountDeletion::sharedHistoryDeleted($conversation->getCreator(), $campaign->getCompany()) || $blocks->pair($provider->getId(), $client->getId())) {
                return new JsonResponse(['error' => ApiMessages::get('blocked_history', $locale)], 409);
            }
            $reopening = $conversation->getReopening();
            if ($campaign->getStatus() !== 'open' || !$application || $offer?->getStatus() !== 'cancelled'
                || ($action === 'request' ? $reopening['status'] === 'pending'
                    : ($reopening['status'] !== 'pending' || ($data['version'] ?? null) !== $reopening['version']))) {
                return new JsonResponse(['error' => ApiMessages::get('reopening_unavailable', $locale)], 409);
            }
            if ($action === 'request') {
                $conversation->requestReopening($body);
            } else {
                $conversation->respondReopening($action === 'accept');
                if ($action === 'accept') {
                    // Keep the cancelled offer and its history. Consent opens discussion only.
                    $application->setOffer(null);
                    $application->setStatus('shortlisted');
                }
            }
            $type = 'discussion_reopening_'.match ($action) { 'request' => 'requested', 'accept' => 'accepted', 'reject' => 'rejected' };
            $recipient = $actor->getId() === $client->getId() ? $provider : $client;
            $text = ApiMessages::get($type, $recipient->getPreferredLocale()).' '.$campaign->getTitle();
            if ($action === 'request') $text .= "\n".$body;
            $message = new CampaignMessage($conversation, $actor, $text, [
                'type' => $type, 'campaignTitle' => $campaign->getTitle(), 'campaignSlug' => $campaign->getSlug(),
                'message' => $action === 'request' ? $body : null, 'reopeningVersion' => $conversation->getReopening()['version'],
            ]);
            $notification = new Notification($recipient, $type, $actor, $campaign, $conversation);
            $em->persist($message);
            $em->persist($notification);
            return ['notification' => $notification, 'message' => $message];
        });
        if ($result instanceof JsonResponse) return $result;
        $delivery->deliver($result['notification'], $result['message']);
        return new JsonResponse(['data' => $conversation->getReopening()], 200, ['Cache-Control' => 'private, no-store']);
    }
}
