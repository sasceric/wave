<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use App\Localization\LocalizedRouteMap;
use App\Reviews\CollaborationFeedback;

/** One profile entity graph for the initial HTML and subsequent SPA navigation. */
final class ProfileStructuredData
{
    public function __construct(
        private readonly LocalizedRouteMap $routes,
        private readonly SiteOrigin $origin,
        private readonly CollaborationFeedback $feedback,
    ) {}

    public function creator(Creator $creator, string $locale, ?array $rating = null): array
    {
        $url = $this->origin->url($this->routes->localizedPath('creator-profile', $locale, ['slug' => $creator->getSlug()]));
        $person = ['@type' => 'Person', '@id' => $url.'#person', 'name' => $creator->getDisplayName(), 'url' => $url,
            'description' => $this->plain($creator->getTranslations()[$locale]['bio'] ?? $creator->getBio()),
            'knowsAbout' => $creator->getCategories()];
        if ($creator->getCity() !== '') $person['address'] = ['@type' => 'PostalAddress', 'addressLocality' => $creator->getCity()];
        $image = $creator->getAvatarMedia()?->getUrl() ?? $creator->getAvatarUrl();
        if ($image) $person['image'] = $this->absolute($image);
        $sameAs = array_values(array_unique(array_filter(array_column($creator->getSocialProfiles(), 'url'), static fn ($url): bool => is_string($url) && preg_match('~^https?://~i', $url) === 1)));
        if ($sameAs !== []) $person['sameAs'] = $sameAs;
        $languages = $creator->getPresentation()['languages'] ?? [];
        if ($languages !== []) $person['knowsLanguage'] = $languages;

        $service = ['@type' => 'Service', '@id' => $url.'#service', 'name' => $creator->getDisplayName(),
            'url' => $url, 'provider' => ['@id' => $url.'#person']];
        $service += $this->reviews($creator->getOwner(), $locale, $url, 'Organization', $rating);
        // Person does not support aggregateRating/review: these describe the creator's services.
        $person['makesOffer'] = ['@type' => 'Offer', 'itemOffered' => $service];
        $offers = [];
        foreach ($creator->getPackages() as $index => $package) {
            if (!is_string($package['title'] ?? null) || trim($package['title']) === '') continue;
            $offer = ['@type' => 'Offer', 'url' => $url, 'itemOffered' => ['@type' => 'Service', '@id' => $url.'#package-'.rawurlencode((string) ($package['id'] ?? $index)),
                'name' => $package['title'], 'description' => $this->plain($package['description'] ?? ''), 'provider' => ['@id' => $url.'#person']]];
            $price = $package['price'] ?? null;
            if (is_numeric($price) && (float) $price > 0 && in_array($package['priceMode'] ?? 'fixed', ['fixed', 'from'], true)) {
                $offer['priceCurrency'] = $package['currency'] ?? 'BAM';
                if (($package['priceMode'] ?? 'fixed') === 'from') {
                    $offer['priceSpecification'] = ['@type' => 'PriceSpecification', 'minPrice' => (float) $price, 'priceCurrency' => $offer['priceCurrency']];
                } else $offer['price'] = (float) $price;
            }
            $offers[] = $offer;
        }
        $faqs = array_values(array_filter($creator->getFaqs(), static fn (array $faq): bool => trim($faq['question'] ?? '') !== '' && trim($faq['answer'] ?? '') !== ''));
        if ($faqs !== []) $person['subjectOf'] = ['@type' => 'FAQPage', '@id' => $url.'#faq', 'mainEntity' => array_map(fn (array $faq): array => ['@type' => 'Question', 'name' => $this->plain($faq['question']), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $this->plain($faq['answer'])]], $faqs)];
        $mediaUrls = [];
        foreach ($creator->getPortfolioMedia() as $link) $mediaUrls[$link->getMedia()->getId()] = $link->getMedia()->getUrl();
        $images = [];
        foreach ($creator->getPortfolio() as $item) {
            if (!($item['publicPermission'] ?? true) || ($item['type'] ?? 'image') !== 'image') continue;
            $imageUrl = isset($item['mediaId']) ? ($mediaUrls[$item['mediaId']] ?? null) : ($item['url'] ?? null);
            if (!is_string($imageUrl) || $imageUrl === '') continue;
            // Permission to display work does not establish its author, copyright or reuse license.
            // Keep ordinary gallery images until verified per-image rights metadata is available.
            $images[] = $this->absolute($imageUrl);
        }
        if ($images !== []) {
            $gallery = ['@type' => 'ImageGallery', '@id' => $url.'#portfolio', 'name' => $creator->getDisplayName(),
                'isPartOf' => ['@id' => $url.'#webpage'], 'image' => $images];
            $person['subjectOf'] = isset($person['subjectOf']) ? [$person['subjectOf'], $gallery] : $gallery;
        }
        if ($offers !== []) $person['hasOfferCatalog'] = ['@type' => 'OfferCatalog', 'name' => $creator->getDisplayName(), 'itemListElement' => $offers];
        return $person;
    }

    public function company(Company $company, string $locale, ?array $rating = null): array
    {
        $url = $this->origin->url($this->routes->localizedPath('company-profile', $locale, ['slug' => $company->getSlug()]));
        $schema = ['@type' => 'Organization', '@id' => $url.'#organization', 'name' => $company->getName(), 'url' => $url,
            'description' => $this->plain($company->getAbout() ?? ''), 'knowsAbout' => $company->getTranslations()[$locale]['industry'] ?? $company->getIndustry()];
        $image = $company->getLogoMedia()?->getUrl() ?? $company->getLogoUrl();
        if ($image) $schema['logo'] = $this->absolute($image);
        return $schema + $this->reviews($company->getOwner(), $locale, $url, 'Person', $rating);
    }

    private function reviews(?User $owner, string $locale, string $url, string $authorType, ?array $rating): array
    {
        if ($owner === null || $owner->isPresentationDemo() || $owner->isDeleted()) return [];
        $result = $this->feedback->publicReviews($owner, $locale, summary: $rating, forSchema: true);
        $schema = [];
        if ($result['summary']['reviewCount'] > 0 && !$result['hasDemoReviews']) {
            $schema['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $result['summary']['average'],
                'reviewCount' => $result['summary']['reviewCount'], 'bestRating' => 5, 'worstRating' => 1];
        }
        $reviews = [];
        foreach ($result['data'] as $review) {
            if ($review['isPresentationDemo'] || $review['comment'] === '' || !$review['authorName']) continue;
            $reviews[] = ['@type' => 'Review', '@id' => $url.'#review-'.$review['id'], 'author' => ['@type' => $review['authorType'] ?? $authorType, 'name' => $review['authorName']],
                'reviewBody' => $review['comment'], 'datePublished' => $review['createdAt'],
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => $review['rating'], 'bestRating' => 5, 'worstRating' => 1]];
        }
        if ($reviews !== []) $schema['review'] = $reviews;
        return $schema;
    }

    private function absolute(string $url): string
    {
        return preg_match('~^https?://~i', $url) === 1 ? $url : $this->origin->url($url);
    }

    private function plain(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('~</(?:p|div|li|h[1-6])>~i', ' ', $value) ?? $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
}
