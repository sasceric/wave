<?php

namespace App\Api;

use App\Entity\Company;

final class CompanyResource
{
    public static function fromEntity(Company $company, string $locale, ?int $availableCampaignCount = null): array
    {
        $translation = $company->getTranslations()[$locale] ?? [];

        $resource = [
            'id' => $company->getId(),
            'slug' => $company->getSlug(),
            'name' => $company->getName(),
            'industry' => $translation['industry'] ?? $company->getIndustry(),
            'logoUrl' => $company->getLogoMedia()?->getUrl() ?? $company->getLogoUrl(),
            'logoMediaId' => $company->getLogoMedia()?->getId(),
            'verified' => $company->isVerified(),
            'featured' => $company->isFeatured(),
        ];

        if ($availableCampaignCount !== null) {
            $resource['availableCampaignCount'] = $availableCampaignCount;
        }

        return $resource;
    }
}
