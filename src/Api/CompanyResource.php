<?php

namespace App\Api;

use App\Entity\Company;

final class CompanyResource
{
    public static function fromEntity(Company $company, string $locale, ?int $availableCampaignCount = null, bool $card = false): array
    {
        $translation = $company->getTranslations()[$locale] ?? [];

        $resource = [
            'id' => $company->getId(),
            'slug' => $company->getSlug(),
            'name' => $company->getName(),
            'industry' => $translation['industry'] ?? $company->getIndustry(),
            'about' => $company->getAbout(),
            'city' => $company->getOwner()?->getCity(),
            'countryCode' => $company->getOwner()?->getCountryCode(),
            'logoUrl' => $company->getLogoMedia()?->getUrl() ?? $company->getLogoUrl(),
            'logoMediaId' => $company->getLogoMedia()?->getId(),
            'logoImage' => MediaImageResource::fromEntity($company->getLogoMedia(), 96),
            'verified' => $company->isVerified(),
            'featured' => $company->isFeatured(),
        ];

        if ($availableCampaignCount !== null) {
            $resource['availableCampaignCount'] = $availableCampaignCount;
        }

        if ($card) {
            unset($resource['about'], $resource['city'], $resource['countryCode']);
        }

        return $resource;
    }
}
