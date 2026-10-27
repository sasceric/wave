<?php

namespace App\Controller\Api;

use App\Api\CompanyResource;
use App\Api\DirectoryCursor;
use App\Marketplace\MarketplaceAreaCatalog;
use App\Api\MarketplaceCategoryLabels;
use App\Entity\Campaign;
use App\Entity\Company;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Reviews\CollaborationFeedback;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CompanyController
{
    public function __construct(private readonly CollaborationFeedback $feedback, private readonly \App\Safety\BlockPolicy $blocks, private readonly \App\Service\ProfileStructuredData $profileSchema)
    {
    }

    #[Route('/api/companies', name: 'api_companies_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, \App\Api\RecommendedProfiles $recommendations, DirectoryCursor $cursor): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }

        $query = trim($request->query->getString('q'));
        $companyTypes = \App\Account\CompanyTypes::parse(json_decode($request->query->getString('companyTypes', '[]'), true));
        if ($companyTypes === null) return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        $industry = trim($request->query->getString('industry'));
        $industries = json_decode($request->query->getString('industries', '[]'), true);
        if (!is_array($industries) || !array_is_list($industries) || count($industries) > 100 || array_filter($industries, static fn ($value): bool => !is_string($value) || mb_strlen($value) > 255) !== []) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $city = trim($request->query->getString('city'));
        $country = trim($request->query->getString('country'));
        $countries = json_decode($request->query->getString('countries', '[]'), true);
        if (!is_array($countries) || !array_is_list($countries) || count($countries) > 250 || array_filter($countries, static fn ($value): bool => !is_string($value) || preg_match('/^[A-Za-z]{2}$/D', $value) !== 1) !== []) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $countries = array_values(array_unique(array_map(strtoupper(...), $countries)));
        $verified = $request->query->has('verified') ? $request->query->getBoolean('verified') : null;
        $featuredFilter = $request->query->has('featured') ? $request->query->getBoolean('featured') : null;
        $sort = trim($request->query->getString('sort', 'recommended'));
        if (!in_array($sort, ['recommended', 'name', 'campaigns'], true)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
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
            $positionTypes = match ($sort) {
                'name' => ['name' => 'string', 'id' => 'int'],
                'campaigns' => ['count' => 'int', 'name' => 'string', 'id' => 'int'],
                default => \App\Api\RecommendedProfiles::POSITION_TYPES,
            };
            $position = $cursorMode ? $cursor->decode($request, 'company', $positionTypes) : null;
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $rankingColumns = $sort === 'recommended' ? $recommendations->columns('company') : '(c.featured AND (c.featured_until IS NULL OR c.featured_until > :rankingNow)) AS featured';
        $rankingJoins = $sort === 'recommended' ? $recommendations->joins('company') : '';
        $baseSql = <<<SQL
            SELECT * FROM (
                SELECT c.id, c.archived_profile, $rankingColumns, c.verified, c.name, c.company_type, c.industry, c.about,
                    u.city, u.country_code,
                    COALESCE(i.available_campaign_count, (
                        SELECT COUNT(*) FROM campaign a
                        WHERE a.company_id = c.id AND a.status = 'open' AND a.listed = TRUE AND a.closes_at >= :today
                    )) AS campaign_count
                FROM company c
                LEFT JOIN wave_user u ON u.id = c.owner_id
                LEFT JOIN directory_index i ON i.kind = 'company' AND i.entity_id = c.id AND i.indexed_at >= :today
                $rankingJoins
                WHERE u.id IS NULL OR (u.approved = TRUE AND u.hide_my_account = FALSE)
            ) ranked WHERE archived_profile = FALSE
            SQL;
        $countBaseSql = <<<'SQL'
            SELECT c.id, c.archived_profile, (c.featured AND (c.featured_until IS NULL OR c.featured_until > :rankingNow)) AS featured, c.verified, c.name, c.company_type, c.industry, c.about,
                u.city, u.country_code
            FROM company c
            LEFT JOIN wave_user u ON u.id = c.owner_id
            WHERE u.id IS NULL OR (u.approved = TRUE AND u.hide_my_account = FALSE)
            SQL;
        $baseSql = str_replace('WHERE u.id IS NULL OR (u.approved = TRUE AND u.hide_my_account = FALSE)', 'WHERE (u.id IS NULL OR (u.approved = TRUE AND u.hide_my_account = FALSE)) AND '.$this->blocks->visible('u.id'), $baseSql);
        $countBaseSql .= ' AND '.$this->blocks->visible('u.id');
        $params = ['today' => $today];
        $parameterTypes = ['today' => \Doctrine\DBAL\ParameterType::STRING];
        $params['rankingNow'] = $recommendations->parameters()['rankingNow'];
        if ($sort === 'recommended') $params = array_replace($params, $recommendations->parameters($position));

        $conditions = [];
        if ($query !== '') {
            $conditions[] = "(LOWER(name) LIKE :query OR LOWER(industry) LIKE :query OR EXISTS (SELECT 1 FROM company_industry ci WHERE ci.company_id = ranked.id AND LOWER(ci.value) LIKE :query) OR LOWER(COALESCE(about, '')) LIKE :query OR LOWER(COALESCE(city, '')) LIKE :query)";
            $params['query'] = '%' . mb_strtolower($query) . '%';
            $parameterTypes['query'] = \Doctrine\DBAL\ParameterType::STRING;
        }
        if ($companyTypes !== []) {
            $conditions[] = 'company_type IN (:companyTypes)';
            $params['companyTypes'] = $companyTypes;
            $parameterTypes['companyTypes'] = \Doctrine\DBAL\ArrayParameterType::STRING;
        }
        if ($industry !== '') {
            $conditions[] = '(LOWER(industry) LIKE :industry OR EXISTS (SELECT 1 FROM company_industry ci WHERE ci.company_id = ranked.id AND LOWER(ci.value) LIKE :industry))';
            $params['industry'] = '%' . mb_strtolower($industry) . '%';
            $parameterTypes['industry'] = \Doctrine\DBAL\ParameterType::STRING;
        }
        if ($industries !== []) {
            $conditions[] = '(industry IN (:industries) OR EXISTS (SELECT 1 FROM company_industry ci WHERE ci.company_id = ranked.id AND ci.value IN (:industries)))';
            $params['industries'] = $industries;
            $parameterTypes['industries'] = \Doctrine\DBAL\ArrayParameterType::STRING;
        }
        if ($city !== '') {
            $conditions[] = 'LOWER(COALESCE(city, \'\')) LIKE :city';
            $params['city'] = '%' . mb_strtolower($city) . '%';
            $parameterTypes['city'] = \Doctrine\DBAL\ParameterType::STRING;
        }
        if ($country !== '') {
            $conditions[] = 'LOWER(COALESCE(country_code, \'\')) = :country';
            $params['country'] = mb_strtolower($country);
            $parameterTypes['country'] = \Doctrine\DBAL\ParameterType::STRING;
        }
        if ($countries !== []) {
            $conditions[] = 'UPPER(country_code) IN (:countries)';
            $params['countries'] = $countries;
            $parameterTypes['countries'] = \Doctrine\DBAL\ArrayParameterType::STRING;
        }
        if ($verified !== null) {
            $conditions[] = 'verified = :verified';
            $params['verified'] = $verified;
            $parameterTypes['verified'] = \Doctrine\DBAL\ParameterType::BOOLEAN;
        }
        if ($featuredFilter !== null) {
            $conditions[] = 'featured = :featuredFilter';
            $params['featuredFilter'] = $featuredFilter;
            $parameterTypes['featuredFilter'] = \Doctrine\DBAL\ParameterType::BOOLEAN;
        }
        $filterSql = $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);
        $countParams = $params;
        $countParameterTypes = $parameterTypes;
        $sql = $baseSql . $filterSql;
        if ($position !== null) {
            $sql .= match ($sort) {
                'name' => ' AND (name > :name OR (name = :name AND id > :id))',
                'campaigns' => ' AND (campaign_count < :count OR (campaign_count = :count AND (name > :name OR (name = :name AND id > :id))))',
                default => $recommendations->seek(),
            };
            $params += array_diff_key($position, ['asOf' => true]);
            $parameterTypes += ['featured' => \Doctrine\DBAL\ParameterType::BOOLEAN, 'count' => \Doctrine\DBAL\ParameterType::INTEGER, 'name' => \Doctrine\DBAL\ParameterType::STRING, 'id' => \Doctrine\DBAL\ParameterType::INTEGER];
        }
        $orderBy = match ($sort) {
            'name' => 'name ASC, id ASC',
            'campaigns' => 'campaign_count DESC, name ASC, id ASC',
            default => \App\Api\RecommendedProfiles::ORDER,
        };
        $sql .= ' ORDER BY ' . $orderBy . ' LIMIT :limit OFFSET :offset';
        $params += ['limit' => $limit + ($cursorMode ? 1 : 0), 'offset' => $cursorMode ? 0 : $offset];
        $parameterTypes += ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER];
        $pageRows = $entityManager->getConnection()->fetchAllAssociative($sql, $params, $parameterTypes);
        $hasMore = $cursorMode && count($pageRows) > $limit;
        $pageRows = array_slice($pageRows, 0, $limit);
        $pageIds = array_column($pageRows, 'id');
        $builder = $entityManager->getRepository(Company::class)->createQueryBuilder('company')
            ->leftJoin('company.owner', 'owner')
            ->andWhere('company.archivedProfile = false')
            ->andWhere('owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible)')
            ->setParameter('approved', true)
            ->setParameter('visible', false);
        if ($pageIds === []) {
            $builder->andWhere('1 = 0');
        } else {
            $builder->andWhere('company.id IN (:pageIds)')->setParameter('pageIds', $pageIds);
        }
        $total = $position !== null ? null : (int) $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM (' . $countBaseSql . ') ranked WHERE archived_profile = FALSE' . $filterSql,
            $countParams,
            $countParameterTypes,
        );
        $companies = $builder
            ->leftJoin('company.logoMedia', 'logoMedia')
            ->leftJoin('company.coverMedia', 'coverMedia')
            ->leftJoin('company.industrySelections', 'industrySelections')
            ->addSelect('logoMedia', 'coverMedia', 'owner', 'industrySelections')
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
                ->andWhere('campaign.listed = TRUE')
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
            $meta['nextCursor'] = !$hasMore || $last === null ? null : $cursor->encode($request, 'company', match ($sort) {
                'name' => ['name' => $last['name'], 'id' => (int) $last['id']],
                'campaigns' => ['count' => (int) $last['campaign_count'], 'name' => $last['name'], 'id' => (int) $last['id']],
                default => $recommendations->position($last, $params),
            });
        } else {
            $meta['offset'] = $offset;
        }

        $ratings = $this->feedback->companySummaries($companies);
        $categoryLabels = MarketplaceCategoryLabels::forLocale($entityManager, $locale);

        return new JsonResponse([
            'data' => array_map(
                static fn (Company $company): array => CompanyResource::fromEntity(
                    $company,
                    $locale,
                    $campaignCounts[$company->getId() ?? 0] ?? 0,
                    $card,
                    $categoryLabels,
                ) + ['rating' => $ratings[$company->getOwner()?->getId()] ?? ['average' => null, 'reviewCount' => 0, 'completedCampaigns' => 0]],
                $companies,
            ),
            'meta' => $meta,
        ]);
    }

    #[Route('/api/companies/filters', name: 'api_companies_filters', methods: ['GET'], priority: 10)]
    public function filters(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $visible = " FROM company c LEFT JOIN wave_user u ON u.id = c.owner_id WHERE c.archived_profile = FALSE AND (u.id IS NULL OR (u.approved = TRUE AND u.hide_my_account = FALSE))";
        $visible .= ' AND '.$this->blocks->visible('u.id');
        $typeCounts = array_column($entityManager->getConnection()->fetchAllAssociative('SELECT c.company_type AS value, COUNT(*) AS count'.$visible.' GROUP BY c.company_type'), 'count', 'value');
        $rows = $entityManager->getConnection()->fetchAllAssociative(
            "SELECT selections.value, COUNT(DISTINCT c.id) AS count, MIN(c.id) AS id FROM company c
                LEFT JOIN wave_user u ON u.id = c.owner_id
                JOIN (SELECT company_id, value FROM company_industry UNION SELECT id, industry FROM company) selections ON selections.company_id = c.id
                WHERE c.archived_profile = FALSE AND (u.id IS NULL OR (u.approved = TRUE AND u.hide_my_account = FALSE)) AND selections.value <> ''
                GROUP BY selections.value ORDER BY selections.value LIMIT 200",
        );
        $counts = array_column($rows, 'count', 'value');
        $options = MarketplaceAreaCatalog::options($entityManager, $locale);
        $known = array_column($options, 'value');
        $legacyRows = array_filter($rows, static fn (array $row): bool => !in_array($row['value'], $known, true));
        $legacyLabels = MarketplaceCategoryLabels::forLocale($entityManager, $locale);
        if ($legacyRows !== []) {
            foreach ($entityManager->getRepository(Company::class)->findBy(['id' => array_column($legacyRows, 'id')]) as $company) {
                $legacyLabels[$company->getIndustry()] ??= $company->getTranslations()[$locale]['industry'] ?? $company->getIndustry();
            }
        }
        foreach ($legacyRows as $row) {
            $options[] = ['value' => $row['value'], 'label' => $legacyLabels[$row['value']] ?? $row['value']];
        }
        $countries = $entityManager->getConnection()->fetchAllAssociative(
            'SELECT u.country_code AS value, COUNT(*) AS count' . $visible . " AND u.country_code IS NOT NULL AND u.country_code <> '' GROUP BY u.country_code ORDER BY u.country_code",
        );
        return new JsonResponse(['data' => [
            'companyTypes' => array_map(static fn (string $value): array => ['value' => $value, 'count' => (int) ($typeCounts[$value] ?? 0)], \App\Account\CompanyTypes::values()),
            'industries' => array_map(static fn (array $option): array => $option + ['count' => (int) ($counts[$option['value']] ?? 0)], $options),
            'countries' => array_map(static fn (array $row): array => ['value' => $row['value'], 'count' => (int) $row['count']], $countries),
        ]]);
    }

    #[Route('/api/companies/{slug}/reviews', name: 'api_company_reviews', methods: ['GET'])]
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
            || ($company->isArchivedProfile() && (!$security->getUser() instanceof \App\Entity\User || $security->getUser() !== $owner) && !$security->isGranted('ROLE_ADMIN'))
            || $owner?->isDeleted() === true
            || ($owner !== null && !$owner->isApproved() && !$security->isGranted('ROLE_ADMIN'))
            || ($owner !== null
                && $owner->isHideMyAccount()
                && $security->getUser() !== $owner
                && !$security->isGranted('ROLE_ADMIN'))
        ) {
            return new JsonResponse(['error' => ApiMessages::get('company_not_found', $locale)], 404);
        }

        $reviewRole = $request->query->get('role');
        if ($reviewRole !== null && !in_array($reviewRole, ['provider', 'client'], true)) return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        if (str_ends_with($request->getPathInfo(), '/reviews')) {
            return new JsonResponse($owner === null ? ['data' => [], 'summary' => $this->feedback->summary(null), 'meta' => ['hasMore' => false, 'nextCursor' => null]] : $this->feedback->publicReviews($owner, $locale, max(0, $request->query->getInt('before')), max(1, $request->query->getInt('page', 1)), role: $reviewRole));
        }
        $rating = $this->feedback->summary($owner, $company->getCompanyType() === 'agency' ? 'provider' : 'client');
        return new JsonResponse(['data' => CompanyResource::fromEntity($company, $locale, categoryLabels: MarketplaceCategoryLabels::forLocale($entityManager, $locale)) + ['rating' => $rating, 'indexable' => $owner === null || ($owner->isApproved() && !$owner->isHideMyAccount() && !$owner->isDeleted()), 'structuredData' => $this->profileSchema->company($company, $locale, $rating)]]);
    }
}
