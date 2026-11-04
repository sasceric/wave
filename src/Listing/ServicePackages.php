<?php

declare(strict_types=1);

namespace App\Listing;

use App\Account\CreatorPresentation;
use App\Api\MarketplaceCategoryLabels;
use App\Api\MediaImageResource;
use App\Api\RecommendedProfiles;
use App\Entity\Company;
use App\Entity\Creator;
use App\Safety\BlockPolicy;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Public creator packages for the /usluge page and the homepage row. Packages live as JSON on
 * creators, so eligible creators are loaded once and packages are filtered in PHP.
 */
final class ServicePackages
{
    public const FILTERS = [...CreatorPresentation::SERVICE_TYPES, 'agreement'];
    private const CREATOR_LIMIT = 500;
    /** Service types with their own indexable page, e.g. /usluge/ugc-video. Mirrored in frontend/src/lib/serviceTypes.js. */
    public const PAGE_SLUGS = ['ugc' => 'ugc-video', 'instagram' => 'instagram', 'tiktok' => 'tiktok', 'photo' => 'fotografije'];

    public static function typeForSlug(string $slug): ?string
    {
        $type = array_search($slug, self::PAGE_SLUGS, true);
        return is_string($type) ? $type : null;
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BlockPolicy $blocks,
        private readonly RecommendedProfiles $recommendations,
    ) {}

    /**
     * @param array{type?: string, q?: string, city?: string, category?: string, minPrice?: ?int, maxPrice?: ?int, sort?: string, onePerCreator?: bool} $filters
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>, total: int}
     */
    public function find(string $locale, ?Company $company, array $filters = [], int $limit = 24, int $offset = 0): array
    {
        $visibility = $this->blocks->visible('u.id');
        // Same ranking as the creator directory: featured by position, reviews, completed jobs,
        // profile strength, followers, then a daily rotation.
        $columns = $this->recommendations->columns('creator');
        $joins = $this->recommendations->joins('creator');
        $ids = $this->entityManager->getConnection()->fetchFirstColumn("SELECT id FROM (
                SELECT c.id, $columns FROM creator c JOIN wave_user u ON u.id = c.owner_id $joins
                WHERE c.agency_profile = FALSE AND u.approved = TRUE AND u.hide_my_account = FALSE
                    AND u.deleted_at IS NULL AND u.presentation_demo_key IS NULL AND $visibility
            ) ranked ORDER BY ".RecommendedProfiles::ORDER.' LIMIT '.self::CREATOR_LIMIT,
            $this->recommendations->parameters());
        if ($ids === []) return ['items' => [], 'counts' => array_fill_keys(self::FILTERS, 0), 'total' => 0];

