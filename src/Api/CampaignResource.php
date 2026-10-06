<?php

namespace App\Api;

use App\Entity\Campaign;

final class CampaignResource
{
    public static function fromEntity(Campaign $campaign, string $locale, ?string $categoryLabel = null): array
    {
        $translation = $campaign->getTranslations()[$locale] ?? [];

        return [
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
            'location' => $translation['location'] ?? $campaign->getLocation(),
            'creatorCount' => $campaign->getCreatorCount(),
            'closesAt' => $campaign->getClosesAt()->format(DATE_ATOM),
            'publishedAt' => $campaign->getPublishedAt()->format(DATE_ATOM),
            'status' => $campaign->getStatus(),
            'featured' => $campaign->isFeatured(),
            'coverMediaId' => $campaign->getCoverMedia()?->getId(),
            'coverImageUrl' => $campaign->getCoverMedia()?->getUrl(),
            'coverImage' => MediaImageResource::fromEntity($campaign->getCoverMedia()),
            'company' => CompanyResource::fromEntity($campaign->getCompany(), $locale),
        ];
    }
}
