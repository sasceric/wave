<?php

namespace App\Api;

use App\Entity\Creator;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\DemoTranslations;

final class CreatorResource
{
    public static function fromEntity(
        Creator $creator,
        string $locale,
        ?string $categoryLabel = null,
        array $categoryLabels = [],
        bool $card = false,
        bool $public = true,
    ): array {
        $translation = $creator->getTranslations()[$locale] ?? [];
        $owner = $creator->getOwner();
        $categories = $creator->getCategories();
        $categoryLabel ??= $categoryLabels[$creator->getCategory()] ?? null;
        if ($card) {
            $primarySocial = \App\Account\SocialAudience::largest($creator->getSocialProfiles());
            return [
                'id' => $creator->getId(),
                'slug' => $creator->getSlug(),
            'publicUrlChangeAvailableAt' => $creator->getPublicUrlChangedAt()?->modify('+14 days')->format(DATE_ATOM),
                'featured' => $creator->isFeatured(),
                'city' => $creator->getCity(),
                'displayName' => $owner?->isDeleted() ? ApiMessages::get('deleted_account', $locale) : $creator->getDisplayName(),
                'deleted' => $owner?->isDeleted() ?? false,
                'isPresentationDemo' => $owner?->isPresentationDemo() ?? false,
                'creatorTypes' => $creator->getCreatorTypes(),
                'category' => $translation['category'] ?? $creator->getCategory(),
                'categoryLabel' => $categoryLabel ?? ($translation['category'] ?? $creator->getCategory()),
                'categoryLabels' => array_map(static fn (string $category): string => $categoryLabels[$category] ?? $category, $categories),
                'avatarUrl' => $creator->getAvatarMedia()?->getUrl() ?? $creator->getAvatarUrl(),
                'avatarImage' => MediaImageResource::fromEntity($creator->getAvatarMedia()),
                'socialProfiles' => $primarySocial === null ? [] : [array_intersect_key($primarySocial, array_flip(['platform', 'followers']))],
                'packages' => array_map(static fn (array $package): array => ['price' => $package['price'] ?? null, 'currency' => $package['currency'] ?? 'BAM'], $creator->getPackages()),
            ];
        }
        $portfolioMedia = [];
        foreach ($creator->getPortfolioMedia() as $portfolioItem) {
            $portfolioMedia[$portfolioItem->getMedia()->getId()] = $portfolioItem->getMedia()->getUrl();
        }
        $portfolio = array_map(
            static function (array $item) use ($portfolioMedia): array {
                if (isset($item['mediaId'])) {
                    $item['url'] = $portfolioMedia[$item['mediaId']] ?? null;
                }
                $item['coverUrl'] = $portfolioMedia[$item['coverMediaId'] ?? 0] ?? null;
                $item['media'] = array_map(static function (array $media) use ($portfolioMedia): array {
                    if (isset($media['mediaId'])) $media['url'] = $portfolioMedia[$media['mediaId']] ?? null;
                    return $media;
                }, $item['media'] ?? []);

                if (isset($item['references'])) {
                    $item['references'] = array_map(static function (array $reference) use ($portfolioMedia): array {
                        if (isset($reference['mediaId'])) {
                            $reference['imageUrl'] = $portfolioMedia[$reference['mediaId']] ?? null;
                        }
                        return $reference;
                    }, $item['references']);
                }

                return $item;
            },
            array_values(array_filter($creator->getPortfolio(), static fn (array $item): bool => !$public || ($item['publicPermission'] ?? true))),
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

        $resource = [
            'ownerId' => $owner?->getId(),
            'profileRoute' => 'creator-profile',
            'isAgency' => $creator->isAgencyProfile(),
            'id' => $creator->getId(),
            'canReceiveCampaignInvitations' => $owner instanceof User
                && $owner->isApproved()
                && $owner->isEmailVerified()
                && !$owner->isHideMyAccount(),
            'slug' => $creator->getSlug(),
            'publicUrlChangeAvailableAt' => $creator->getPublicUrlChangedAt()?->modify('+14 days')->format(DATE_ATOM),
            'featured' => $creator->isFeatured(),
            'displayName' => $owner?->isDeleted() ? ApiMessages::get('deleted_account', $locale) : $creator->getDisplayName(),
            'deleted' => $owner?->isDeleted() ?? false,
                'isPresentationDemo' => $owner?->isPresentationDemo() ?? false,
            'creatorTypes' => $creator->getCreatorTypes(),
            'presentation' => $creator->getPresentation(),
            'category' => $translation['category'] ?? $creator->getCategory(),
            'categoryLabel' => $categoryLabel ?? ($translation['category'] ?? $creator->getCategory()),
            'categories' => $categories,
            'categoryLabels' => array_map(
                static fn (string $category): string => $categoryLabels[$category] ?? $category,
                $categories,
            ),
            'city' => $creator->getCity(),
            'bio' => $translation['bio'] ?? $creator->getBio(),
            'tagline' => $translation['tagline'] ?? $creator->getTagline(),
            'avatarUrl' => $creator->getAvatarMedia()?->getUrl() ?? $creator->getAvatarUrl(),
            'avatarMediaId' => $creator->getAvatarMedia()?->getId(),
            'avatarImage' => MediaImageResource::fromEntity($creator->getAvatarMedia()),
            'socialProfiles' => array_map(
                static fn (array $profile): array => $profile + ['source' => ApiMessages::get('self_reported', $locale)],
                $creator->getSocialProfiles(),
            ),
            'portfolio' => $portfolio,
            'packages' => $packages,
            'faqs' => $creator->getFaqs(),
            'tags' => $translation['tags'] ?? $creator->getTags(),
        ];
        if ($creator->isAgencyProfile() && ($company = $owner?->getCompany()) !== null) {
            $resource = array_replace($resource, [
                'profileRoute' => 'company-profile', 'companyId' => $company->getId(), 'companyType' => 'agency',
                'slug' => $company->getSlug(), 'displayName' => $company->getName(),
                'category' => $company->getIndustry(), 'categoryLabel' => $categoryLabels[$company->getIndustry()] ?? $company->getIndustry(),
                'categories' => $company->getIndustries(), 'categoryLabels' => array_map(static fn (string $value): string => $categoryLabels[$value] ?? $value, $company->getIndustries()),
                'bio' => $company->getAbout(), 'avatarUrl' => $company->getLogoMedia()?->getUrl() ?? $company->getLogoUrl(),
                'avatarImage' => MediaImageResource::fromEntity($company->getLogoMedia()),
                'canReceiveCampaignInvitations' => $resource['canReceiveCampaignInvitations'] && $owner->isAgency(),
            ]);
        }
        return $resource;
    }
}
