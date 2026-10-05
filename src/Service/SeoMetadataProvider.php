<?php

namespace App\Service;

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Localization\LocalizedRouteMap;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SeoMetadataProvider
{
    private array $translations = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LocalizedRouteMap $routeMap,
        private readonly SiteOrigin $siteOrigin,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ) {
        foreach ($this->routeMap->locales() as $locale) {
            $contents = file_get_contents($projectDir.'/frontend/src/locales/'.$locale.'.json');
            if ($contents === false) {
                throw new RuntimeException(sprintf('Unable to read SEO translations for locale "%s".', $locale));
            }
            $catalog = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            if (!isset($catalog['seo']) || !is_array($catalog['seo'])) {
                throw new RuntimeException(sprintf('SEO translations are missing for locale "%s".', $locale));
            }
            $this->translations[$locale] = $catalog['seo'];
        }
    }

    public function forPath(string $path): array
    {
        $route = $this->routeMap->resolve($path);
        if ($route === null) {
            return $this->notFoundMetadata();
        }

        $locale = $route['locale'];
        $name = $route['name'];
        $params = $route['params'];
        $private = $name === 'account'
            || str_starts_with($name, 'account-')
            || $name === 'admin'
            || str_starts_with($name, 'admin-')
            || in_array($name, ['messages', 'verify-email', 'reset-password', 'moderation'], true);
        $titleKey = match ($name) {
            'home' => 'homeTitle',
            'creators', 'creator-profile' => 'creatorsTitle',
            'companies', 'company-profile' => 'companiesTitle',
            'campaigns', 'campaign-detail' => 'campaignsTitle',
            'imprint' => 'imprintTitle',
            'privacy-policy' => 'privacyTitle',
            'cookie-policy' => 'cookiesTitle',
            default => 'privateTitle',
        };
        $descriptionKey = match ($name) {
            'home' => 'homeDescription',
            'creators', 'creator-profile' => 'creatorsDescription',
            'companies', 'company-profile' => 'companiesDescription',
            'campaigns', 'campaign-detail' => 'campaignsDescription',
            'imprint' => 'imprintDescription',
            'privacy-policy' => 'privacyDescription',
            'cookie-policy' => 'cookiesDescription',
            default => 'privateDescription',
        };
        $title = $this->translation($locale, $titleKey);
        $description = $this->translation($locale, $descriptionKey);
        $image = $name === 'home' ? $this->absoluteUrl('/images/share.webp') : null;
        $entitySchema = null;
        $indexable = !$private;

        if ($name === 'creator-profile') {
            $creator = $this->entityManager->getRepository(Creator::class)->createQueryBuilder('creator')
                ->leftJoin('creator.owner', 'owner')
                ->andWhere('creator.slug = :slug')
                ->andWhere('owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible)')
                ->setParameter('slug', $params['slug'])
                ->setParameter('approved', true)
                ->setParameter('visible', false)
                ->getQuery()
                ->getOneOrNullResult();
            if (!$creator instanceof Creator) {
                $indexable = false;
            } else {
                $translation = $creator->getTranslations()[$locale] ?? [];
                $title = $this->formatTranslation(
                    $locale,
                    'creatorProfileTitle',
                    '{name} — Wave',
                    ['{name}' => $creator->getDisplayName()],
                );
                $description = $this->plainDescription($translation['bio'] ?? $creator->getBio(), $description);
                $image = $this->absoluteUrl($creator->getAvatarMedia()?->getUrl() ?? $creator->getAvatarUrl())
                    ?? $this->absoluteUrl('/images/logo-icon.svg');
                $entitySchema = $this->creatorSchema($creator, $locale, $params, $image);
            }
        } elseif ($name === 'company-profile') {
            $company = $this->entityManager->getRepository(Company::class)->createQueryBuilder('company')
                ->leftJoin('company.owner', 'owner')
                ->andWhere('company.slug = :slug')
                ->andWhere('owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible)')
                ->setParameter('slug', $params['slug'])
                ->setParameter('approved', true)
                ->setParameter('visible', false)
                ->getQuery()
                ->getOneOrNullResult();
            if (!$company instanceof Company) {
                $indexable = false;
            } else {
                $translation = $company->getTranslations()[$locale] ?? [];
                $title = $this->formatTranslation(
                    $locale,
                    'companyProfileTitle',
                    '{name} — Wave',
                    ['{name}' => $company->getName()],
                );
                $description = $translation['industry'] ?? $company->getIndustry();
                $image = $this->absoluteUrl($company->getLogoMedia()?->getUrl() ?? $company->getLogoUrl())
                    ?? $this->absoluteUrl('/images/logo-icon.svg');
                $entitySchema = $this->companySchema($company, $locale, $params, $image);
            }
        } elseif ($name === 'campaign-detail') {
            $campaign = $this->entityManager->getRepository(Campaign::class)->createQueryBuilder('campaign')
                ->join('campaign.company', 'company')
                ->leftJoin('company.owner', 'companyOwner')
                ->andWhere('campaign.slug = :slug')
                ->andWhere('campaign.status = :status')
                ->andWhere('companyOwner.id IS NULL OR (companyOwner.approved = :approved AND companyOwner.hideMyAccount = :visible)')
                ->setParameter('slug', $params['slug'])
                ->setParameter('status', 'open')
                ->setParameter('approved', true)
                ->setParameter('visible', false)
                ->getQuery()
                ->getOneOrNullResult();
            if (!$campaign instanceof Campaign || $campaign->getClosesAt() < new DateTimeImmutable('today')) {
                $indexable = false;
            } else {
                $translation = $campaign->getTranslations()[$locale] ?? [];
                $title = $this->formatTranslation(
                    $locale,
                    'campaignProfileTitle',
                    '{name} — Wave',
                    ['{name}' => $translation['title'] ?? $campaign->getTitle()],
                );
                $description = $translation['summary'] ?? $campaign->getSummary();
                $entitySchema = $this->campaignSchema($campaign, $locale, $params);
            }
        }

        $canonicalPath = $this->routeMap->localizedPath($name, $locale, $params);
        $canonicalUrl = $this->siteOrigin->url($canonicalPath);
        $alternates = [];
        foreach ($this->routeMap->localizedAlternates($name, $params) as $hreflang => $alternatePath) {
            $alternates[$hreflang] = $this->siteOrigin->url($alternatePath);
        }
        if (!$indexable) {
            $alternates = [];
        }

        return [
            'title' => $title,
            'description' => $description,
            'locale' => $locale,
            'canonical' => $canonicalUrl,
            'alternates' => $alternates,
            'image' => $image,
            'preloadImage' => $name === 'home' ? '/images/banner-girl.webp' : null,
            'noindex' => !$indexable,
            'structuredData' => $indexable ? $this->structuredData($title, $description, $canonicalUrl, $locale, $name, $entitySchema) : [],
        ];
    }

    public function absoluteUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return $url;
        }

        return $this->siteOrigin->url($url);
    }

    private function structuredData(
        string $title,
        string $description,
        string $canonicalUrl,
        string $locale,
        string $routeName,
        ?array $entitySchema,
    ): array {
        $language = match ($locale) {
            'sr' => 'sr-Latn',
            'cnr' => 'cnr-Latn-ME',
            default => $locale,
        };
        $websiteUrl = $this->siteOrigin->url('/');
        $website = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => $websiteUrl.'#website',
            'url' => $websiteUrl,
            'name' => 'Wave',
            'inLanguage' => $language,
            'publisher' => ['@id' => $websiteUrl.'#organization'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $this->siteOrigin->url($this->routeMap->localizedPath('creators', $locale)).'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
        $organization = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => $websiteUrl.'#organization',
            'name' => 'UD SteelCode',
            'legalName' => 'UD SteelCode',
            'alternateName' => 'SteelCode',
            'url' => $websiteUrl,
            'logo' => $this->siteOrigin->url('/pwa-512.png'),
            'email' => 'info@wave.ba',
            'taxID' => '4320531730002',
            'vatID' => '320531730002',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => 'Školska 10',
                'addressLocality' => 'Zenica',
                'postalCode' => '72000',
                'addressCountry' => 'BA',
            ],
            'brand' => [
                '@type' => 'Brand',
                'name' => 'Wave',
                'url' => $websiteUrl,
            ],
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'email' => 'info@wave.ba',
                'availableLanguage' => ['bs', 'hr', 'sr-Latn', 'cnr-Latn-ME', 'sl', 'en'],
            ],
        ];
        $page = [
            '@context' => 'https://schema.org',
            '@type' => match ($routeName) {
                'creators', 'companies', 'campaigns' => 'CollectionPage',
                'creator-profile', 'company-profile' => 'ProfilePage',
                default => 'WebPage',
            },
            '@id' => $canonicalUrl.'#webpage',
            'url' => $canonicalUrl,
            'name' => $title,
            'description' => $description,
            'inLanguage' => $language,
            'isPartOf' => ['@id' => $websiteUrl.'#website'],
        ];
        if ($entitySchema !== null) {
            $page['mainEntity'] = $entitySchema;
        }

        return [$website, $organization, $page];
    }

    private function creatorSchema(Creator $creator, string $locale, array $params, ?string $image): array
    {
        $schema = [
            '@type' => 'Person',
            '@id' => $this->siteOrigin->url($this->routeMap->localizedPath('creator-profile', $locale, $params)).'#person',
            'name' => $creator->getDisplayName(),
            'url' => $this->siteOrigin->url($this->routeMap->localizedPath('creator-profile', $locale, $params)),
            'description' => $creator->getTranslations()[$locale]['bio'] ?? $creator->getBio(),
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => $creator->getLocation()],
            'knowsAbout' => $creator->getCategories(),
        ];
        if ($image !== null) {
            $schema['image'] = $image;
        }
        $sameAs = [];
        foreach ($creator->getSocialProfiles() as $profile) {
            $url = $profile['url'] ?? null;
            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $sameAs[] = $url;
            }
        }
        if ($sameAs !== []) {
            $schema['sameAs'] = array_values(array_unique($sameAs));
        }

        return $schema;
    }

    private function companySchema(Company $company, string $locale, array $params, ?string $image): array
    {
        $schema = [
            '@type' => 'Organization',
            '@id' => $this->siteOrigin->url($this->routeMap->localizedPath('company-profile', $locale, $params)).'#organization',
            'name' => $company->getName(),
            'url' => $this->siteOrigin->url($this->routeMap->localizedPath('company-profile', $locale, $params)),
            'industry' => $company->getTranslations()[$locale]['industry'] ?? $company->getIndustry(),
        ];
        if ($image !== null) {
            $schema['logo'] = $image;
        }

        return $schema;
    }

    private function campaignSchema(Campaign $campaign, string $locale, array $params): array
    {
        $company = $campaign->getCompany();

        return [
            '@type' => 'CreativeWork',
            '@id' => $this->siteOrigin->url($this->routeMap->localizedPath('campaign-detail', $locale, $params)).'#campaign',
            'name' => $campaign->getTranslations()[$locale]['title'] ?? $campaign->getTitle(),
            'description' => $campaign->getTranslations()[$locale]['summary'] ?? $campaign->getSummary(),
            'datePublished' => $campaign->getPublishedAt()->format(DATE_ATOM),
            'expires' => $campaign->getClosesAt()->format(DATE_ATOM),
            'provider' => [
                '@type' => 'Organization',
                'name' => $company->getName(),
                'url' => $this->siteOrigin->url($this->routeMap->localizedPath(
                    'company-profile',
                    $locale,
                    ['slug' => $company->getSlug()],
                )),
            ],
        ];
    }

    private function notFoundMetadata(): array
    {
        return [
            'title' => $this->translation('bs', 'notFoundTitle', 'Page not found — Wave'),
            'description' => $this->translation('bs', 'notFoundDescription', 'The requested page could not be found.'),
            'locale' => 'bs',
            'canonical' => null,
            'alternates' => [],
            'image' => null,
            'preloadImage' => null,
            'noindex' => true,
            'structuredData' => [],
        ];
    }

    private function translation(string $locale, string $key, string $fallback = ''): string
    {
        $value = $this->translations[$locale][$key] ?? $fallback;

        return is_string($value) ? $value : $fallback;
    }

    private function formatTranslation(string $locale, string $key, string $fallback, array $replacements): string
    {
        return strtr($this->translation($locale, $key, $fallback), $replacements);
    }

    private function plainDescription(string $description, string $fallback): string
    {
        $description = trim(preg_replace('/\s+/u', ' ', strip_tags($description)) ?? '');
        if ($description === '') {
            return $fallback;
        }
        if (mb_strlen($description) > 320) {
            return mb_substr($description, 0, 317).'...';
        }

        return $description;
    }
}
