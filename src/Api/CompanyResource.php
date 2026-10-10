<?php

namespace App\Api;

use App\Entity\Company;
use App\Localization\ApiMessages;

final class CompanyResource
{
    public static function fromEntity(Company $company, string $locale, ?int $availableCampaignCount = null, bool $card = false, array $categoryLabels = []): array
    {
        $translation = $company->getTranslations()[$locale] ?? [];

        $resource = [
            'id' => $company->getId(),
            'slug' => $company->getSlug(),
            'name' => $company->getOwner()?->isDeleted() ? ApiMessages::get('deleted_account', $locale) : $company->getName(),
            'deleted' => $company->getOwner()?->isDeleted() ?? false,
            'industries' => $company->getIndustries(),
            'industryLabels' => array_map(
                static fn (string $value): string => $categoryLabels[$value] ?? ($value === $company->getIndustry() ? ($translation['industry'] ?? $value) : $value),
                $company->getIndustries(),
            ),
            'coverMediaId' => $company->getCoverMedia()?->getId(),
            'coverUrl' => $company->getCoverMedia()?->getUrl(),
            'coverImage' => MediaImageResource::fromEntity($company->getCoverMedia(), 480),
            'industry' => $categoryLabels[$company->getIndustry()] ?? $translation['industry'] ?? $company->getIndustry(),
            'about' => $company->getAbout(),
            'city' => $company->getOwner()?->getCity(),
            'countryCode' => $company->getOwner()?->getCountryCode(),
            'logoUrl' => $company->getLogoMedia()?->getUrl() ?? $company->getLogoUrl(),
            'logoMediaId' => $company->getLogoMedia()?->getId(),
            'logoImage' => MediaImageResource::fromEntity($company->getLogoMedia(), 96),
            'socialLinks' => array_values(array_map(
                static fn (array $link): array => [
                    'platform' => $link['platform'] ?? '',
                    'url' => $link['url'] ?? '',
                ],
                array_filter($company->getSocialLinks(), static fn (mixed $link): bool => is_array($link) && ($link['platform'] ?? '') !== '' && ($link['url'] ?? '') !== ''),
            )),
            'verified' => $company->isVerified(),
            'featured' => $company->isFeatured(),
        ];

        if ($availableCampaignCount !== null) {
            $resource['availableCampaignCount'] = $availableCampaignCount;
        }

        if ($card) {
            $text = preg_replace('~</(?:p|div|li|h[1-6])>|<br\\s*/?>~i', ' ', $company->getAbout() ?? '') ?? '';
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $resource['summary'] = mb_substr(trim(preg_replace('/\\s+/u', ' ', $text) ?? ''), 0, 240);
            unset($resource['about']);
        }

        return $resource;
    }
}
