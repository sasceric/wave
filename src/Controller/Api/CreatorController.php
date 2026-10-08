<?php

namespace App\Controller\Api;

use App\Account\CreatorTypes;
use App\Api\CreatorResource;
use App\Api\DirectoryCursor;
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
            $creatorTypes = CreatorTypes::parse($this->selectedValues($request, 'creatorTypes', 30));
            if ($creatorTypes === null) {
                throw new \InvalidArgumentException();
            }
            $platforms = $this->selectedValues($request, 'platforms', 30);
            $countries = $this->selectedValues($request, 'countries', 2);
            foreach ($countries as $country) {
                if (!preg_match('/^[A-Za-z]{2}$/D', $country)) {
                    throw new \InvalidArgumentException();
                }
            }
            $countries = array_map(strtoupper(...), $countries);
            $sort = $request->query->getString('sort', 'name');
            $audience = $request->query->getString('audience');
            if (!in_array($sort, ['name', 'newest', 'followers'], true) || !in_array($audience, ['', 'small', 'medium', 'large'], true)) {
                throw new \InvalidArgumentException();
            }
            $cursorMode = $cursor->enabled($request);
            $positionTypes = match ($sort) {
                'newest' => ['date' => 'date', 'id' => 'int'],
                'followers' => ['followers' => 'int', 'id' => 'int'],
                default => ['name' => 'string', 'id' => 'int'],
            };
            $position = $cursorMode ? $cursor->decode($request, 'creator', $positionTypes) : null;
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $platform = trim($request->query->getString('platform'));
        $category = trim($request->query->getString('category'));
        if ($platform !== '') {
            $platforms[] = $platform;
        }
        if ($category !== '') {
            $categories[] = $category;
        }
        $params = [];
        $types = [];
        $conditions = [];
        $query = trim($request->query->getString('q'));
        if ($query !== '') {
            $conditions[] = "(COALESCE(search_text, LOWER(display_name || ' ' || bio || ' ' || COALESCE(city, '') || ' ' || category)) LIKE :query OR COALESCE(tag_text, LOWER(CAST(tags AS TEXT))) LIKE :query)";
            $params['query'] = '%' . mb_strtolower($query) . '%';
        }
        if ($categories !== []) {
            $parts = [];
            foreach (array_unique($categories) as $index => $value) {
                $parts[] = "(LOWER(category) = :category$index OR LOWER(CAST(categories AS TEXT)) LIKE :categoryJson$index ESCAPE '!')";
                $params['category' . $index] = mb_strtolower($value);
                $params['categoryJson' . $index] = '%' . strtr(json_encode(mb_strtolower($value), JSON_THROW_ON_ERROR), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }
        if ($creatorTypes !== []) {
            $parts = [];
            foreach ($creatorTypes as $index => $value) {
                $parts[] = "CAST(creator_types AS TEXT) LIKE :creatorType$index";
                $params['creatorType' . $index] = '%"' . $value . '"%';
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }
        if ($platforms !== []) {
            $parts = [];
            foreach (array_unique($platforms) as $index => $value) {
                $parts[] = "(platform_keys LIKE :platformKey$index ESCAPE '!' OR (platform_keys IS NULL AND (LOWER(CAST(social_profiles AS TEXT)) LIKE :platformCompact$index ESCAPE '!' OR LOWER(CAST(social_profiles AS TEXT)) LIKE :platformSpaced$index ESCAPE '!')))";
                $escaped = strtr(mb_strtolower($value), ['!' => '!!', '%' => '!%', '_' => '!_']);
                $params['platformKey' . $index] = '%"' . $escaped . '"%';
                $params['platformCompact' . $index] = '%"platform":"' . $escaped . '"%';
                $params['platformSpaced' . $index] = '%"platform": "' . $escaped . '"%';
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }
        $city = trim($request->query->getString('city'));
        if ($city !== '') {
            $conditions[] = "LOWER(COALESCE(city, '')) LIKE :city";
            $params['city'] = '%' . mb_strtolower($city) . '%';
        }
        if ($countries !== []) {
            $conditions[] = 'UPPER(country_code) IN (:countries)';
            $params['countries'] = $countries;
            $types['countries'] = \Doctrine\DBAL\ArrayParameterType::STRING;
        }
        if ($audience !== '') {
            // The largest channel is used; summing platforms would double-count people.
            $conditions[] = match ($audience) {
                'small' => 'followers >= 1 AND followers < 10000',
                'medium' => 'followers >= 10000 AND followers < 50000',
                'large' => 'followers >= 50000',
            };
        }
        $metric = ($sort === 'followers' || $audience !== '') ? self::followersSql('c.social_profiles') : '0';
        $base = "SELECT c.id, c.display_name, c.created_at, c.category, c.categories, c.creator_types, c.bio, c.tags, c.social_profiles,
            COALESCE(u.city, c.city) AS city, u.country_code, i.search_text, i.tag_text, i.platform_keys, $metric AS followers
            FROM creator c LEFT JOIN wave_user u ON u.id = c.owner_id
            LEFT JOIN directory_index i ON i.kind = 'creator' AND i.entity_id = c.id
            WHERE u.id IS NULL OR (u.approved = TRUE AND u.hide_my_account = FALSE)";
        $filtered = ' FROM (' . $base . ') ranked WHERE 1 = 1' . ($conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions));
        $connection = $entityManager->getConnection();
        $total = $position !== null ? null : (int) $connection->fetchOne('SELECT COUNT(*)' . $filtered, $params, $types);
        $sql = 'SELECT id, display_name, created_at, followers' . $filtered;
        if ($position !== null) {
            $sql .= match ($sort) {
                'newest' => ' AND (created_at < :date OR (created_at = :date AND id < :id))',
                'followers' => ' AND (followers < :followers OR (followers = :followers AND id < :id))',
                default => ' AND (display_name > :name OR (display_name = :name AND id > :id))',
            };
            $params += $position;
        }
        $sql .= ' ORDER BY ' . match ($sort) {
            'newest' => 'created_at DESC, id DESC',
            'followers' => 'followers DESC, id DESC',
            default => 'display_name ASC, id ASC',
        } . ' LIMIT :limit OFFSET :offset';
        $params += ['limit' => $limit + ($cursorMode ? 1 : 0), 'offset' => $cursorMode ? 0 : $offset];
        $types += ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER];
        $rows = $connection->fetchAllAssociative($sql, $params, $types);
        $hasMore = $cursorMode && count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $ids = array_column($rows, 'id');
        $creators = $ids === [] ? [] : $entityManager->getRepository(Creator::class)->createQueryBuilder('creator')
            ->leftJoin('creator.owner', 'owner')->leftJoin('creator.avatarMedia', 'avatarMedia')
            ->addSelect('owner', 'avatarMedia')->where('creator.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        $ranks = array_flip($ids);
        usort($creators, static fn (Creator $a, Creator $b): int => $ranks[$a->getId()] <=> $ranks[$b->getId()]);
        $meta = ['count' => count($creators), 'limit' => $limit];
        if ($total !== null) {
            $meta['total'] = $total;
        }
        if ($cursorMode) {
            $last = $rows === [] ? null : $rows[array_key_last($rows)];
            $meta['hasMore'] = $hasMore;
            $meta['nextCursor'] = !$hasMore || $last === null ? null : $cursor->encode($request, 'creator', match ($sort) {
                'newest' => ['date' => (new \DateTimeImmutable($last['created_at']))->format('Y-m-d H:i:s'), 'id' => (int) $last['id']],
                'followers' => ['followers' => (int) $last['followers'], 'id' => (int) $last['id']],
                default => ['name' => $last['display_name'], 'id' => (int) $last['id']],
            });
        } else {
            $meta['offset'] = $offset;
        }
        $card = $request->query->getString('view') === 'card';
        $categoryLabels = MarketplaceCategoryLabels::forLocale($entityManager, $locale);
        if (!$card && $creators !== []) {
            $entityManager->createQueryBuilder()->select('creator', 'portfolioMedia', 'media')->from(Creator::class, 'creator')
                ->leftJoin('creator.portfolioMedia', 'portfolioMedia')->leftJoin('portfolioMedia.media', 'media')
                ->where('creator IN (:creators)')->setParameter('creators', $creators)->getQuery()->getResult();
        }
        return new JsonResponse(['data' => array_map(static fn (Creator $creator): array => CreatorResource::fromEntity($creator, $locale, $categoryLabels[$creator->getCategory()] ?? null, $categoryLabels, $card), $creators), 'meta' => $meta]);
    }

    private function selectedValues(Request $request, string $key, int $maxLength): array
    {
        $values = json_decode($request->query->getString($key, '[]'), true);
        if (!is_array($values) || !array_is_list($values) || count($values) > 100 || array_filter($values, static fn ($value): bool => !is_string($value) || $value === '' || mb_strlen($value) > $maxLength) !== []) {
            throw new \InvalidArgumentException();
        }
        return array_values(array_unique($values));
    }

    private static function followersSql(string $column): string
    {
        return "(SELECT COALESCE(MAX(CASE WHEN profile->>'followers' ~ '^[0-9]{1,10}$' THEN LEAST((profile->>'followers')::BIGINT, 2147483647) ELSE 0 END), 0) FROM jsonb_array_elements(CAST($column AS jsonb)) profile)";
    }

    #[Route('/api/creators/filters', name: 'api_creators_filters', methods: ['GET'], priority: 10)]
    public function filters(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $rows = $entityManager->getConnection()->fetchAllAssociative("SELECT UPPER(u.country_code) AS value, COUNT(*) AS count FROM creator c LEFT JOIN wave_user u ON u.id = c.owner_id WHERE (u.id IS NULL OR (u.approved = TRUE AND u.hide_my_account = FALSE)) AND u.country_code IS NOT NULL AND u.country_code <> '' GROUP BY UPPER(u.country_code) ORDER BY UPPER(u.country_code)");
        return new JsonResponse(['data' => ['countries' => array_map(static fn (array $row): array => ['value' => $row['value'], 'count' => (int) $row['count']], $rows)]]);
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
