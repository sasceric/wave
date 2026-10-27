<?php

namespace App\Search;

use App\Api\{CampaignResource, CompanyResource, CreatorResource, MarketplaceCategoryLabels};
use App\Entity\{Campaign, Company, Creator};
use App\Localization\LocalizedRouteMap;
use Doctrine\ORM\EntityManagerInterface;

/** Builds only public, localized result summaries; no email, private portfolio or credentials. */
final class SearchDocuments
{
    public const CLASSES = ['creator' => Creator::class, 'company' => Company::class, 'campaign' => Campaign::class];
    public const TYPES = ['creator' => 'creators', 'company' => 'companies', 'campaign' => 'campaigns'];
    public function __construct(private readonly EntityManagerInterface $em, private readonly LocalizedRouteMap $routes) {}

    public function build(string $kind, int $id, array $labels, ?string $onlyLocale = null): array
    {
        $entity = $this->em->find(self::CLASSES[$kind], $id);
        if (!$entity) return [];
        $owner = $entity instanceof Campaign ? $entity->getCompany()->getOwner() : $entity->getOwner();
        if ($owner && (!$owner->isApproved() || $owner->isHideMyAccount() || $owner->isDeleted())) return [];
        if ($entity instanceof Creator && $entity->isAgencyProfile()) return [];
        if ($entity instanceof Campaign && (!$entity->isListed() || $entity->getStatus() !== 'open' || $entity->getClosesAt() < new \DateTimeImmutable('today'))) return [];
        $documents = [];
        foreach ($onlyLocale === null ? $this->routes->locales() : [$onlyLocale] as $locale) {
            $categories = $labels[$locale];
            $row = match ($kind) {
                'creator' => CreatorResource::fromEntity($entity, $locale, categoryLabels: $categories, card: true),
                'company' => CompanyResource::fromEntity($entity, $locale, card: true, categoryLabels: $categories),
                'campaign' => CampaignResource::fromEntity($entity, $locale, card: true, categoryLabels: $categories),
                default => throw new \InvalidArgumentException('Unknown search catalog.'),
            };
            $translation = $entity->getTranslations()[$locale] ?? [];
            $text = match ($kind) {
                'creator' => ($translation['bio'] ?? $entity->getBio()).' '.implode(' ', $entity->getTags()),
                'company' => ($translation['about'] ?? $entity->getAbout()).' '.json_encode($entity->getServices(), JSON_UNESCAPED_UNICODE),
                default => ($translation['summary'] ?? $entity->getSummary()).' '.($translation['description'] ?? $entity->getDescription()),
            };
            $key = $kind.'_'.$id;
            $documents[] = [
                'id' => $key.'_'.$locale, 'entityKey' => $key, 'entityId' => $id,
                'locale' => $locale, 'kind' => self::TYPES[$kind], 'ownerId' => $owner?->getId() ?? 0,
                'expiresAt' => $entity instanceof Campaign ? $entity->getClosesAt()->setTime(23, 59, 59)->getTimestamp() : 253402300799,
                'slug' => $row['slug'], 'title' => $row['displayName'] ?? $row['name'] ?? $row['title'],
                'subtitle' => $kind === 'campaign' ? $row['company']['name'] : implode(', ', $row['categoryLabels'] ?? $row['industryLabels'] ?? []),
                'categories' => $row['categoryLabels'] ?? $row['industryLabels'] ?? [],
                'summary' => mb_substr(self::plain($row['summary'] ?? $text), 0, 240),
                'text' => self::plain($text), 'location' => $row['city'] ?? ($entity instanceof Creator ? $entity->getCity() : $owner?->getCity()) ?? '',
                'imageUrl' => $row['avatarUrl'] ?? $row['logoUrl'] ?? $row['coverImageUrl'] ?? null,
                'image' => $row['avatarImage'] ?? $row['logoImage'] ?? $row['coverImage'] ?? null,
                'isPresentationDemo' => $row['isPresentationDemo'],
                'compensationType' => $row['compensationType'] ?? null, 'budgetMin' => $row['budgetMin'] ?? null, 'budgetMax' => $row['budgetMax'] ?? null, 'currency' => $row['currency'] ?? null,
            ];
        }
        return $documents;
    }

    public function labels(?string $onlyLocale = null): array
    {
        $result = [];
        foreach ($onlyLocale === null ? $this->routes->locales() : [$onlyLocale] as $locale) $result[$locale] = MarketplaceCategoryLabels::forLocale($this->em, $locale);
        return $result;
    }
    public static function plain(?string $text): string { return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '</div>', '<br>'], ' ', $text ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''); }
}
