<?php

namespace App\Api;

use App\Entity\Campaign;

final class CampaignResource
{
    public static function fromEntity(Campaign $campaign, string $locale, ?string $categoryLabel = null, bool $card = false, array $categoryLabels = [], int $hiredCount = 0): array
    {
        $translation = $campaign->getTranslations()[$locale] ?? [];
        $categoryLabel ??= $categoryLabels[$campaign->getCategory()] ?? null;

        $resource = [
            'id' => $campaign->getId(),
            'slug' => $campaign->getSlug(),
            'title' => $translation['title'] ?? $campaign->getTitle(),
            'summary' => $translation['summary'] ?? $campaign->getSummary(),
            'description' => $translation['description'] ?? $campaign->getDescription(),
            'category' => $translation['category'] ?? $campaign->getCategory(),
            'categoryLabel' => $categoryLabel ?? ($translation['category'] ?? $campaign->getCategory()),
            'channels' => $campaign->getChannels(),
            'deliverables' => $translation['deliverables'] ?? $campaign->getDeliverables(),
            'budgetMin' => $campaign->getBudgetMin(),
            'budgetMax' => $campaign->getBudgetMax(),
            'currency' => $campaign->getCurrency(),
            'city' => $campaign->getCity(),
            'countryCode' => $campaign->getCountryCode(),
            'categories' => $campaign->getCategories(),
            'categoryLabels' => array_map(static fn (string $category): string => $categoryLabels[$category] ?? $category, $campaign->getCategories()),
            'creatorCount' => $campaign->getCreatorCount(),
            'hiredCount' => $hiredCount,
            'closesAt' => $campaign->getClosesAt()->format(DATE_ATOM),
            'publishedAt' => $campaign->getPublishedAt()->format(DATE_ATOM),
            'status' => $campaign->getStatus(),
            'featured' => $campaign->isFeatured(),
            'coverMediaId' => $campaign->getCoverMedia()?->getId(),
            'coverImageUrl' => $campaign->getCoverMedia()?->getUrl(),
            'coverImage' => MediaImageResource::fromEntity($campaign->getCoverMedia()),
            'company' => CompanyResource::fromEntity($campaign->getCompany(), $locale, card: $card, categoryLabels: $categoryLabels),
        ];
        if ($card) {
            unset($resource['description'], $resource['deliverables']);
        }

        return $resource;
    }
}
