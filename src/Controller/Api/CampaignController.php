<?php

namespace App\Controller\Api;

use App\Api\CampaignResource;
use App\Entity\Campaign;
use App\Entity\MarketplaceCategory;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SortDirection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CampaignController
{
    #[Route('/api/campaigns', name: 'api_campaigns_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager): JsonResponse
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

        $query = trim($request->query->getString('q'));
        $category = trim($request->query->getString('category'));
        $company = trim($request->query->getString('company'));
        $builder = $entityManager->getRepository(Campaign::class)->createQueryBuilder('campaign')
            ->join('campaign.company', 'company')
            ->leftJoin('company.owner', 'companyOwner')
            ->andWhere('campaign.status = :status')
            ->andWhere('campaign.closesAt >= :today')
            ->andWhere('companyOwner.id IS NULL OR (companyOwner.approved = :approved AND companyOwner.hideMyAccount = :visible)')
            ->setParameter('status', 'open');
        $builder->setParameter('today', new DateTimeImmutable('today'));
        $builder->setParameter('approved', true);
        $builder->setParameter('visible', false);

        if ($query !== '') {
            $builder
                ->andWhere('LOWER(campaign.title) LIKE :query OR LOWER(campaign.summary) LIKE :query OR LOWER(campaign.description) LIKE :query OR LOWER(campaign.category) LIKE :query OR LOWER(campaign.location) LIKE :query OR LOWER(company.name) LIKE :query OR LOWER(company.industry) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if ($category !== '') {
            $builder
                ->andWhere('LOWER(campaign.category) = :category')
                ->setParameter('category', mb_strtolower($category));
        }
        if ($company !== '') {
            $builder
                ->andWhere('company.slug = :company')
                ->setParameter('company', $company);
        }
        if ($request->query->has('featured')) {
            $featuredValue = $request->query->all()['featured'];
            if (!is_string($featuredValue)) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_featured', $locale)], 400);
            }
            $featured = filter_var($featuredValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($featured === null) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_featured', $locale)], 400);
            }
            $builder
                ->andWhere('campaign.featured = :featured')
                ->setParameter('featured', $featured);
        }

        $total = (int) (clone $builder)
            ->select('COUNT(DISTINCT campaign.id)')
            ->getQuery()
            ->getSingleScalarResult();
        $campaigns = $builder
            ->orderBy('campaign.featured', SortDirection::Descending)
            ->addOrderBy('campaign.closesAt', SortDirection::Ascending)
            ->addOrderBy('campaign.id', SortDirection::Ascending)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
        $categoryLabels = self::categoryLabels($entityManager, $locale);

        return new JsonResponse([
            'data' => array_map(
                static fn (Campaign $campaign): array => CampaignResource::fromEntity(
                    $campaign,
                    $locale,
                    $categoryLabels[$campaign->getCategory()] ?? null,
                ),
                $campaigns,
            ),
            'meta' => ['count' => count($campaigns), 'total' => $total, 'limit' => $limit, 'offset' => $offset],
        ]);
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
        if (!$campaign instanceof Campaign || $campaign->getClosesAt() < new DateTimeImmutable('today')) {
            return new JsonResponse(['error' => ApiMessages::get('campaign_not_found', $locale)], 404);
        }

        $categoryLabels = self::categoryLabels($entityManager, $locale);

        return new JsonResponse([
            'data' => CampaignResource::fromEntity($campaign, $locale, $categoryLabels[$campaign->getCategory()] ?? null),
        ]);
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
