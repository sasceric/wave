<?php

namespace App\Controller\Api;

use App\Api\CampaignHiredCounts;
use App\Account\AccountEmailSender;
use App\Api\ApiAccess;
use App\Account\AccountDeletion;
use App\Api\ApplicationResource;
use App\Api\JsonPayload;
use App\Api\OfferResource;
use App\Entity\Application;
use App\Entity\Campaign;
use App\Entity\CampaignConversation;
use App\Entity\CampaignInvitation;
use App\Api\CampaignResource;
use App\Api\CreatorResource;
use App\Marketplace\CampaignOfferTimeline;
use App\Safety\BlockPolicy;
use Doctrine\DBAL\LockMode;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\Notification;
use App\Entity\Offer;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\NotificationDelivery;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SortDirection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class MarketplaceWorkflowController
{
    #[Route('/api/campaigns/{slug}/applications', name: 'api_campaign_apply', methods: ['POST'])]
    public function apply(
        string $slug,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        NotificationDelivery $notificationDelivery,
        AccountEmailSender $accountEmailSender,
        \App\Credits\CreditService $credits,
        \App\Service\CampaignApplicationCounter $applicationCounter,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireProvider($security, $locale, requireVerified: true, active: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $creator = $user->isAgency() ? \App\Account\AgencyProfiles::ensure($user, $entityManager) : $user->getCreator();
        $campaign = $entityManager->getRepository(Campaign::class)->createQueryBuilder('campaign')
            ->join('campaign.company', 'company')
            ->leftJoin('company.owner', 'companyOwner')
            ->andWhere('campaign.slug = :slug')
            ->andWhere('campaign.status = :status')
            ->andWhere('companyOwner.id IS NULL OR (companyOwner.approved = :approved AND companyOwner.hideMyAccount = :visible)')
            ->setParameter('slug', $slug)
            ->setParameter('status', 'open')
            ->setParameter('approved', true)
            ->setParameter('visible', false)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$creator instanceof Creator) {
            return $user->isAgency() ? new JsonResponse(['data' => []]) : new JsonResponse(['error' => ApiMessages::get('profile_unavailable', $locale)], 409);
        }
        if (!$campaign instanceof Campaign) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_not_found', $locale)], 404);
        }
        if ($campaign->getCompany()->getOwner()?->getId() === $user->getId()) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        }
        if ($campaign->getClosesAt() < new DateTimeImmutable('today')) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_closed', $locale)], 409);
        }
        $invitation = $entityManager->getRepository(CampaignInvitation::class)->findOneBy(['campaign' => $campaign, 'creator' => $creator]);
        if ($invitation instanceof CampaignInvitation && $invitation->getStatus() !== 'declined') {
            return new JsonResponse(['error' => ApiMessages::get('invitation_exists', $locale)], 409);
        }
        if ($entityManager->getRepository(Application::class)->findOneBy(['campaign' => $campaign, 'creator' => $creator]) instanceof Application) {
            return new JsonResponse(['error' => ApiMessages::get('already_applied', $locale)], 409);
        }
        $data = JsonPayload::fromRequest($request);
        $message = is_string($data['message'] ?? null) ? trim($data['message']) : '';
        if ($data === null || mb_strlen($message) < 20 || mb_strlen($message) > 1200) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_application', $locale)], 400);
        }

        $application = new Application($campaign, $creator, $message);
        if ($user->isAgency() && array_key_exists('proposal', $data)) {
            if ($campaign->isVoluntary() && ($data['proposal']['amount'] ?? null) !== null) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_application', $locale)], 400);
            }
            try { $application->setProposal(\App\Account\AgencyProposal::parse($data['proposal'])); }
            catch (\InvalidArgumentException) { return new JsonResponse(['error' => ApiMessages::get('invalid_application', $locale)], 400); }
        }
        $entityManager->persist($application);
        $companyOwner = $campaign->getCompany()->getOwner();
        $notification = null;
        if ($companyOwner instanceof User) {
            $notification = new Notification(
                $companyOwner,
                'application_received',
                $user,
                $campaign,
            );
            $entityManager->persist($notification);
        }
        try {
            $credits->spend($user, 'application', function () use ($entityManager, $application, $campaign, $applicationCounter): array {
                if ($entityManager->getRepository(Application::class)->findOneBy(['campaign' => $campaign, 'creator' => $application->getCreator()]) instanceof Application) {
                    throw new \App\Credits\CreditException('already_applied', 409);
                }
                $entityManager->flush();
                $applicationCounter->increment($campaign);

                return ['id' => $application->getId(), 'title' => $campaign->getTitle()];
            });
        } catch (\App\Credits\CreditException $error) {
            return new JsonResponse(['error' => ApiMessages::get($error->key, $locale), 'code' => $error->key], $error->status);
        }
        if ($notification instanceof Notification) {
            $notificationDelivery->deliver($notification);
        }
        if ($companyOwner instanceof User) {
            $accountEmailSender->sendApplicationReceived(
                $companyOwner,
                $creator->getDisplayName(),
                $campaign->getTitle(),
                $application->getMessage(),
            );
        }

        return new JsonResponse(['data' => ApplicationResource::fromEntity($application, $locale, hiredCount: CampaignHiredCounts::forCampaign($entityManager, $application->getCampaign()))], 201);
    }

    #[Route('/api/me/applications', name: 'api_my_applications', methods: ['GET'])]
    public function creatorApplications(Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireProvider($security, $locale);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $creator = $user->getCreator();
        if (!$creator instanceof Creator) {
            return $user->isAgency() ? new JsonResponse(['data' => []]) : new JsonResponse(['error' => ApiMessages::get('profile_unavailable', $locale)], 409);
        }
        $applications = $entityManager->getRepository(Application::class)->findBy(['creator' => $creator], ['createdAt' => 'DESC']);
        $hiredCounts = CampaignHiredCounts::forCampaigns($entityManager, array_map(static fn (Application $application): Campaign => $application->getCampaign(), $applications));
        $conversationIdsByCampaign = [];
        foreach ($entityManager->getRepository(CampaignConversation::class)->findBy(['creator' => $creator]) as $conversation) {
            $campaignId = $conversation->getCampaign()->getId();
            $conversationId = $conversation->getId();
            if ($campaignId !== null && $conversationId !== null) {
                $conversationIdsByCampaign[$campaignId] = $conversationId;
            }
        }

        return new JsonResponse([
            'data' => array_map(
                static function (Application $application) use ($locale, $conversationIdsByCampaign, $hiredCounts): array {
                    $campaignId = $application->getCampaign()->getId();
                    $conversationId = $campaignId === null ? null : ($conversationIdsByCampaign[$campaignId] ?? null);

                    return ApplicationResource::fromEntity($application, $locale, $conversationId, $hiredCounts[$campaignId] ?? 0);
                },
                $applications,
            ),
        ]);
    }

    #[Route('/api/company/campaigns/{slug}/applications', name: 'api_company_campaign_applications', methods: ['GET'])]
    public function companyApplications(string $slug, Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireRole($security, 'ROLE_COMPANY', $locale);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $company = $user->getCompany();
        $campaign = $company instanceof Company ? $entityManager->getRepository(Campaign::class)->findOneBy(['slug' => $slug, 'company' => $company]) : null;
        if (!$campaign instanceof Campaign) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_not_found', $locale)], 404);
        }
        $applications = $entityManager->getRepository(Application::class)->findBy(['campaign' => $campaign], ['createdAt' => 'ASC']);

        $hiredCount = CampaignHiredCounts::forCampaign($entityManager, $campaign);
        $conversations = [];
        foreach ($entityManager->getRepository(CampaignConversation::class)->findBy(['campaign' => $campaign]) as $conversation) {
            $conversations[$conversation->getCreator()->getId()] = $conversation->getId();
        }
        $known = [];
        $rows = [];
        foreach ($applications as $application) {
            $creatorId = $application->getCreator()->getId();
            $known[$creatorId] = true;
            $rows[] = ApplicationResource::fromEntity($application, $locale, $conversations[$creatorId] ?? null, $hiredCount);
        }
        foreach ($entityManager->getRepository(CampaignInvitation::class)->findBy(['campaign' => $campaign], ['createdAt' => 'ASC']) as $invitation) {
            $creatorId = $invitation->getCreator()->getId();
            if (isset($known[$creatorId])) continue;
            $rows[] = [
                'id' => 'invitation-'.$invitation->getId(), 'source' => 'invitation', 'invitationStatus' => $invitation->getStatus(),
                'status' => $invitation->getStatus() === 'cancelled' ? 'cancelled' : ($invitation->getStatus() === 'declined' ? 'invitation_declined' : 'invited'), 'offer' => null,
                'message' => $invitation->getMessage(), 'createdAt' => $invitation->getCreatedAt()->format(DATE_ATOM),
                'conversationId' => in_array($invitation->getStatus(), ['accepted', 'cancelled'], true) ? ($conversations[$creatorId] ?? null) : null,
                'readOnly' => $campaign->getStatus() === 'cancelled' || AccountDeletion::sharedHistoryDeleted($invitation->getCreator(), $campaign->getCompany()),
                'creator' => CreatorResource::fromEntity($invitation->getCreator(), $locale), 'campaign' => CampaignResource::fromEntity($campaign, $locale, hiredCount: $hiredCount),
            ];
        }
        return new JsonResponse(['data' => $rows]);
    }

    #[Route('/api/company/applications/{id}/shortlist', name: 'api_company_application_shortlist', methods: ['POST'])]
    public function shortlistApplication(
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
        $user = ApiAccess::requireRole($security, 'ROLE_COMPANY', $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $application = $entityManager->getRepository(Application::class)->find($id);
        $company = $user->getCompany();
        if (!$application instanceof Application
            || !$company instanceof Company
            || $application->getCampaign()->getCompany()->getId() !== $company->getId()
        ) {
            return new JsonResponse(['error' => ApiMessages::get('application_not_found', $locale)], 404);
        }
        if (AccountDeletion::sharedHistoryDeleted($application->getCreator(), $application->getCampaign()->getCompany())) {
            return new JsonResponse(['error' => ApiMessages::get('account_deleted_history', $locale)], 409);
        }
        if ($application->getCampaign()->getStatus() !== 'open') {
            $status = $application->getCampaign()->getStatus();
            return new JsonResponse(['error' => ApiMessages::get($status === 'cancelled' ? 'campaign_cancelled' : ($status === 'finished' ? 'campaign_finished' : 'campaign_chat_closed'), $locale)], 409);
        }
        if ($application->getStatus() !== 'pending') {
            return new JsonResponse(['error' => ApiMessages::get('application_not_pending', $locale)], 409);
        }

        $application->setStatus('shortlisted');
        $conversation = $entityManager->getRepository(CampaignConversation::class)->findOneBy([
            'campaign' => $application->getCampaign(),
            'creator' => $application->getCreator(),
        ]);
        if (!$conversation instanceof CampaignConversation) {
            $conversation = new CampaignConversation(
                $application->getCampaign(),
                $application->getCreator(),
                $user,
            );
            $entityManager->persist($conversation);
        }
        $creatorOwner = $application->getCreator()->getOwner();
        $notification = null;
        if ($creatorOwner instanceof User) {
            $notification = new Notification(
                $creatorOwner,
                'application_shortlisted',
                $user,
                $application->getCampaign(),
                $conversation,
            );
            $entityManager->persist($notification);
        }
        $entityManager->flush();
        if ($notification instanceof Notification) {
            $notificationDelivery->deliver($notification);
        }

        return new JsonResponse([
            'data' => ApplicationResource::fromEntity($application, $locale, hiredCount: CampaignHiredCounts::forCampaign($entityManager, $application->getCampaign())),
            'conversationId' => $conversation->getId(),
        ]);
    }

    #[Route('/api/company/applications/{id}/reject', name: 'api_company_application_reject', methods: ['POST'])]
    public function rejectApplication(
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
        $user = ApiAccess::requireRole($security, 'ROLE_COMPANY', $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $application = $entityManager->getRepository(Application::class)->find($id);
        $company = $user->getCompany();
        if (!$application instanceof Application || !$company instanceof Company || $application->getCampaign()->getCompany()->getId() !== $company->getId()) {
            return new JsonResponse(['error' => ApiMessages::get('application_not_found', $locale)], 404);
        }
        if (AccountDeletion::sharedHistoryDeleted($application->getCreator(), $application->getCampaign()->getCompany())) {
            return new JsonResponse(['error' => ApiMessages::get('account_deleted_history', $locale)], 409);
        }
        if ($application->getCampaign()->getStatus() !== 'open') {
            $status = $application->getCampaign()->getStatus();
            return new JsonResponse(['error' => ApiMessages::get($status === 'cancelled' ? 'campaign_cancelled' : ($status === 'finished' ? 'campaign_finished' : 'campaign_chat_closed'), $locale)], 409);
        }
        if ($application->getStatus() !== 'pending') {
            return new JsonResponse(['error' => ApiMessages::get('application_not_pending', $locale)], 409);
        }
        $application->setStatus('rejected');
        $entityManager->flush();

        return new JsonResponse(['data' => ApplicationResource::fromEntity($application, $locale, hiredCount: CampaignHiredCounts::forCampaign($entityManager, $application->getCampaign()))]);
    }

    #[Route('/api/company/applications/{id}/offer', name: 'api_company_application_offer', methods: ['POST'])]
    #[Route('/api/company/conversations/{id}/hire', name: 'api_company_conversation_hire', methods: ['POST'])]
    public function makeOffer(
        int $id, Request $request, EntityManagerInterface $entityManager, Security $security,
        CsrfTokenManagerInterface $tokenManager, NotificationDelivery $notificationDelivery,
        CampaignOfferTimeline $timeline, BlockPolicy $blocks,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) return $csrfError;
        $user = ApiAccess::requireRole($security, 'ROLE_COMPANY', $locale, requireVerified: true);
        if ($user instanceof JsonResponse) return $user;
        $data = JsonPayload::fromRequest($request);
        $amount = $data['amount'] ?? null;
        $message = is_string($data['message'] ?? null) ? trim($data['message']) : '';
        if (!is_int($amount) || $amount < 0 || $amount > 10000000 || mb_strlen($message) < 10 || mb_strlen($message) > 1500) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_offer', $locale)], 400);
        }
        $dueDateValue = $data['dueDate'] ?? null;
        $dueDate = is_string($dueDateValue) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dueDateValue) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDateValue) : false;
        if (!$dueDate || $dueDate->format('Y-m-d') !== $dueDateValue || $dueDate < new \DateTimeImmutable('today')) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_offer_due_date', $locale)], 400);
        }
        $result = $entityManager->wrapInTransaction(function () use ($id, $request, $entityManager, $user, $locale, $amount, $message, $dueDate, $timeline, $blocks): array|JsonResponse {
            $company = $user->getCompany();
            $fromChat = str_starts_with($request->getPathInfo(), '/api/company/conversations/');
            $conversation = $fromChat ? $entityManager->find(CampaignConversation::class, $id) : null;
            $application = $fromChat ? null : $entityManager->find(Application::class, $id);
            $campaign = $conversation?->getCampaign() ?? $application?->getCampaign();
            if (!$company instanceof Company || !$campaign instanceof Campaign || $campaign->getCompany()->getId() !== $company->getId()) {
                return new JsonResponse(['error' => ApiMessages::get('application_not_found', $locale)], 404);
            }
            // Serialize offers and acceptance for this campaign, including invited creators without an application yet.
            $entityManager->lock($campaign, LockMode::PESSIMISTIC_WRITE);
            $entityManager->refresh($campaign);
            if ($campaign->isVoluntary() ? $amount !== 0 : $amount < 1) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_offer', $locale)], 400);
            }
            if ($fromChat) {
                $application = $entityManager->getRepository(Application::class)->findOneBy(['campaign' => $campaign, 'creator' => $conversation->getCreator()]);
                if (!$application instanceof Application) {
                    $invitation = $entityManager->getRepository(CampaignInvitation::class)->findOneBy(['campaign' => $campaign, 'creator' => $conversation->getCreator(), 'status' => 'accepted']);
                    if (!$invitation instanceof CampaignInvitation) return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 404);
                    $application = new Application($campaign, $conversation->getCreator(), $invitation->getMessage());
                    $application->setStatus('shortlisted');
                }
            }
            if (!$application instanceof Application) return new JsonResponse(['error' => ApiMessages::get('application_not_found', $locale)], 404);
            if ($application->getId() !== null) $entityManager->refresh($application);
            if (AccountDeletion::sharedHistoryDeleted($application->getCreator(), $campaign->getCompany())) return new JsonResponse(['error' => ApiMessages::get('account_deleted_history', $locale)], 409);
            if ($blocks->pair($user->getId(), $application->getCreator()->getOwner()?->getId())) return new JsonResponse(['error' => ApiMessages::get('blocked_history', $locale)], 409);
            if ($campaign->getStatus() === 'cancelled') return new JsonResponse(['error' => ApiMessages::get('campaign_cancelled', $locale)], 409);
            if ($campaign->getStatus() === 'finished') return new JsonResponse(['error' => ApiMessages::get('campaign_finished', $locale)], 409);
            if ($campaign->getStatus() === 'closed') return new JsonResponse(['error' => ApiMessages::get('campaign_chat_closed', $locale)], 409);
            if (CampaignHiredCounts::forCampaign($entityManager, $campaign) >= $campaign->getCreatorCount()) return new JsonResponse(['error' => ApiMessages::get('campaign_full', $locale)], 409);
            if (!in_array($application->getStatus(), ['shortlisted', 'offer_declined'], true)) return new JsonResponse(['error' => ApiMessages::get('application_not_pending', $locale)], 409);
            if ($application->getOffer() instanceof Offer && $application->getOffer()->getStatus() !== 'rejected') return new JsonResponse(['error' => ApiMessages::get('offer_exists', $locale)], 409);
            $offer = new Offer($application, $amount, $message, $dueDate);
            $application->setStatus('offered');
            $entityManager->persist($application);
            $entityManager->persist($offer);
            $entityManager->flush();
            $chatMessage = $timeline->record($offer, $user, 'offer_sent', $application->getCreator()->getOwner()?->getPreferredLocale() ?? $locale);
            $creatorOwner = $application->getCreator()->getOwner();
            $notification = $creatorOwner instanceof User ? new Notification($creatorOwner, 'offer_received', $user, $campaign, $chatMessage->getConversation()) : null;
            if ($notification) $entityManager->persist($notification);
            return ['offer' => $offer, 'notification' => $notification, 'message' => $chatMessage];
        });
        if ($result instanceof JsonResponse) return $result;
        if ($result['notification']) $notificationDelivery->deliver($result['notification'], $result['message']);
        return new JsonResponse(['data' => OfferResource::fromEntity($result['offer'], $locale, CampaignHiredCounts::forCampaign($entityManager, $result['offer']->getApplication()->getCampaign()))], 201);
    }

    #[Route('/api/me/offers', name: 'api_my_offers', methods: ['GET'])]
    public function creatorOffers(Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireProvider($security, $locale);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $creator = $user->getCreator();
        if (!$creator instanceof Creator) {
            return $user->isAgency() ? new JsonResponse(['data' => []]) : new JsonResponse(['error' => ApiMessages::get('profile_unavailable', $locale)], 409);
        }
        $offers = $entityManager->createQueryBuilder()
            ->select('offer')
            ->from(Offer::class, 'offer')
            ->join('offer.application', 'application')
            ->where('application.creator = :creator')
            ->andWhere('application.offer = offer')
            ->setParameter('creator', $creator)
            ->orderBy('offer.createdAt', SortDirection::Descending)
            ->getQuery()
            ->getResult();

        $hiredCounts = CampaignHiredCounts::forCampaigns($entityManager, array_map(static fn (Offer $offer): Campaign => $offer->getApplication()->getCampaign(), $offers));
        return new JsonResponse(['data' => array_map(static fn (Offer $offer): array => OfferResource::fromEntity($offer, $locale, $hiredCounts[$offer->getApplication()->getCampaign()->getId()] ?? 0), $offers)]);
    }

    #[Route('/api/me/offers/{id}/respond', name: 'api_creator_offer_respond', methods: ['POST'])]
    public function respondToOffer(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        NotificationDelivery $notificationDelivery,
        AccountEmailSender $accountEmailSender,
        CampaignOfferTimeline $timeline,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireProvider($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $creator = $user->getCreator();
        $offer = $entityManager->getRepository(Offer::class)->find($id);
        if (!$offer instanceof Offer || !$creator instanceof Creator || $offer->getApplication()->getCreator()->getId() !== $creator->getId()) {
            return new JsonResponse(['error' => ApiMessages::get('offer_not_found', $locale)], 404);
        }
        if (AccountDeletion::sharedHistoryDeleted($offer->getApplication()->getCreator(), $offer->getApplication()->getCampaign()->getCompany())) {
            return new JsonResponse(['error' => ApiMessages::get('account_deleted_history', $locale)], 409);
        }
        if ($offer->getApplication()->getCampaign()->getStatus() === 'finished') {
            return new JsonResponse(['error' => ApiMessages::get('campaign_finished', $locale)], 409);
        }
        if ($offer->getStatus() !== 'pending') {
            return new JsonResponse(['error' => ApiMessages::get('offer_not_pending', $locale)], 409);
        }
        $data = JsonPayload::fromRequest($request);
        $decision = $data['decision'] ?? null;
        if ($data === null || !in_array($decision, ['accept', 'reject'], true)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_offer_response', $locale)], 400);
        }
        $result = $entityManager->wrapInTransaction(function () use ($entityManager, $offer, $decision, $user, $locale, $timeline): array|JsonResponse {
            $campaign = $offer->getApplication()->getCampaign();
            $entityManager->lock($campaign, LockMode::PESSIMISTIC_WRITE);
            $entityManager->refresh($campaign);
            $entityManager->refresh($offer);
            if ($campaign->getStatus() === 'cancelled') return new JsonResponse(['error' => ApiMessages::get('campaign_cancelled', $locale)], 409);
            if ($campaign->getStatus() === 'finished') return new JsonResponse(['error' => ApiMessages::get('campaign_finished', $locale)], 409);
            $entityManager->refresh($offer->getApplication());
            if ($offer->getApplication()->getOffer()?->getId() !== $offer->getId()) return new JsonResponse(['error' => ApiMessages::get('offer_not_pending', $locale)], 409);
            if ($offer->getStatus() !== 'pending') return new JsonResponse(['error' => ApiMessages::get('offer_not_pending', $locale)], 409);
            if ($decision === 'accept') {
                if ($offer->getDueDate() && $offer->getDueDate() < new \DateTimeImmutable('today')) return new JsonResponse(['error' => ApiMessages::get('invalid_offer_due_date', $locale)], 409);
                $acceptedCount = (int) $entityManager->createQueryBuilder()->select('COUNT(existing.id)')->from(Offer::class, 'existing')
                    ->join('existing.application', 'application')->where('application.campaign = :campaign')->andWhere('existing.status = :status')
                    ->setParameter('campaign', $campaign)->setParameter('status', 'accepted')->getQuery()->getSingleScalarResult();
                if ($acceptedCount >= $campaign->getCreatorCount()) return new JsonResponse(['error' => ApiMessages::get('campaign_full', $locale)], 409);
            }
            $offer->respond($decision === 'accept' ? 'accepted' : 'rejected');
            $companyOwner = $campaign->getCompany()->getOwner();
            $chatMessage = $timeline->record($offer, $user, $decision === 'accept' ? 'hired' : 'offer_declined', $companyOwner?->getPreferredLocale() ?? $locale);
            $notification = $companyOwner instanceof User ? new Notification($companyOwner, $decision === 'accept' ? 'offer_accepted' : 'offer_declined', $user, $campaign, $chatMessage->getConversation()) : null;
            if ($notification) $entityManager->persist($notification);
            return ['notification' => $notification, 'message' => $chatMessage];
        });
        if ($result instanceof JsonResponse) return $result;
        if ($result['notification']) $notificationDelivery->deliver($result['notification'], $result['message']);
        if ($decision === 'accept') {
            $creatorOwner = $offer->getApplication()->getCreator()->getOwner();
            if ($creatorOwner instanceof User) {
                $campaign = $offer->getApplication()->getCampaign();
                $accountEmailSender->sendCreatorHired(
                    $creatorOwner,
                    $campaign->getCompany()->getName(),
                    $campaign->getTitle(),
                );
            }
        }

        return new JsonResponse(['data' => OfferResource::fromEntity($offer, $locale, CampaignHiredCounts::forCampaign($entityManager, $offer->getApplication()->getCampaign()))]);
    }
}
