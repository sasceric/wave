<?php

namespace App\Controller\Api;

use App\Api\CompanyResource;
use App\Entity\Campaign;
use App\Entity\Company;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CompanyController
{
    #[Route('/api/companies', name: 'api_companies_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }

        $limit = $request->query->getInt('limit', 50);
        $offset = $request->query->getInt('offset', 0);
        if ($limit < 1 || $limit > 90) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_limit', $locale)], 400);
        }
        if ($offset < 0 || $offset > 100_000) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_offset', $locale)], 400);
        }

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $pageIds = $entityManager->getConnection()->fetchFirstColumn(
            "SELECT c.id FROM company c LEFT JOIN wave_user u ON u.id = c.owner_id LEFT JOIN directory_index i ON i.kind = 'company' AND i.entity_id = c.id AND i.indexed_at >= ? WHERE u.id IS NULL OR (u.approved = TRUE AND u.hide_my_account = FALSE) ORDER BY c.featured DESC, COALESCE(i.available_campaign_count, (SELECT COUNT(*) FROM campaign a WHERE a.company_id = c.id AND a.status = 'open' AND a.closes_at >= ?)) DESC, c.name ASC, c.id ASC LIMIT ? OFFSET ?",
            [$today, $today, $limit, $offset],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER],
        );
        $builder = $entityManager->getRepository(Company::class)->createQueryBuilder('company')
            ->leftJoin('company.owner', 'owner')
            ->andWhere('owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible)')
            ->setParameter('approved', true)
            ->setParameter('visible', false);
        if ($pageIds === []) {
            $builder->andWhere('1 = 0');
        } else {
            $builder->andWhere('company.id IN (:pageIds)')->setParameter('pageIds', $pageIds);
        }
        $total = (int) $entityManager->getRepository(Company::class)->createQueryBuilder('company')
            ->select('COUNT(DISTINCT company.id)')
            ->leftJoin('company.owner', 'owner')
            ->andWhere('owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible)')
            ->setParameter('approved', true)
            ->setParameter('visible', false)
            ->getQuery()
            ->getSingleScalarResult();
        $companies = $builder
            ->leftJoin('company.logoMedia', 'logoMedia')
            ->addSelect('logoMedia', 'owner')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $ranks = array_flip($pageIds);
        usort($companies, static fn (Company $left, Company $right): int => $ranks[$left->getId()] <=> $ranks[$right->getId()]);

        $card = $request->query->getString('view') === 'card';
        $companyIds = [];
        foreach ($companies as $company) {
            if ($company instanceof Company && $company->getId() !== null) {
                $companyIds[] = $company->getId();
            }
        }

        $campaignCounts = [];
        if ($companyIds !== []) {
            $countRows = $entityManager->getRepository(Campaign::class)->createQueryBuilder('campaign')
                ->select('IDENTITY(campaign.company) AS companyId')
                ->addSelect('COUNT(campaign.id) AS availableCampaignCount')
                ->andWhere('IDENTITY(campaign.company) IN (:companyIds)')
                ->andWhere('campaign.status = :availableStatus')
                ->andWhere('campaign.closesAt >= :availableToday')
                ->groupBy('companyId')
                ->setParameter('companyIds', $companyIds)
                ->setParameter('availableStatus', 'open')
                ->setParameter('availableToday', new \DateTimeImmutable('today'))
                ->getQuery()
                ->getArrayResult();

            foreach ($countRows as $row) {
                $campaignCounts[(int) $row['companyId']] = (int) $row['availableCampaignCount'];
            }
        }

        return new JsonResponse([
            'data' => array_map(
                static fn (Company $company): array => CompanyResource::fromEntity(
                    $company,
                    $locale,
                    $campaignCounts[$company->getId() ?? 0] ?? 0,
                    $card,
                ),
                $companies,
            ),
            'meta' => ['count' => count($companies), 'total' => $total, 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    #[Route('/api/companies/{slug}', name: 'api_companies_show', methods: ['GET'])]
    public function show(
        string $slug,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }

        $company = $entityManager->getRepository(Company::class)->createQueryBuilder('company')
            ->leftJoin('company.owner', 'owner')
            ->andWhere('company.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
        $owner = $company instanceof Company ? $company->getOwner() : null;
        if (!$company instanceof Company
            || ($owner !== null && !$owner->isApproved() && !$security->isGranted('ROLE_ADMIN'))
            || ($owner !== null
                && $owner->isHideMyAccount()
                && $security->getUser() !== $owner
                && !$security->isGranted('ROLE_ADMIN'))
        ) {
            return new JsonResponse(['error' => ApiMessages::get('company_not_found', $locale)], 404);
        }

        return new JsonResponse(['data' => CompanyResource::fromEntity($company, $locale)]);
    }
}
