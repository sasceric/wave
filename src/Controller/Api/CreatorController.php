<?php

namespace App\Controller\Api;

use App\Api\CreatorResource;
use App\Api\DirectoryCursor;
use App\Api\MarketplaceCategoryLabels;
use App\Entity\Creator;
use App\Entity\DirectoryIndex;
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
            $cursorMode = $cursor->enabled($request);
            $position = $cursorMode ? $cursor->decode($request, 'creator', ['name' => 'string', 'id' => 'int']) : null;
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $card = $request->query->getString('view') === 'card';
        $query = trim($request->query->getString('q'));
        $platform = trim($request->query->getString('platform'));
        $category = trim($request->query->getString('category'));
        $builder = $entityManager->getRepository(Creator::class)->createQueryBuilder('creator')
            ->leftJoin('creator.owner', 'owner')
            ->leftJoin(DirectoryIndex::class, 'searchIndex', 'WITH', "searchIndex.kind = 'creator' AND searchIndex.entityId = creator.id")
            ->andWhere('owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible)')
            ->setParameter('approved', true)
            ->setParameter('visible', false);

        if ($query !== '') {
            $builder
                ->andWhere('searchIndex.searchText LIKE :query OR COALESCE(searchIndex.tagText, LOWER(JSON_TEXT(creator.tags))) LIKE :query OR (searchIndex.id IS NULL AND (LOWER(creator.displayName) LIKE :query OR LOWER(creator.bio) LIKE :query OR LOWER(creator.location) LIKE :query OR LOWER(creator.category) LIKE :query))')
                ->setParameter('query', '%' . mb_strtolower($query) . '%');
        }
        if ($category !== '') {
            $categoryJson = json_encode(mb_strtolower($category), JSON_THROW_ON_ERROR);
            $categoryJson = strtr($categoryJson, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $builder
                ->andWhere("LOWER(creator.category) = :category OR LOWER(JSON_TEXT(creator.categories)) LIKE :categoryJson ESCAPE '!'")
                ->setParameter('category', mb_strtolower($category))
                ->setParameter('categoryJson', '%' . $categoryJson . '%');
        }
        if ($platform !== '') {
            $normalizedPlatform = mb_strtolower($platform);
            $builder
                ->andWhere("(searchIndex.id IS NOT NULL AND searchIndex.platformKeys LIKE :platformKey ESCAPE '!') OR (searchIndex.id IS NULL AND LOWER(JSON_TEXT(creator.socialProfiles)) LIKE :platformCompact) OR (searchIndex.id IS NULL AND LOWER(JSON_TEXT(creator.socialProfiles)) LIKE :platformSpaced)")
                ->setParameter('platformKey', '%"' . strtr($normalizedPlatform, ['!' => '!!', '%' => '!%', '_' => '!_']) . '"%')
                ->setParameter('platformCompact', '%"platform":"' . $normalizedPlatform . '"%')
                ->setParameter('platformSpaced', '%"platform": "' . $normalizedPlatform . '"%');
        }

        $total = $position !== null ? null : (int) (clone $builder)
            ->select('COUNT(DISTINCT creator.id)')
            ->getQuery()
            ->getSingleScalarResult();
        $cursor->seek($builder, ['name' => ['creator.displayName', 'ASC'], 'id' => ['creator.id', 'ASC']], $position);
        $creators = $builder
            ->leftJoin('creator.avatarMedia', 'avatarMedia')
            ->addSelect('avatarMedia', 'owner')
            ->orderBy('creator.displayName', \SortDirection::Ascending)
            ->addOrderBy('creator.id', \SortDirection::Ascending)
            ->setFirstResult($cursorMode ? 0 : $offset)
            ->setMaxResults($limit + ($cursorMode ? 1 : 0))
            ->getQuery()
            ->getResult();
        $hasMore = $cursorMode && count($creators) > $limit;
        $creators = array_slice($creators, 0, $limit);
        $last = $creators === [] ? null : $creators[array_key_last($creators)];
        $meta = ['count' => count($creators), 'limit' => $limit];
        if ($total !== null) {
            $meta['total'] = $total;
        }
        if ($cursorMode) {
            $meta['hasMore'] = $hasMore;
            $meta['nextCursor'] = !$hasMore || $last === null ? null : $cursor->encode($request, 'creator', ['name' => $last->getDisplayName(), 'id' => $last->getId()]);
        } else {
            $meta['offset'] = $offset;
        }
        $categoryLabels = MarketplaceCategoryLabels::forLocale($entityManager, $locale);

        if (!$card && $creators !== []) {
            // Fetch the bounded page's portfolio associations in one query; joining
            // a collection into the paginated query would truncate creator pages.
            $entityManager->createQueryBuilder()
                ->select('creator', 'portfolioMedia', 'media')
                ->from(Creator::class, 'creator')
                ->leftJoin('creator.portfolioMedia', 'portfolioMedia')
                ->leftJoin('portfolioMedia.media', 'media')
                ->where('creator IN (:creators)')
                ->setParameter('creators', $creators)
                ->getQuery()->getResult();
        }

        return new JsonResponse([
            'data' => array_map(
                static fn (Creator $creator): array => CreatorResource::fromEntity(
                    $creator,
                    $locale,
                    $categoryLabels[$creator->getCategory()] ?? null,
                    $categoryLabels,
                    $card,
                ),
                $creators,
            ),
            'meta' => $meta,
        ]);
    }

    #[Route('/api/creators/{slug}', name: 'api_creators_show', methods: ['GET'])]
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