        $creators = $this->entityManager->getRepository(Creator::class)->createQueryBuilder('profile')
            ->leftJoin('profile.avatarMedia', 'media')->addSelect('media')
            ->where('profile.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        $industries = $company?->getIndustries() ?? [];
        $order = array_flip($ids);
        // A company sees creators from its own fields first: featured matches, other featured,
        // other matches, then everyone else. Within each tier the directory ranking is kept.
        $rank = static fn (Creator $creator): int => ($creator->isFeatured() ? 0 : 2)
            + ($industries === [] || array_intersect($creator->getCategories(), $industries) !== [] ? 0 : 1);
        usort($creators, static fn (Creator $a, Creator $b): int => [$rank($a), $order[$a->getId()]] <=> [$rank($b), $order[$b->getId()]]);

        $labels = MarketplaceCategoryLabels::forLocale($this->entityManager, $locale);
        $city = mb_strtolower(trim($filters['city'] ?? ''));
        $category = trim($filters['category'] ?? '');
        $type = $filters['type'] ?? '';
        $query = mb_strtolower(trim($filters['q'] ?? ''));
        $rows = [];
        foreach ($creators as $position => $creator) {
            if ($city !== '' && !str_contains(mb_strtolower(trim((string) $creator->getCity())), $city)) continue;
            if ($category !== '' && !in_array($category, $creator->getCategories(), true)) continue;
            foreach (self::preferred($creator->getPackages()) as $package) {
                $row = $this->row($creator, $package, $labels);
                if ($row === null) continue;
                if ($query !== '' && !str_contains(mb_strtolower($row['title'].' '.$row['creator']['displayName'].' '.implode(' ', $row['creator']['categoryLabels'])), $query)) continue;
                if (isset($filters['minPrice']) && ($row['price'] === null || $row['price'] < $filters['minPrice'])) continue;
                if (isset($filters['maxPrice']) && ($row['price'] === null || $row['price'] > $filters['maxPrice'])) continue;
                $row['position'] = $position;
                $rows[] = $row;
                if ($filters['onePerCreator'] ?? false) break;
            }
        }

        $counts = array_fill_keys(self::FILTERS, 0);
        foreach ($rows as $row) {
            $counts[$row['serviceType']]++;
            if ($row['priceMode'] === 'agreement') $counts['agreement']++;
        }
        if ($type !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $type === 'agreement' ? $row['priceMode'] === 'agreement' : $row['serviceType'] === $type));
        }
        $sort = $filters['sort'] ?? 'recommended';
        if ($sort === 'price') {
            usort($rows, static fn (array $a, array $b): int => [$a['price'] === null ? 1 : 0, $a['price'] ?? 0, $a['position']] <=> [$b['price'] === null ? 1 : 0, $b['price'] ?? 0, $b['position']]);
        } elseif ($sort === 'newest') {
            usort($rows, static fn (array $a, array $b): int => [$b['creatorId'], $a['position']] <=> [$a['creatorId'], $b['position']]);
        }
        $total = count($rows);
        $items = array_map(static function (array $row): array {
            unset($row['position'], $row['creatorId']);
            return $row;
        }, array_slice($rows, $offset, $limit));

        return ['items' => $items, 'counts' => $counts, 'total' => $total];
    }

    /**
     * Packages in the order they should represent the creator: the one the creator featured,
     * otherwise the cheapest priced one, otherwise the creator's own order.
     */
    public static function preferred(array $packages): array
    {
        $packages = array_values(array_filter($packages, 'is_array'));
        $position = array_flip(array_keys($packages));
        $price = static fn (array $package): ?float => is_numeric($package['price'] ?? null) && (float) $package['price'] > 0 ? (float) $package['price'] : null;
        uksort($packages, static fn (int $a, int $b): int => [
            ($packages[$a]['featured'] ?? false) === true ? 0 : 1, $price($packages[$a]) === null ? 1 : 0, $price($packages[$a]) ?? 0, $position[$a],
        ] <=> [
            ($packages[$b]['featured'] ?? false) === true ? 0 : 1, $price($packages[$b]) === null ? 1 : 0, $price($packages[$b]) ?? 0, $position[$b],
        ]);

        return array_values($packages);
    }

    /** @param array<string, string> $labels */
    private function row(Creator $creator, mixed $package, array $labels): ?array
    {
        if (!is_array($package)) return null;
        $title = trim((string) ($package['title'] ?? ''));
        if ($title === '') return null;
        $price = is_numeric($package['price'] ?? null) && (float) $package['price'] > 0 ? (float) $package['price'] : null;

        return [
            'id' => (string) ($package['id'] ?? ''),
            'title' => mb_substr($title, 0, 120),
            'serviceType' => CreatorPresentation::serviceType($package),
            'platform' => (string) ($package['platform'] ?? 'All'),
            'price' => $price,
            'currency' => (string) ($package['currency'] ?? 'BAM'),
            'featured' => ($package['featured'] ?? false) === true,
            'priceMode' => $price === null ? 'agreement' : (($package['priceMode'] ?? null) === 'from' ? 'from' : 'fixed'),
            'creatorId' => (int) $creator->getId(),
            'creator' => [
                'slug' => $creator->getSlug(),
                'displayName' => $creator->getDisplayName(),
                'city' => $creator->getCity(),
                'featured' => $creator->isFeatured(),
                'categoryLabels' => array_map(static fn (string $category): string => $labels[$category] ?? $category, $creator->getCategories()),
                'avatarUrl' => $creator->getAvatarMedia()?->getUrl() ?? $creator->getAvatarUrl(),
                'avatarImage' => MediaImageResource::fromEntity($creator->getAvatarMedia()),
            ],
        ];
    }
}
