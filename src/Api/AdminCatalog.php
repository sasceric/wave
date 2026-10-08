<?php

namespace App\Api;

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
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

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function page(Request $request, string $kind, string $locale): array
    {
        $builder = $this->query($kind);
        $limit = $request->query->getInt('limit', 25);
        $page = $request->query->getInt('page', 1);
        if (!in_array($limit, [25, 50, 100], true) || $page < 1 || $page > 1000000) {
            throw new \InvalidArgumentException('Invalid admin page.');
        }
        $columns = match ($kind) {
            'creators' => ['displayName' => 'item.displayName', 'category' => 'item.category', 'city' => 'COALESCE(owner.city, item.city)'],
            'companies' => ['name' => 'item.name', 'industry' => 'item.industry', 'verified' => 'item.verified'],
            'campaigns' => ['title' => 'item.title', 'company.name' => 'company.name', 'status' => 'item.status'],
            'registrations' => ['approved' => 'item.approved', 'name' => 'COALESCE(creator.displayName, company.name, item.email)', 'email' => 'item.email', 'accountType' => 'item.role', 'emailVerified' => 'item.emailVerified'],
            default => throw new \InvalidArgumentException('Unknown catalog.'),
        };
        $sort = $request->query->getString('sort', array_key_first($columns));
        $direction = strtoupper($request->query->getString('direction', 'asc'));
        if (!isset($columns[$sort]) || !in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Invalid admin sort.');
        }
        $query = trim($request->query->getString('q'));
        if (mb_strlen($query) > 200) {
            throw new \InvalidArgumentException('Search is too long.');
        }
        if ($query !== '') {
            $searchColumns = array_filter($columns, static fn (string $column): bool => !in_array($column, ['item.verified', 'item.approved', 'item.emailVerified'], true));
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
        $items = $builder->orderBy($columns[$sort], $sortDirection)->addOrderBy('item.id', $sortDirection)
            ->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        $categoryLabels = $kind === 'companies' ? MarketplaceCategoryLabels::forLocale($this->entityManager, $locale) : [];

        return [
            'data' => array_map(fn (object $item): array => $this->resource($item, $locale, $categoryLabels), $items),
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'count' => count($items)],
        ];
    }

    public function featured(string $kind, string $locale): array
    {
        $categoryLabels = $kind === 'companies' ? MarketplaceCategoryLabels::forLocale($this->entityManager, $locale) : [];

        return array_map(fn (object $item): array => $this->resource($item, $locale, $categoryLabels),
            $this->query($kind)->andWhere('item.featured = :featured')->setParameter('featured', true)->orderBy('item.id', \SortDirection::Ascending)->getQuery()->getResult());
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
        } elseif ($kind === 'campaigns') {
            $builder->join('item.company', 'company')->addSelect('company');
        } else {
            $builder->leftJoin('item.creator', 'creator')->leftJoin('item.company', 'company')->addSelect('creator', 'company')
                ->andWhere('item.role IN (:roles)')->andWhere('item.admin = false')->andWhere('item.moderator = false')->andWhere('item.approved = false')
                ->setParameter('roles', ['ROLE_CREATOR', 'ROLE_COMPANY']);
        }

        return $builder;
    }

    private function resource(object $item, string $locale, array $categoryLabels = []): array
    {
        if ($item instanceof Creator) {
            return [
                'id' => $item->getId(),
                'slug' => $item->getSlug(),
                'displayName' => $item->getDisplayName(),
                'category' => $item->getCategory(),
                'city' => $item->getCity(),
                'avatarUrl' => $item->getAvatarMedia()?->getUrl() ?? $item->getAvatarUrl(),
                'featured' => $item->isFeatured(),
            ];
        }
        if ($item instanceof Company) {
            $resource = CompanyResource::fromEntity($item, $locale, card: true, categoryLabels: $categoryLabels);
            unset($resource['logoImage']);

            return $resource;
        }
        if ($item instanceof Campaign) {
            return [
                'id' => $item->getId(),
                'slug' => $item->getSlug(),
                'title' => $item->getTitle(),
                'status' => $item->getStatus(),
                'featured' => $item->isFeatured(),
                'company' => ['name' => $item->getCompany()->getName()],
            ];
        }
        if (!$item instanceof User) {
            throw new \LogicException('Unsupported catalog entity.');
        }

        return [
            'id' => $item->getId(),
            'email' => $item->getEmail(),
            'accountType' => $item->hasRole('ROLE_CREATOR') ? 'creator' : 'company',
            'name' => $item->getCreator()?->getDisplayName() ?? $item->getCompany()?->getName() ?? $item->getEmail(),
            'profileSlug' => $item->getCreator()?->getSlug() ?? $item->getCompany()?->getSlug(),
            'emailVerified' => $item->isEmailVerified(),
            'approved' => $item->isApproved(),
            'profileComplete' => $item->hasCompleteProfile(),
        ];
    }
}
