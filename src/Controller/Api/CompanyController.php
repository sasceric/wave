<?php

namespace App\Controller\Api;

use App\Api\CompanyResource;
use App\Api\DirectoryCursor;
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
    public function index(Request $request, EntityManagerInterface $entityManager, DirectoryCursor $cursor): JsonResponse
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

        try {
            $cursorMode = $cursor->enabled($request);
            $position = $cursorMode ? $cursor->decode($request, 'company', ['featured' => 'bool', 'count' => 'int', 'name' => 'string', 'id' => 'int']) : null;
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $sql = <<<'SQL'
            SELECT * FROM (
                SELECT c.id, c.featured, c.name,
                    COALESCE(i.available_campaign_count, (
                        SELECT COUNT(*) FROM campaign a
                        WHERE a.company_id = c.id AND a.status = 'open' AND a.closes_at >= :today
                    )) AS campaign_count
                FROM company c
                LEFT JOIN wave_user u ON u.id = c.owner_id
                LEFT JOIN directory_index i ON i.kind = 'company' AND i.entity_id = c.id AND i.indexed_at >= :today
                WHERE u.id IS NULL OR (u.approved = TRUE AND u.hide_my_account = FALSE)
            ) ranked WHERE 1 = 1
            SQL;
        $params = ['today' => $today];
        $parameterTypes = ['today' => \Doctrine\DBAL\ParameterType::STRING];
        if ($position !== null) {
            $sql .= ' AND (featured < :featured OR (featured = :featured AND (campaign_count < :count OR (campaign_count = :count AND (name > :name OR (name = :name AND id > :id))))))';
            $params += $position;
            $parameterTypes += ['featured' => \Doctrine\DBAL\ParameterType::BOOLEAN, 'count' => \Doctrine\DBAL\ParameterType::INTEGER, 'name' => \Doctrine\DBAL\ParameterType::STRING, 'id' => \Doctrine\DBAL\ParameterType::INTEGER];
        }
        $sql .= ' ORDER BY featured DESC, campaign_count DESC, name ASC, id ASC LIMIT :limit OFFSET :offset';
        $params += ['limit' => $limit + ($cursorMode ? 1 : 0), 'offset' => $cursorMode ? 0 : $offset];
        $parameterTypes += ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER];
        $pageRows = $entityManager->getConnection()->fetchAllAssociative($sql, $params, $parameterTypes);
        $hasMore = $cursorMode && count($pageRows) > $limit;
        $pageRows = array_slice($pageRows, 0, $limit);
        $pageIds = array_column($pageRows, 'id');
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
        $total = $position !== null ? null : (int) $entityManager->getRepository(Company::class)->createQueryBuilder('company')
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

        $meta = ['count' => count($companies), 'limit' => $limit];
        if ($total !== null) {
            $meta['total'] = $total;
        }
        if ($cursorMode) {
            $last = $pageRows === [] ? null : $pageRows[array_key_last($pageRows)];
            $meta['hasMore'] = $hasMore;
            $meta['nextCursor'] = !$hasMore || $last === null ? null : $cursor->encode($request, 'company', [
                'featured' => (bool) $last['featured'], 'count' => (int) $last['campaign_count'], 'name' => $last['name'], 'id' => (int) $last['id'],
            ]);
        } else {
            $meta['offset'] = $offset;
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
            'meta' => $meta,
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
