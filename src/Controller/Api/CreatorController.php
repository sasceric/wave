<?php

namespace App\Controller\Api;

use App\Api\CreatorResource;
use App\Api\MarketplaceCategoryLabels;
use App\Entity\Creator;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CreatorController
{
    #[Route('/api/creators', name: 'api_creators_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }

        $limit = $request->query->getInt('limit', 24);
        if ($limit < 1 || $limit > 50) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_limit', $locale)], 400);
        }

        $query = trim($request->query->getString('q'));
        $category = trim($request->query->getString('category'));
        $builder = $entityManager->getRepository(Creator::class)->createQueryBuilder('creator')
            ->leftJoin('creator.owner', 'owner')
            ->andWhere('owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible)')
            ->setParameter('approved', true)
            ->setParameter('visible', false);

        if ($query !== '') {
            $builder
                ->andWhere('LOWER(creator.displayName) LIKE :query OR LOWER(creator.bio) LIKE :query OR LOWER(creator.location) LIKE :query OR LOWER(creator.category) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if ($category !== '') {
            $categoryJson = json_encode(mb_strtolower($category), JSON_THROW_ON_ERROR);
            $categoryJson = strtr($categoryJson, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $builder
                ->andWhere("LOWER(creator.category) = :category OR LOWER(creator.categories) LIKE :categoryJson ESCAPE '!'")
                ->setParameter('category', mb_strtolower($category))
                ->setParameter('categoryJson', '%'.$categoryJson.'%');
        }

        $creators = $builder
            ->orderBy('creator.displayName', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
        $categoryLabels = MarketplaceCategoryLabels::forLocale($entityManager, $locale);

        return new JsonResponse([
            'data' => array_map(
                static fn (Creator $creator): array => CreatorResource::fromEntity(
                    $creator,
                    $locale,
                    $categoryLabels[$creator->getCategory()] ?? null,
                    $categoryLabels,
                ),
                $creators,
            ),
            'meta' => ['count' => count($creators), 'limit' => $limit],
        ]);
    }

    #[Route('/api/creators/{slug}', name: 'api_creators_show', methods: ['GET'])]
    public function show(
        string $slug,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
    ): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }

        $creator = $entityManager->getRepository(Creator::class)->createQueryBuilder('creator')
            ->leftJoin('creator.owner', 'owner')
            ->andWhere('creator.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
        $owner = $creator instanceof Creator ? $creator->getOwner() : null;
        if (!$creator instanceof Creator
            || ($owner !== null && !$owner->isApproved() && !$security->isGranted('ROLE_ADMIN'))
            || ($owner !== null
                && $owner->isHideMyAccount()
                && $security->getUser() !== $owner
                && !$security->isGranted('ROLE_ADMIN'))
        ) {
            return new JsonResponse(['error' => ApiMessages::get('creator_not_found', $locale)], 404);
        }

        $categoryLabels = MarketplaceCategoryLabels::forLocale($entityManager, $locale);

        return new JsonResponse([
            'data' => CreatorResource::fromEntity(
                $creator,
                $locale,
                $categoryLabels[$creator->getCategory()] ?? null,
                $categoryLabels,
            ),
        ]);
    }
}
