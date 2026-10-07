<?php

namespace App\Controller\Api;

use App\Api\CampaignResource;
use App\Api\Currency;
use App\Api\DirectoryCursor;
use App\Entity\Campaign;
use App\Entity\MarketplaceCategory;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CampaignController
{
    #[Route('/api/campaigns', name: 'api_campaigns_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, DirectoryCursor $cursor): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }

        $limit = $request->query->getInt('limit', 24);
        $offset = $request->query->getInt('offset', 0);
        if ($limit < 1 || $limit > 90) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_limit', $locale)], 400);
        }
        if ($offset < 0 || $offset > 100_000) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_offset', $locale)], 400);
        }

        try {
            $categories = $this->selectedValues($request, 'categories', 80);
            $channels = $this->selectedValues($request, 'channels', 40);
            $countries = array_map(strtoupper(...), $this->selectedValues($request, 'countries', 2));
            foreach ($countries as $country) {
                if (!\App\Account\CountryCode::isSupported($country)) throw new \InvalidArgumentException();
            }
            $sort = $request->query->getString('sort', 'recommended');
            $currency = $request->query->getString('currency');
            $budgetMin = $this->budgetBound($request, 'budgetMin');
            $budgetMax = $this->budgetBound($request, 'budgetMax');
            if (!in_array($sort, ['recommended', 'newest', 'closing'], true)
                || ($currency !== '' && !Currency::isSupported($currency))
                || (($budgetMin !== null || $budgetMax !== null) && $currency === '')
                || ($budgetMin !== null && $budgetMax !== null && $budgetMin > $budgetMax)) {
                throw new \InvalidArgumentException();
            }
            $cursorMode = $cursor->enabled($request);
            $positionTypes = $sort === 'recommended' ? ['featured' => 'bool', 'date' => 'date', 'id' => 'int'] : ['date' => 'date', 'id' => 'int'];
            $position = $cursorMode ? $cursor->decode($request, 'campaign', $positionTypes) : null;
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $card = $request->query->getString('view') === 'card';
        $params = ['status' => 'open', 'today' => (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s')];
        $types = [];
        $conditions = ['c.status = :status', 'c.closes_at >= :today', '(owner.id IS NULL OR (owner.approved = TRUE AND owner.hide_my_account = FALSE))'];
        $query = trim($request->query->getString('q'));
        if ($query !== '') {
            $conditions[] = "(i.search_text LIKE :query OR (i.id IS NULL AND (LOWER(c.title) LIKE :query OR LOWER(c.summary) LIKE :query OR LOWER(c.description) LIKE :query OR LOWER(c.category) LIKE :query OR LOWER(c.city) LIKE :query OR LOWER(c.country_code) LIKE :query OR LOWER(CAST(c.categories AS TEXT)) LIKE :query)) OR LOWER(company.name) LIKE :query OR LOWER(company.industry) LIKE :query)";
            $params['query'] = '%' . mb_strtolower($query) . '%';
        }
        $category = trim($request->query->getString('category'));
        if ($category !== '') {
            $categories[] = $category;
        }
        if ($categories !== []) {
            $parts = [];
            foreach (array_values(array_unique($categories)) as $index => $value) {
                $parts[] = "(LOWER(c.category) = :category$index OR LOWER(CAST(c.categories AS TEXT)) LIKE :categoryJson$index ESCAPE '!')";
                $params['category' . $index] = mb_strtolower($value);
                $params['categoryJson' . $index] = '%' . strtr(json_encode(mb_strtolower($value), JSON_THROW_ON_ERROR), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }
        if ($channels !== []) {
            $parts = [];
            foreach ($channels as $index => $channel) {
                // Match complete JSON values, including before a queued index rebuild.
                $parts[] = "LOWER(CAST(c.channels AS TEXT)) LIKE :channel$index ESCAPE '!'";
                $params['channel' . $index] = '%' . strtr(json_encode(mb_strtolower($channel), JSON_THROW_ON_ERROR), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }
        $city = trim($request->query->getString('city'));
        if ($city !== '') {
            $conditions[] = "LOWER(c.city) LIKE :city ESCAPE '!'";
            $params['city'] = '%' . strtr(mb_strtolower($city), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if ($countries !== []) {
            $conditions[] = 'UPPER(c.country_code) IN (:countries)';
            $params['countries'] = $countries;
            $types['countries'] = \Doctrine\DBAL\ArrayParameterType::STRING;
        }
        if ($currency !== '') {
            $conditions[] = 'c.currency = :currency';
            $params['currency'] = $currency;
        }
        // Include campaigns whose offered budget overlaps the requested range.
        foreach (['budgetMin' => [$budgetMin, 'c.budget_max >='], 'budgetMax' => [$budgetMax, 'c.budget_min <=']] as $key => [$value, $comparison]) {
            if ($value !== null) {
                $conditions[] = $comparison . ' :' . $key;
                $params[$key] = $value;
                $types[$key] = \Doctrine\DBAL\ParameterType::INTEGER;
            }
        }
        $company = trim($request->query->getString('company'));
        if ($company !== '') {
            $conditions[] = 'company.slug = :company';
            $params['company'] = $company;
        }
        if ($request->query->has('featured')) {
            $featuredValue = $request->query->all()['featured'];
            $featured = is_string($featuredValue) ? filter_var($featuredValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
            if ($featured === null) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_featured', $locale)], 400);
            }
            $conditions[] = 'c.featured = :featuredFilter';
            $params['featuredFilter'] = $featured;
            $types['featuredFilter'] = \Doctrine\DBAL\ParameterType::BOOLEAN;
        }
        $base = " FROM campaign c JOIN company ON company.id = c.company_id
            LEFT JOIN wave_user owner ON owner.id = company.owner_id
            LEFT JOIN directory_index i ON i.kind = 'campaign' AND i.entity_id = c.id
            WHERE " . implode(' AND ', $conditions);
        $connection = $entityManager->getConnection();
        $total = $position !== null ? null : (int) $connection->fetchOne('SELECT COUNT(*)' . $base, $params, $types);
        $sql = 'SELECT c.id, c.featured, c.closes_at, c.published_at' . $base;
        if ($position !== null) {
            $sql .= match ($sort) {
                'newest' => ' AND (c.published_at < :date OR (c.published_at = :date AND c.id < :id))',
                'closing' => ' AND (c.closes_at > :date OR (c.closes_at = :date AND c.id > :id))',
                default => ' AND (c.featured < :featured OR (c.featured = :featured AND (c.closes_at > :date OR (c.closes_at = :date AND c.id > :id))))',
            };
            $params += $position;
            if ($sort === 'recommended') {
                $types['featured'] = \Doctrine\DBAL\ParameterType::BOOLEAN;
            }
        }
        $sql .= ' ORDER BY ' . match ($sort) {
            'newest' => 'c.published_at DESC, c.id DESC',
            'closing' => 'c.closes_at ASC, c.id ASC',
            default => 'c.featured DESC, c.closes_at ASC, c.id ASC',
        } . ' LIMIT :limit OFFSET :offset';
        $params += ['limit' => $limit + ($cursorMode ? 1 : 0), 'offset' => $cursorMode ? 0 : $offset];
        $types += ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER];
        $rows = $connection->fetchAllAssociative($sql, $params, $types);
        $hasMore = $cursorMode && count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $ids = array_column($rows, 'id');
        // Hydrate only this batch, together with the media and company used by cards.
        $campaigns = $ids === [] ? [] : $entityManager->getRepository(Campaign::class)->createQueryBuilder('campaign')
            ->join('campaign.company', 'company')->leftJoin('company.owner', 'companyOwner')
            ->leftJoin('campaign.coverMedia', 'coverMedia')->leftJoin('company.logoMedia', 'logoMedia')
            ->addSelect('company', 'companyOwner', 'coverMedia', 'logoMedia')
            ->where('campaign.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        $ranks = array_flip($ids);
        usort($campaigns, static fn (Campaign $a, Campaign $b): int => $ranks[$a->getId()] <=> $ranks[$b->getId()]);
        $last = $campaigns === [] ? null : $campaigns[array_key_last($campaigns)];
        $meta = ['count' => count($campaigns), 'limit' => $limit];
        if ($total !== null) {
            $meta['total'] = $total;
        }
        if ($cursorMode) {
            $meta['hasMore'] = $hasMore;
            $meta['nextCursor'] = !$hasMore || $last === null ? null : $cursor->encode($request, 'campaign', match ($sort) {
                'newest' => ['date' => $last->getPublishedAt()->format('Y-m-d H:i:s'), 'id' => $last->getId()],
                'closing' => ['date' => $last->getClosesAt()->format('Y-m-d H:i:s'), 'id' => $last->getId()],
                default => ['featured' => $last->isFeatured(), 'date' => $last->getClosesAt()->format('Y-m-d H:i:s'), 'id' => $last->getId()],
            });
        } else {
            $meta['offset'] = $offset;
        }
        $categoryLabels = self::categoryLabels($entityManager, $locale);

        return new JsonResponse([
            'data' => array_map(
                static fn (Campaign $campaign): array => CampaignResource::fromEntity(
                    $campaign,
                    $locale,
                    $categoryLabels[$campaign->getCategory()] ?? null,
                    $card,
                    $categoryLabels,
                ),
                $campaigns,
            ),
            'meta' => $meta,
        ]);
    }

    #[Route('/api/campaigns/filters', name: 'api_campaigns_filters', methods: ['GET'])]
    public function filters(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        if (LocaleContext::fromRequest($request) === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $rows = $entityManager->getConnection()->fetchAllAssociative("SELECT c.country_code AS value, COUNT(*) AS count FROM campaign c
            JOIN company ON company.id = c.company_id LEFT JOIN wave_user owner ON owner.id = company.owner_id
            WHERE c.status = 'open' AND c.closes_at >= :today AND c.country_code IS NOT NULL
            AND (owner.id IS NULL OR (owner.approved = TRUE AND owner.hide_my_account = FALSE))
            GROUP BY c.country_code ORDER BY c.country_code", ['today' => (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s')]);

        return new JsonResponse(['data' => ['countries' => array_map(static fn (array $row): array => ['value' => $row['value'], 'count' => (int) $row['count']], $rows)]]);
    }

    #[Route('/api/campaigns/{slug}', name: 'api_campaigns_show', methods: ['GET'])]
    public function show(string $slug, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }

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
        if (!$campaign instanceof Campaign || $campaign->getClosesAt() < new \DateTimeImmutable('today')) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_not_found', $locale)], 404);
        }

        $categoryLabels = self::categoryLabels($entityManager, $locale);

        return new JsonResponse([
            'data' => CampaignResource::fromEntity($campaign, $locale, $categoryLabels[$campaign->getCategory()] ?? null, categoryLabels: $categoryLabels),
        ]);
    }

    private function selectedValues(Request $request, string $key, int $maxLength): array
    {
        $values = json_decode($request->query->getString($key, '[]'));
        if (!is_array($values) || !array_is_list($values) || count($values) > 100
            || array_filter($values, static fn ($value): bool => !is_string($value) || $value === '' || mb_strlen($value) > $maxLength) !== []) {
            throw new \InvalidArgumentException();
        }

        return array_values(array_unique($values));
    }

    private function budgetBound(Request $request, string $key): ?int
    {
        $value = $request->query->getString($key);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^[0-9]{1,8}$/D', $value) || (int) $value > 10_000_000) {
            throw new \InvalidArgumentException();
        }

        return (int) $value;
    }

    private static function categoryLabels(EntityManagerInterface $entityManager, string $locale): array
    {
        $labels = [];
        foreach ($entityManager->getRepository(MarketplaceCategory::class)->findAll() as $category) {
            if ($category instanceof MarketplaceCategory) {
                $labels[$category->getValue()] = $category->label($locale);
            }
        }

        return $labels;
    }
}
