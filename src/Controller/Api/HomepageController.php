<?php

namespace App\Controller\Api;

use App\Api\CampaignHiredCounts;
use App\Account\AccountEmailSender;
use App\Api\AdminCatalog;
use App\Api\ApiAccess;
use App\Api\CampaignResource;
use App\Api\CreatorResource;
use App\Api\JsonPayload;
use App\Api\MarketplaceCategoryLabels;
use App\Entity\Application;
use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\HomepageSettings;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class HomepageController
{
    #[Route('/api/homepage', name: 'api_homepage', methods: ['GET'])]
    public function homepage(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }

        $mode = $entityManager->find(HomepageSettings::class, 1)?->getCreatorMode() ?? HomepageSettings::MODE_LATEST;
        $creatorQuery = $entityManager->getRepository(Creator::class)->createQueryBuilder('creator')
            ->leftJoin('creator.owner', 'creatorOwner')
            ->andWhere('creatorOwner.id IS NULL OR (creatorOwner.approved = :approved AND creatorOwner.hideMyAccount = :visible)')
            ->setParameter('approved', true)
            ->setParameter('visible', false);
        if ($mode === HomepageSettings::MODE_FEATURED) {
            $creatorQuery->andWhere('creator.featured = :featured')->setParameter('featured', true);
        }
        $creators = $creatorQuery
            ->orderBy('creator.createdAt', \SortDirection::Descending)
            ->addOrderBy('creator.id', \SortDirection::Descending)
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();
        $categoryLabels = MarketplaceCategoryLabels::forLocale($entityManager, $locale);
        $campaigns = $entityManager->getRepository(Campaign::class)->createQueryBuilder('campaign')
            ->join('campaign.company', 'company')
            ->leftJoin('company.owner', 'companyOwner')
            ->andWhere('campaign.status = :status')
            ->andWhere('campaign.closesAt >= :today')
            ->andWhere('companyOwner.id IS NULL OR (companyOwner.approved = :approved AND companyOwner.hideMyAccount = :visible)')
            ->setParameter('status', 'open')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->setParameter('approved', true)
            ->setParameter('visible', false)
            ->orderBy('campaign.featured', \SortDirection::Descending)
            ->addOrderBy('campaign.publishedAt', \SortDirection::Descending)
            ->addOrderBy('campaign.id', \SortDirection::Descending)
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();

        $hiredCounts = CampaignHiredCounts::forCampaigns($entityManager, $campaigns);

        return new JsonResponse([
            'data' => [
                'creatorMode' => $mode,
                'creators' => array_map(
                    static fn (Creator $creator): array => CreatorResource::fromEntity(
                        $creator,
                        $locale,
                        $categoryLabels[$creator->getCategory()] ?? null,
                        $categoryLabels,
                    ),
                    $creators,
                ),
                'campaigns' => array_map(static fn (Campaign $campaign): array => CampaignResource::fromEntity($campaign, $locale, categoryLabels: $categoryLabels, hiredCount: $hiredCounts[$campaign->getId()] ?? 0), $campaigns),
            ],
        ]);
    }

    #[Route('/api/admin/catalog/{kind}', name: 'api_admin_catalog', methods: ['GET'])]
    public function catalog(Request $request, string $kind, Security $security, AdminCatalog $catalog): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        try {
            return new JsonResponse($catalog->page($request, $kind, $locale));
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
    }

    #[Route('/api/admin/dashboard', name: 'api_admin_dashboard', methods: ['GET'])]
    public function dashboard(Request $request, EntityManagerInterface $entityManager, Security $security, AdminCatalog $catalog): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $section = $request->query->getString('section', 'all');
        if (!in_array($section, ['all', 'overview', 'homepage', 'creators', 'companies', 'campaigns', 'registrations', 'email-templates'], true)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $data = [
            'metrics' => [
                'users' => $entityManager->getRepository(User::class)->count([]),
                'creators' => $entityManager->getRepository(Creator::class)->count([]),
                'companies' => $entityManager->getRepository(Company::class)->count([]),
                'campaigns' => $entityManager->getRepository(Campaign::class)->count([]),
                'pendingApplications' => $entityManager->getRepository(Application::class)->count(['status' => 'pending']),
                'pendingRegistrations' => $entityManager->getRepository(User::class)->count(['role' => ['ROLE_CREATOR', 'ROLE_COMPANY'], 'admin' => false, 'moderator' => false, 'approved' => false]),
            ],
            'creatorMode' => $entityManager->find(HomepageSettings::class, 1)?->getCreatorMode() ?? HomepageSettings::MODE_LATEST,
        ];
        foreach (['creators', 'companies', 'campaigns', 'registrations'] as $kind) {
            $data[$kind] = $section === 'all' ? $catalog->page(new Request(), $kind, $locale)['data'] : [];
        }
        if ($section === 'homepage') {
            foreach (['creators', 'companies', 'campaigns'] as $kind) {
                $data[$kind] = $catalog->featured($kind, $locale);
            }
        }

        return new JsonResponse(['data' => $data]);
    }

    #[Route('/api/admin/homepage', name: 'api_admin_homepage_update', methods: ['PUT'])]
    public function updateHomepage(
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
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $data = JsonPayload::fromRequest($request);
        if ($data === null) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $creatorMode = $data['creatorMode'] ?? null;
        $creatorIds = self::idList($data['featuredCreatorIds'] ?? null);
        $companyIds = array_key_exists('featuredCompanyIds', $data)
            ? self::idList($data['featuredCompanyIds'])
            : null;
        $campaignIds = self::idList($data['featuredCampaignIds'] ?? null);
        if (!in_array($creatorMode, [HomepageSettings::MODE_LATEST, HomepageSettings::MODE_FEATURED], true)
            || $creatorIds === null || $campaignIds === null
            || (array_key_exists('featuredCompanyIds', $data) && $companyIds === null)
        ) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $creators = $creatorIds === [] ? [] : $entityManager->getRepository(Creator::class)->findBy(['id' => $creatorIds]);
        $companies = $companyIds === null || $companyIds === []
            ? []
            : $entityManager->getRepository(Company::class)->findBy(['id' => $companyIds]);
        $campaigns = $campaignIds === [] ? [] : $entityManager->getRepository(Campaign::class)->findBy(['id' => $campaignIds]);
        if (count($creators) !== count($creatorIds)
            || ($companyIds !== null && count($companies) !== count($companyIds))
            || count($campaigns) !== count($campaignIds)
        ) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $selectedCreators = array_fill_keys($creatorIds, true);
        foreach ($entityManager->getRepository(Creator::class)->findBy(['featured' => true]) as $creator) {
            if ($creator instanceof Creator) {
                $creator->setFeatured(isset($selectedCreators[$creator->getId()]));
            }
        }
        if ($companyIds !== null) {
            $selectedCompanies = array_fill_keys($companyIds, true);
            foreach ($entityManager->getRepository(Company::class)->findBy(['featured' => true]) as $company) {
                if ($company instanceof Company) {
                    $company->setFeatured(isset($selectedCompanies[$company->getId()]));
                }
            }
        }
        $selectedCampaigns = array_fill_keys($campaignIds, true);
        foreach ($entityManager->getRepository(Campaign::class)->findBy(['featured' => true]) as $campaign) {
            if ($campaign instanceof Campaign) {
                $campaign->setFeatured(isset($selectedCampaigns[$campaign->getId()]));
            }
        }

        foreach ([...$creators, ...$companies, ...$campaigns] as $selected) {
            $selected->setFeatured(true);
        }

        $settings = $entityManager->find(HomepageSettings::class, 1) ?? new HomepageSettings();
        $settings->setCreatorMode($creatorMode);
        $entityManager->persist($settings);
        $entityManager->flush();

        return new JsonResponse(['data' => ['creatorMode' => $creatorMode]]);
    }

    #[Route('/api/admin/registrations/{id}/approve', name: 'api_admin_registration_approve', methods: ['POST'])]
    public function approveRegistration(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        AccountEmailSender $emailSender,
        LoggerInterface $logger,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $user = $entityManager->getRepository(User::class)->find($id);
        if (!$user instanceof User
            || !in_array($user->getRoles()[0] ?? null, ['ROLE_CREATOR', 'ROLE_COMPANY'], true)
            || $user->hasRole('ROLE_ADMIN')
            || $user->hasRole('ROLE_MODERATOR')
        ) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);
        }
        if (!$user->isEmailVerified()) {
            return new JsonResponse(['error' => ApiMessages::get('approval_requires_verified_email', $locale)], 400);
        }
        if (!$user->hasCompleteProfile()) {
            return new JsonResponse(['error' => ApiMessages::get('profile_incomplete', $locale)], 400);
        }
        if ($user->isApproved()) {
            return new JsonResponse(['data' => ['id' => $id, 'approved' => true]]);
        }
        try {
            $emailSender->sendApproval($user, $user->getPreferredLocale());
        } catch (TransportExceptionInterface $exception) {
            $logger->error('Unable to send the Wave account approval email.', ['userId' => $id, 'exception' => $exception]);

            return new JsonResponse(['error' => ApiMessages::get('approval_email_failed', $locale)], 503);
        }
        $user->setApproved(true);
        $entityManager->flush();

        return new JsonResponse(['data' => ['id' => $id, 'approved' => true]]);
    }

    #[Route('/api/admin/registrations/bulk-approve', name: 'api_admin_registrations_bulk_approve', methods: ['POST'])]
    public function bulkApproveRegistrations(
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        AccountEmailSender $emailSender,
        LoggerInterface $logger,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $data = JsonPayload::fromRequest($request);
        $ids = $data === null ? null : self::idList($data['ids'] ?? null);
        if ($ids === null || $ids === []) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $users = $entityManager->getRepository(User::class)->findBy(['id' => $ids]);
        if (count($users) !== count($ids)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);
        }
        foreach ($users as $user) {
            if (!$user instanceof User
                || !in_array($user->getRoles()[0] ?? null, ['ROLE_CREATOR', 'ROLE_COMPANY'], true)
                || $user->hasRole('ROLE_ADMIN')
                || $user->hasRole('ROLE_MODERATOR')
            ) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);
            }
        }

        $approved = 0;
        $skippedUnverified = 0;
        $skippedIncomplete = 0;
        $alreadyApproved = 0;
        $emailFailures = false;
        foreach ($users as $user) {
            if ($user->isApproved()) {
                ++$alreadyApproved;
                continue;
            }
            if (!$user->isEmailVerified()) {
                ++$skippedUnverified;
                continue;
            }
            if (!$user->hasCompleteProfile()) {
                ++$skippedIncomplete;
                continue;
            }

            try {
                $emailSender->sendApproval($user, $user->getPreferredLocale());
            } catch (TransportExceptionInterface $exception) {
                $emailFailures = true;
                $logger->error('Unable to send a Wave account approval email during bulk approval.', [
                    'userId' => $user->getId(),
                    'exception' => $exception,
                ]);
                continue;
            }

            $user->setApproved(true);
            ++$approved;
        }
        if ($approved > 0) {
            $entityManager->flush();
        }

        $summary = [
            'approved' => $approved,
            'skippedUnverified' => $skippedUnverified,
            'skippedIncomplete' => $skippedIncomplete,
            'alreadyApproved' => $alreadyApproved,
        ];
        if ($emailFailures) {
            return new JsonResponse([
                'error' => ApiMessages::get('bulk_approval_email_failed', $locale),
                'data' => $summary,
            ], 503);
        }

        return new JsonResponse(['data' => $summary]);
    }

    #[Route('/api/admin/{resource}/bulk-delete', name: 'api_admin_bulk_delete', methods: ['DELETE'])]
    public function bulkDelete(
        string $resource,
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
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $entityClass = match ($resource) {
            'registrations' => User::class,
            'creators' => Creator::class,
            'companies' => Company::class,
            'campaigns' => Campaign::class,
            default => null,
        };
        $data = JsonPayload::fromRequest($request);
        $ids = $data === null ? null : self::idList($data['ids'] ?? null);
        if ($entityClass === null || $ids === null || $ids === []) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $repository = $entityManager->getRepository($entityClass);
        $entities = $resource === 'registrations'
            ? $repository->createQueryBuilder('user')
                ->where('user.id IN (:ids)')
                ->andWhere('user.role IN (:roles)')
                ->andWhere('user.admin = :notAdmin')
                ->andWhere('user.moderator = :notModerator')
                ->setParameter('ids', $ids)
                ->setParameter('roles', ['ROLE_CREATOR', 'ROLE_COMPANY'])
                ->setParameter('notAdmin', false)
                ->setParameter('notModerator', false)
                ->getQuery()
                ->getResult()
            : $repository->findBy(['id' => $ids]);
        if (count($entities) !== count($ids)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);
        }
        foreach ($entities as $entity) {
            if ($entity instanceof Creator || $entity instanceof Company) {
                $entityManager->remove($entity->getOwner() ?? $entity);
            } else {
                $entityManager->remove($entity);
            }
        }
        $entityManager->flush();

        return new JsonResponse(['data' => ['deleted' => count($entities)]]);
    }

    private static function idList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 1000) {
            return null;
        }

        $ids = [];
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1 || isset($ids[$id])) {
                return null;
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }
}
