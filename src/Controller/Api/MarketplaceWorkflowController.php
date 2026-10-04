<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\ApplicationResource;
use App\Api\JsonPayload;
use App\Api\OfferResource;
use App\Entity\Application;
use App\Entity\Campaign;
use App\Entity\CampaignConversation;
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
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
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
            return new JsonResponse(['error' => ApiMessages::get('profile_unavailable', $locale)], 409);
        }
        if (!$campaign instanceof Campaign) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_not_found', $locale)], 404);
        }
        if ($campaign->getClosesAt() < new DateTimeImmutable('today')) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_closed', $locale)], 409);
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
        $entityManager->flush();
        if ($notification instanceof Notification) {
            $notificationDelivery->deliver($notification);
        }

        return new JsonResponse(['data' => ApplicationResource::fromEntity($application, $locale)], 201);
    }

    #[Route('/api/me/applications', name: 'api_my_applications', methods: ['GET'])]
    public function creatorApplications(Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
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
        $applications = $entityManager->getRepository(Application::class)->findBy(['creator' => $creator], ['createdAt' => 'DESC']);

        return new JsonResponse(['data' => array_map(static fn (Application $application): array => ApplicationResource::fromEntity($application, $locale), $applications)]);
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

        return new JsonResponse(['data' => array_map(static fn (Application $application): array => ApplicationResource::fromEntity($application, $locale), $applications)]);
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
            'data' => ApplicationResource::fromEntity($application, $locale),
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
        if (!$application instanceof Application || !$company instanceof Company || $application->getCampaign()->getCompany()->getId() !== $company->getId()) {
            return new JsonResponse(['error' => ApiMessages::get('application_not_found', $locale)], 404);
        }
        if ($application->getStatus() !== 'pending') {
            return new JsonResponse(['error' => ApiMessages::get('application_not_pending', $locale)], 409);
        }
        $application->setStatus('rejected');
        $creatorOwner = $application->getCreator()->getOwner();
        $notification = null;
        if ($creatorOwner instanceof User) {
            $notification = new Notification(
                $creatorOwner,
                'application_rejected',
                $user,
                $application->getCampaign(),
            );
            $entityManager->persist($notification);
        }
        $entityManager->flush();
        if ($notification instanceof Notification) {
            $notificationDelivery->deliver($notification);
        }

        return new JsonResponse(['data' => ApplicationResource::fromEntity($application, $locale)]);
    }

    #[Route('/api/company/applications/{id}/offer', name: 'api_company_application_offer', methods: ['POST'])]
    public function makeOffer(
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
        if (!$application instanceof Application || !$company instanceof Company || $application->getCampaign()->getCompany()->getId() !== $company->getId()) {
            return new JsonResponse(['error' => ApiMessages::get('application_not_found', $locale)], 404);
        }
        if ($application->getStatus() !== 'shortlisted') {
            return new JsonResponse(['error' => ApiMessages::get('application_not_pending', $locale)], 409);
        }
        if ($application->getOffer() instanceof Offer) {
            return new JsonResponse(['error' => ApiMessages::get('offer_exists', $locale)], 409);
        }
        $data = JsonPayload::fromRequest($request);
        $amount = $data['amount'] ?? null;
        $message = is_string($data['message'] ?? null) ? trim($data['message']) : '';
        $campaign = $application->getCampaign();
        if ($data === null || !is_int($amount) || $amount < $campaign->getBudgetMin() || $amount > $campaign->getBudgetMax() || mb_strlen($message) < 10 || mb_strlen($message) > 1500) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_offer', $locale)], 400);
        }

        $offer = new Offer($application, $amount, $message);
        $application->setStatus('offered');
        $entityManager->persist($offer);
        $creatorOwner = $application->getCreator()->getOwner();
        $notification = null;
        if ($creatorOwner instanceof User) {
            $notification = new Notification(
                $creatorOwner,
                'offer_received',
                $user,
                $campaign,
            );
            $entityManager->persist($notification);
        }
        $entityManager->flush();
        if ($notification instanceof Notification) {
            $notificationDelivery->deliver($notification);
        }

        return new JsonResponse(['data' => OfferResource::fromEntity($offer, $locale)], 201);
    }

    #[Route('/api/me/offers', name: 'api_my_offers', methods: ['GET'])]
    public function creatorOffers(Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
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
        $offers = $entityManager->createQueryBuilder()
            ->select('offer')
            ->from(Offer::class, 'offer')
            ->join('offer.application', 'application')
            ->where('application.creator = :creator')
            ->setParameter('creator', $creator)
            ->orderBy('offer.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return new JsonResponse(['data' => array_map(static fn (Offer $offer): array => OfferResource::fromEntity($offer, $locale), $offers)]);
    }

    #[Route('/api/me/offers/{id}/respond', name: 'api_creator_offer_respond', methods: ['POST'])]
    public function respondToOffer(
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
        $user = ApiAccess::requireRole($security, 'ROLE_CREATOR', $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $creator = $user->getCreator();
        $offer = $entityManager->getRepository(Offer::class)->find($id);
        if (!$offer instanceof Offer || !$creator instanceof Creator || $offer->getApplication()->getCreator()->getId() !== $creator->getId()) {
            return new JsonResponse(['error' => ApiMessages::get('offer_not_found', $locale)], 404);
        }
        if ($offer->getStatus() !== 'pending') {
            return new JsonResponse(['error' => ApiMessages::get('offer_not_pending', $locale)], 409);
        }
        $data = JsonPayload::fromRequest($request);
        $decision = $data['decision'] ?? null;
        if ($data === null || !in_array($decision, ['accept', 'reject'], true)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_offer_response', $locale)], 400);
        }
        if ($decision === 'accept') {
            $acceptedCount = (int) $entityManager->createQueryBuilder()
                ->select('COUNT(offer.id)')
                ->from(Offer::class, 'offer')
                ->join('offer.application', 'application')
                ->where('application.campaign = :campaign')
                ->andWhere('offer.status = :status')
                ->setParameter('campaign', $offer->getApplication()->getCampaign())
                ->setParameter('status', 'accepted')
                ->getQuery()
                ->getSingleScalarResult();
            if ($acceptedCount >= $offer->getApplication()->getCampaign()->getCreatorCount()) {
                return new JsonResponse(['error' => ApiMessages::get('campaign_full', $locale)], 409);
            }
        }
        $offer->respond($decision === 'accept' ? 'accepted' : 'rejected');
        $companyOwner = $offer->getApplication()->getCampaign()->getCompany()->getOwner();
        $notification = null;
        if ($companyOwner instanceof User) {
            $notification = new Notification(
                $companyOwner,
                $decision === 'accept' ? 'offer_accepted' : 'offer_declined',
                $user,
                $offer->getApplication()->getCampaign(),
            );
            $entityManager->persist($notification);
        }
        $entityManager->flush();
        if ($notification instanceof Notification) {
            $notificationDelivery->deliver($notification);
        }

        return new JsonResponse(['data' => OfferResource::fromEntity($offer, $locale)]);
    }
}
