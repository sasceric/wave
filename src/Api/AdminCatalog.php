<?php

namespace App\Api;

use App\Entity\Campaign;
use App\Entity\AccountMilestone;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use App\Reviews\CollaborationFeedback;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

/** Bounded admin lists; filtering and sorting are applied before pagination. */
final class AdminCatalog
{
    private const ENTITIES = [
        'creators' => Creator::class,
        'companies' => Company::class,
        'campaigns' => Campaign::class,
        'registrations' => User::class,
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly CollaborationFeedback $feedback)
    {
    }

    public function page(Request $request, string $kind, string $locale): array
    {
        $builder = $this->query($kind);
        if ($kind === 'creators') $builder->andWhere('item.agencyProfile = false');
        $limit = $request->query->getInt('limit', 25);
        $page = $request->query->getInt('page', 1);
        if (!in_array($limit, [20, 25, 50, 100], true) || $page < 1 || $page > 1000000) {
            throw new \InvalidArgumentException('Invalid admin page.');
        }
        $columns = match ($kind) {
            'creators' => ['displayName' => 'item.displayName', 'category' => 'item.category', 'city' => 'COALESCE(owner.city, item.city)', 'createdAt' => 'item.createdAt'],
            'companies' => ['name' => 'item.name', 'industry' => 'item.industry', 'verified' => 'item.verified', 'createdAt' => 'registration.occurredAt'],
            'campaigns' => ['title' => 'item.title', 'company.name' => 'company.name', 'status' => 'item.status', 'createdAt' => 'item.publishedAt'],
            'registrations' => ['approved' => 'item.approved', 'name' => 'COALESCE(creator.displayName, company.name, item.email)', 'email' => 'item.email', 'accountType' => 'item.role', 'emailVerified' => 'item.emailVerified', 'createdAt' => 'registration.occurredAt'],
            default => throw new \InvalidArgumentException('Unknown catalog.'),
        };
        $sort = $request->query->getString('sort', 'createdAt');
        $direction = strtoupper($request->query->getString('direction', $sort === 'createdAt' ? 'desc' : 'asc'));
        if (!isset($columns[$sort]) || !in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Invalid admin sort.');
        }
        if ($sort === 'createdAt' && in_array($kind, ['companies', 'registrations'], true)) {
            $builder->leftJoin(AccountMilestone::class, 'registration', 'ON', "registration.user = ".($kind === 'registrations' ? 'item' : 'owner')." AND registration.stage = 'registered'");
            $builder->addSelect('CASE WHEN registration.occurredAt IS NULL THEN 1 ELSE 0 END AS HIDDEN creationTimeMissing');
        }
        $query = trim($request->query->getString('q'));
        if (mb_strlen($query) > 200) {
            throw new \InvalidArgumentException('Search is too long.');
        }
        if ($query !== '') {
            $searchColumns = array_diff_key($columns, array_flip(['verified', 'approved', 'emailVerified', 'createdAt']));
            $builder->andWhere(implode(' OR ', array_map(static fn (string $column): string => "LOWER(" . $column . ") LIKE :search ESCAPE '!'", $searchColumns)))
                ->setParameter('search', '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)) . '%');
        }
        $verified = $request->query->getString('verified', 'all');
        if ($kind === 'companies' && $verified !== 'all') {
            if (!in_array($verified, ['true', 'false'], true)) {
                throw new \InvalidArgumentException('Invalid company filter.');
            }
            $builder->andWhere('item.verified = :verified')->setParameter('verified', $verified === 'true');
        }
        $total = (int) (clone $builder)->select('COUNT(item.id)')->getQuery()->getSingleScalarResult();
        $page = min($page, max(1, (int) ceil($total / $limit)));
        $sortDirection = $direction === 'ASC' ? \SortDirection::Ascending : \SortDirection::Descending;
        $builder->orderBy($columns[$sort], $sortDirection)->addOrderBy('item.id', $sortDirection);
        if ($sort === 'createdAt' && in_array($kind, ['companies', 'registrations'], true)) {
            $builder->orderBy('creationTimeMissing', \SortDirection::Ascending)->addOrderBy($columns[$sort], $sortDirection)->addOrderBy('item.id', $sortDirection);
        }
        $items = $builder->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        $categoryLabels = $kind === 'companies' ? MarketplaceCategoryLabels::forLocale($this->entityManager, $locale) : [];

        $ids = in_array($kind, ['creators', 'companies'], true) ? array_values(array_filter(array_map(static fn ($profile) => $profile->getOwner()?->getId(), $items))) : [];
        $ratings = $this->feedback->summaries($ids);
        $totals = $this->feedback->financialTotals($ids, $kind === 'creators');
        $rows = array_map(fn (object $item): array => $this->resource($item, $locale, $categoryLabels), $items);
        $accountIds = $kind === 'registrations' ? array_map(static fn (User $user): ?int => $user->getId(), $items) : ($kind === 'companies' ? $ids : []);
        $registeredAt = $accountIds === [] ? [] : $this->entityManager->getConnection()->fetchAllKeyValue(
            "SELECT user_id, occurred_at FROM account_milestone WHERE stage = 'registered' AND user_id IN (?)",
            [$accountIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        foreach ($items as $index => $item) {
            if ($item instanceof Creator) {
                $rows[$index]['createdAt'] = $item->getCreatedAt()->format(DATE_ATOM);
            } elseif ($item instanceof Campaign) {
                $rows[$index]['createdAt'] = $item->getPublishedAt()->format(DATE_ATOM);
            } elseif ($item instanceof Company || $item instanceof User) {
                $ownerId = $item instanceof User ? $item->getId() : $item->getOwner()?->getId();
                $date = $registeredAt[$ownerId] ?? null;
                $rows[$index]['createdAt'] = $date === null ? null : (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->format(DATE_ATOM);
            }
            if ($item instanceof Creator || $item instanceof Company) {
                $ownerId = $item->getOwner()?->getId();
                $rows[$index]['rating'] = $ratings[$ownerId] ?? $this->feedback->summary(null);
                $rows[$index]['financialTotals'] = $totals[$ownerId] ?? [];
            }
        }

        return [
            'data' => $rows,
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'count' => count($items)],
        ];
    }

    public function featured(string $kind, string $locale): array
    {
        $categoryLabels = $kind === 'companies' ? MarketplaceCategoryLabels::forLocale($this->entityManager, $locale) : [];

        $builder = $this->query($kind);
        if ($kind === 'creators') $builder->andWhere('item.agencyProfile = false');
        return array_map(fn (object $item): array => $this->resource($item, $locale, $categoryLabels),
            $builder->andWhere('item.featured = :featured')->setParameter('featured', true)->addSelect('COALESCE(item.featuredPosition, 2147483647) AS HIDDEN featuredOrder')->orderBy('featuredOrder', \SortDirection::Ascending)->addOrderBy('item.id', \SortDirection::Ascending)->getQuery()->getResult());
    }

    private function query(string $kind): QueryBuilder
    {
        if (!isset(self::ENTITIES[$kind])) {
            throw new \InvalidArgumentException('Unknown catalog.');
        }
        $builder = $this->entityManager->getRepository(self::ENTITIES[$kind])->createQueryBuilder('item');
        if ($kind === 'creators' || $kind === 'companies') {
            $media = $kind === 'creators' ? 'avatarMedia' : 'logoMedia';
            $builder->leftJoin('item.' . $media, 'media')->addSelect('media')
                ->leftJoin('item.owner', 'owner')->addSelect('owner')->andWhere('owner.id IS NULL OR owner.approved = :approved')->setParameter('approved', true);
            if ($kind === 'companies') $builder->leftJoin('owner.creator', 'studio')->leftJoin('item.coverMedia', 'cover')->addSelect('studio', 'cover');
        } elseif ($kind === 'campaigns') {
            $builder->join('item.company', 'company')->addSelect('company')
                ->leftJoin('item.coverMedia', 'cover')->addSelect('cover');
        } else {
            $builder->leftJoin('item.creator', 'creator')->leftJoin('item.company', 'company')->addSelect('creator', 'company')
                ->leftJoin('creator.avatarMedia', 'avatar')->leftJoin('company.logoMedia', 'logo')->addSelect('avatar', 'logo')
                ->leftJoin('company.coverMedia', 'cover')->addSelect('cover')
                ->andWhere('item.role IN (:roles)')->andWhere('item.admin = false')->andWhere('item.moderator = false')->andWhere('item.approved = false')
                ->setParameter('roles', ['ROLE_CREATOR', 'ROLE_COMPANY']);
        }

        return $builder;
    }

    private function resource(object $item, string $locale, array $categoryLabels = []): array
    {
        if ($item instanceof Creator) {
            return AdminProfileResource::summary($item) + [
                'id' => $item->getId(),
                'slug' => $item->getSlug(),
                'displayName' => $item->getDisplayName(),
                'category' => $item->getCategory(),
                'city' => $item->getCity(),
                'avatarUrl' => $item->getAvatarMedia()?->getUrl() ?? $item->getAvatarUrl(),
                'featured' => $item->isFeaturedConfigured(),
                'featuredUntil' => $item->getFeaturedUntil()?->modify('-1 day')->format('Y-m-d'),
                'featuredPosition' => $item->getFeaturedPosition(),
            ];
        }
        if ($item instanceof Company) {
            $resource = CompanyResource::fromEntity($item, $locale, card: true, categoryLabels: $categoryLabels);
            return ['featuredPosition' => $item->getFeaturedPosition(), 'featured' => $item->isFeaturedConfigured(), 'featuredUntil' => $item->getFeaturedUntil()?->modify('-1 day')->format('Y-m-d')] + AdminProfileResource::summary($item) + $resource;
        }
        if ($item instanceof Campaign) {
            return [
                'id' => $item->getId(),
                'slug' => $item->getSlug(),
                'title' => $item->getTitle(),
                'status' => $item->getStatus(),
                'listed' => $item->isListed(),
                'featured' => $item->isFeatured(),
                'featuredPosition' => $item->getFeaturedPosition(),
                'coverImageUrl' => $item->getCoverMedia()?->getUrl(),
                'coverImage' => MediaImageResource::fromEntity($item->getCoverMedia(), 96),
                'company' => ['name' => $item->getCompany()->getName()],
            ];
        }
        if (!$item instanceof User) {
            throw new \LogicException('Unsupported catalog entity.');
        }

        return AdminProfileResource::summary($item) + [
            'id' => $item->getId(),
            'email' => $item->getEmail(),
            'accountType' => $item->hasRole('ROLE_CREATOR') ? 'creator' : 'company',
            'name' => $item->getCompany()?->getName() ?? $item->getCreator()?->getDisplayName() ?? $item->getEmail(),
            'profileSlug' => $item->getCompany()?->getSlug() ?? $item->getCreator()?->getSlug(),
            'emailVerified' => $item->isEmailVerified(),
            'approved' => $item->isApproved(),
            'profileComplete' => $item->hasCompleteProfile(),
        ];
    }
}
