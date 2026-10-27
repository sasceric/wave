<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\Application;
use App\Entity\CampaignReview;
use App\Entity\Notification;
use App\Service\NotificationDelivery;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Reviews\CollaborationFeedback;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CampaignReviewController
{
    public function __construct(private readonly NotificationDelivery $delivery)
    {
    }
    #[Route('/api/me/collaboration-summary', name: 'api_collaboration_summary', methods: ['GET'])]
    public function summary(Request $request, Security $security, CollaborationFeedback $feedback): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireParticipant($security, $locale);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        return new JsonResponse(['userId' => $user->getId(), 'data' => $feedback->earnings($user)], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/me/applications/{id}/review', name: 'api_campaign_review', methods: ['GET', 'POST', 'PATCH'], requirements: ['id' => '\d+'])]
    public function review(int $id, Request $request, Security $security, EntityManagerInterface $entityManager, CollaborationFeedback $feedback, CsrfTokenManagerInterface $tokenManager): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if (!$request->isMethodSafe() && ($error = ApiAccess::requireCsrf($request, $tokenManager, $locale))) {
            return $error;
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $application = $entityManager->find(Application::class, $id);
        if (!$application instanceof Application || !in_array($user->getId(), [
            $application->getCreator()->getOwner()?->getId(), $application->getCampaign()->getCompany()->getOwner()?->getId(),
        ], true)) {
            return new JsonResponse(['error' => ApiMessages::get('application_not_found', $locale)], 404);
        }
        if ($request->isMethod('GET')) {
            return new JsonResponse(['data' => $feedback->state($application, $user, $locale)], headers: ['Cache-Control' => 'private, no-store']);
        }
        $editing = $request->isMethod('PATCH');
        if ($editing && !$user->hasRole('ROLE_ADMIN')) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        }
        $data = JsonPayload::fromRequest($request);
        $rating = $data['rating'] ?? null;
        $comment = is_string($data['comment'] ?? null) ? trim($data['comment']) : '';
        if (!is_int($rating) || $rating < 1 || $rating > 5 || mb_strlen($comment) < 10 || mb_strlen($comment) > 2000) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_review', $locale)], 400);
        }
        return $entityManager->wrapInTransaction(function () use ($entityManager, $application, $feedback, $user, $locale, $rating, $comment, $editing): JsonResponse {
            // Serialize both authors on the collaboration, including duplicate requests.
            if (!$entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
                $entityManager->lock($application, LockMode::PESSIMISTIC_WRITE);
            }
            $entityManager->refresh($application);
            $state = $feedback->state($application, $user, $locale);
            if ($editing) {
                if (!$state['canEditOwn']) {
                    return new JsonResponse(['error' => ApiMessages::get('review_unavailable', $locale)], 409);
                }
                // Never accept an author or review ID from the client: only this participant's review is editable.
                $review = $entityManager->getRepository(CampaignReview::class)->findOneBy(['application' => $application, 'author' => $user]);
                if (!$review instanceof CampaignReview) {
                    return new JsonResponse(['error' => ApiMessages::get('review_unavailable', $locale)], 409);
                }
                $review->edit($rating, $comment);
                $entityManager->flush();
                return new JsonResponse(['data' => $feedback->state($application, $user, $locale)], headers: ['Cache-Control' => 'private, no-store']);
            }
            if (!$state['canReview']) {
                return new JsonResponse(['error' => ApiMessages::get('review_unavailable', $locale)], 409);
            }
            $creator = $application->getCreator()->getOwner();
            $company = $application->getCampaign()->getCompany()->getOwner();
            $recipient = $user->getId() === $creator?->getId() ? $company : $creator;
            if ($recipient === null || $recipient->getId() === $user->getId()) {
                return new JsonResponse(['error' => ApiMessages::get('review_unavailable', $locale)], 409);
            }
            $entityManager->persist(new CampaignReview($application, $user, $recipient, $rating, $comment));
            $type = $feedback->state($application, $recipient, $locale)['own'] === null ? 'review_received' : 'review_published';
            $notification = new Notification($recipient, $type, $user, $application->getCampaign(), reviewApplication: $application);
            $entityManager->persist($notification);
            $entityManager->flush();
            $this->delivery->deliver($notification);
            return new JsonResponse(['data' => $feedback->state($application, $user, $locale)], 201, ['Cache-Control' => 'private, no-store']);
        });
    }
}
