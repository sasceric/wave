<?php

namespace App\Api;

use App\Entity\Creator;
use App\Localization\ApiMessages;
use App\Localization\DemoTranslations;

final class CreatorResource
{
    public static function fromEntity(
        Creator $creator,
        string $locale,
        ?string $categoryLabel = null,
        array $categoryLabels = [],
    ): array
    {
        $translation = $creator->getTranslations()[$locale] ?? [];
        $categories = $creator->getCategories();
        $portfolioMedia = [];
        foreach ($creator->getPortfolioMedia() as $portfolioItem) {
            $portfolioMedia[$portfolioItem->getMedia()->getId()] = $portfolioItem->getMedia()->getUrl();
        }
        $portfolio = array_map(
            static function (array $item) use ($portfolioMedia): array {
                if (isset($item['mediaId'])) {
                    $item['url'] = $portfolioMedia[$item['mediaId']] ?? null;
                }

                return $item;
            },
            $creator->getPortfolio(),
        );
        $packages = array_map(
            static function (array $package) use ($locale): array {
                $package['currency'] = $package['currency'] ?? 'BAM';
                $copyKey = $package['copyKey'] ?? null;
                $translation = is_string($copyKey)
                    ? DemoTranslations::creatorPackage($copyKey, $locale)
                    : [];

                return array_replace($package, $translation);
            },
            $creator->getPackages(),
        );

        return [
            'id' => $creator->getId(),
            'slug' => $creator->getSlug(),
            'featured' => $creator->isFeatured(),
            'displayName' => $creator->getDisplayName(),
            'category' => $translation['category'] ?? $creator->getCategory(),
            'categoryLabel' => $categoryLabel ?? ($translation['category'] ?? $creator->getCategory()),
            'categories' => $categories,
            'categoryLabels' => array_map(
                static fn (string $category): string => $categoryLabels[$category] ?? $category,
                $categories,
            ),
            'location' => $creator->getLocation(),
            'bio' => $translation['bio'] ?? $creator->getBio(),
            'tagline' => $translation['tagline'] ?? ($creator->getTagline() !== '' ? $creator->getTagline() : ($translation['bio'] ?? $creator->getBio())),
            'avatarUrl' => $creator->getAvatarMedia()?->getUrl() ?? $creator->getAvatarUrl(),
            'avatarMediaId' => $creator->getAvatarMedia()?->getId(),
            'socialProfiles' => array_map(
                static fn (array $profile): array => $profile + ['source' => ApiMessages::get('self_reported', $locale)],
                $creator->getSocialProfiles(),
            ),
            'portfolio' => $portfolio,
            'packages' => $packages,
            'faqs' => $creator->getFaqs(),
            'tags' => $translation['tags'] ?? $creator->getTags(),
        ];
    }
}
