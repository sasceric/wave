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
    private array $catalogs = [];
    private array $routeTitles;
    private array $breadcrumbParents;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LocalizedRouteMap $routeMap,
        private readonly SiteOrigin $siteOrigin,
        private readonly RegionalSeoContent $regionalContent,
        private readonly ProfileStructuredData $profileSchema,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ) {
        $titles = file_get_contents($projectDir.'/config/seo_route_titles.json');
        if ($titles === false) {
            throw new RuntimeException('Unable to read route title configuration.');
        }
        $this->routeTitles = json_decode($titles, true, flags: JSON_THROW_ON_ERROR);
        $this->breadcrumbParents = json_decode((string) file_get_contents($projectDir.'/config/seo_breadcrumbs.json'), true, flags: JSON_THROW_ON_ERROR);
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
            $this->catalogs[$locale] = $catalog;
        }
    }

    public function forPath(string $path, array $query = [], bool $authenticated = false): array
    {
        $route = $this->routeMap->resolve($path);
        if ($route === null) {
            foreach ($this->routeMap->locales() as $locale) {
                if ($locale !== 'bs' && (str_starts_with($path, $this->routeMap->localizedPath('home', $locale)) || $path === '/'.$locale || str_starts_with($path, '/'.$locale.'/'))) {
                    return $this->notFoundMetadata($locale);
                }
            }
            return $this->notFoundMetadata();
        }

        $locale = $route['locale'];
        $name = $route['name'];
        $params = $route['params'];
        $countryCode = in_array($name, ['country-creators', 'country-topic'], true) ? $this->regionalContent->countryCode($params['country']) : null;
        $topic = $name === 'country-topic' && $countryCode !== null ? $this->regionalContent->topic($countryCode, $params['topic']) : null;
        $regionalCopy = in_array($name, ['country-creators', 'country-topic', 'seo-guides', 'seo-guide', 'creator-guides', 'creator-guide', 'how-it-works'], true) ? $this->regionalContent->catalog($locale) : [];
        if ((in_array($name, ['country-creators', 'country-topic'], true) && $countryCode === null)
            || ($name === 'country-topic' && $topic === null)
            || ($name === 'seo-guide' && !isset($regionalCopy['guides'][$params['guide']]))
            || ($name === 'creator-guide' && !isset($regionalCopy['creatorGuides']['guides'][$params['guide']]))) {
            return $this->notFoundMetadata($locale);
        }
        $private = $name === 'account'
            || str_starts_with($name, 'account-')
            || $name === 'admin'
            || str_starts_with($name, 'admin-')
            || in_array($name, ['maintenance', 'login', 'register', 'forgot-password', 'support-create', 'support-track', 'messages', 'verify-email', 'reset-password', 'moderation'], true);
        $titleKey = match ($name) {
            'support', 'support-create', 'support-track' => 'supportTitle',
            'home' => 'homeTitle',
            'maintenance' => 'maintenanceTitle',
            'creators', 'creator-profile' => 'creatorsTitle',
            'companies', 'company-profile' => 'companiesTitle',
            'campaigns', 'campaign-detail' => 'campaignsTitle',
            'imprint' => 'imprintTitle',
            'privacy-policy' => 'privacyTitle',
            'cookie-policy' => 'cookiesTitle',
            default => 'privateTitle',
        };
        $descriptionKey = match ($name) {
            'support', 'support-create', 'support-track' => 'supportDescription',
            'home' => 'homeDescription',
            'maintenance' => 'maintenanceDescription',
            'creators', 'creator-profile' => 'creatorsDescription',
            'companies', 'company-profile' => 'companiesDescription',
            'campaigns', 'campaign-detail' => 'campaignsDescription',
            'imprint' => 'imprintDescription',
            'privacy-policy' => 'privacyDescription',
            'cookie-policy' => 'cookiesDescription',
            'terms-of-use' => 'termsDescription',
            default => 'privateDescription',
        };
        $title = $this->translation($locale, $titleKey);
        $catalogKey = $this->routeTitles[$name] ?? null;
        if (!$authenticated && ($name === 'account' || str_starts_with($name, 'account-'))) {
            $catalogKey = match ($query['mode'] ?? null) {
                'register' => 'seo.registerTitle',
                'reset' => 'seo.forgotPasswordTitle',
                default => 'seo.loginTitle',
            };
        }
        if (is_string($catalogKey)) {
            $value = $this->catalogs[$locale];
            foreach (explode('.', $catalogKey) as $part) {
                $value = is_array($value) ? ($value[$part] ?? null) : null;
            }
            if (is_string($value)) {
                $title = $value;
            }
        }
        $description = $this->translation($locale, $descriptionKey);
        $image = $this->absoluteUrl('/images/share.webp');
        $entitySchema = null;
        $searchResults = $name === 'search' || (in_array($name, ['creators', 'companies', 'campaigns'], true) && trim(is_string($query['q'] ?? null) ? $query['q'] : '') !== '');
        $indexable = !$private && !$searchResults;
        if ($name === 'search') $description = $this->catalogs[$locale]['seo']['searchDescription'];
        $contentHtml = null;
        if (in_array($name, ['profile-guide-creator', 'profile-guide-agency', 'profile-guide-brand'], true)) {
            $kind = substr($name, strlen('profile-guide-'));
            $copy = $this->catalogs[$locale]['profileGuide'][$kind];
            $description = $copy['lead'];
            $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $contentHtml = '<main><h1>'.$escape($copy['title']).'</h1><p>'.$escape($copy['lead']).'</p><ul>';
            foreach ($copy['notes'] as $note) {
                $contentHtml .= '<li><strong>'.$escape($note['title']).'</strong> '.$escape($note['text']).'</li>';
            }
            $contentHtml .= '</ul></main>';
        }
        if ($name === 'maintenance') {
            $copy = $this->catalogs[$locale]['maintenance'];
            $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $contentHtml = '<main><h1>'.$escape($copy['title']).'</h1><p>'.$escape($copy['description']).'</p></main>';
        }

        if ($name === 'country-creators') {
            $copy = $regionalCopy['countries'][$countryCode];
            $title = $copy['title'].' | Wave';
            $description = $copy['description'];
            $creators = $this->regionalContent->creators($countryCode);
            $indexable = $creators !== [];
            $entitySchema = [
                '@type' => 'ItemList',
                'itemListElement' => array_map(fn (array $creator, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $creator['name'],
                    'url' => $this->siteOrigin->url($this->routeMap->localizedPath('creator-profile', $locale, ['slug' => $creator['slug']])),
                ], $creators, array_keys($creators)),
            ];
            $contentHtml = $this->regionalContent->html($name, $locale, $params, $this->routeMap, $creators);
        } elseif ($name === 'country-topic' && $topic !== null) {
            $copy = $this->regionalContent->topicCopy($locale, $countryCode, $topic);
            $title = $copy['title'].' | Wave';
            $description = $copy['description'];
            $listing = $this->regionalContent->topicCreators($countryCode, $topic);
            $indexable = $indexable && $this->regionalContent->topicIndexable($countryCode, $topic);
            $entitySchema = [
                '@type' => 'ItemList',
                'itemListElement' => array_map(fn (array $creator, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $creator['name'],
                    'url' => $this->siteOrigin->url($this->routeMap->localizedPath('creator-profile', $locale, ['slug' => $creator['slug']])),
                ], $listing['creators'], array_keys($listing['creators'])),
            ];
            $contentHtml = $this->regionalContent->html($name, $locale, $params, $this->routeMap, $listing['creators']);
        } elseif ($name === 'how-it-works') {
            $title = $regionalCopy['howItWorks']['title'].' | Wave';
            $description = $regionalCopy['howItWorks']['intro'];
            $contentHtml = $this->regionalContent->html($name, $locale, $params, $this->routeMap);
        } elseif (in_array($name, ['seo-guides', 'seo-guide', 'creator-guides', 'creator-guide'], true)) {
            $forCreators = str_starts_with($name, 'creator-');
            $guideCopy = $forCreators ? $regionalCopy['creatorGuides'] : ['title' => $regionalCopy['guideTitle'], 'intro' => $regionalCopy['guideIntro'], 'guides' => $regionalCopy['guides']];
            $copy = isset($params['guide']) ? $guideCopy['guides'][$params['guide']] : null;
            $title = ($copy['title'] ?? $guideCopy['title']).' | Wave';
            $description = $copy['intro'] ?? $guideCopy['intro'];
            if ($copy !== null) {
                $entitySchema = ['@type' => 'Article', 'headline' => $copy['title'], 'description' => $copy['intro'], 'author' => ['@type' => 'Organization', 'name' => 'Wave'], 'publisher' => ['@id' => $this->siteOrigin->url('/').'#organization']];
            } else {
                $entitySchema = ['@type' => 'ItemList', 'itemListElement' => []];
                foreach ($guideCopy['guides'] as $slug => $guide) $entitySchema['itemListElement'][] = ['@type' => 'ListItem', 'position' => count($entitySchema['itemListElement']) + 1, 'name' => $guide['title'], 'url' => $this->siteOrigin->url($this->routeMap->localizedPath($forCreators ? 'creator-guide' : 'seo-guide', $locale, ['guide' => $slug]))];
            }
            $contentHtml = $this->regionalContent->html($name, $locale, $params, $this->routeMap);
        }

        if ($name === 'creator-profile') {
            $creator = $this->entityManager->getRepository(Creator::class)->createQueryBuilder('creator')
                ->leftJoin('creator.owner', 'owner')->andWhere('creator.agencyProfile = false')
                ->andWhere('creator.slug = :slug')
                ->andWhere('owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible)')
                ->setParameter('slug', $params['slug'])
                ->setParameter('approved', true)
                ->setParameter('visible', false)
                ->getQuery()
                ->getOneOrNullResult();
            if (!$creator instanceof Creator) {
                return $this->notFoundMetadata($locale);
            } else {
                $translation = $creator->getTranslations()[$locale] ?? [];
                $details = $this->creatorDetails($locale, $creator->getCreatorTypes(), $creator->getCity());
                $title = $details === ''
                    ? $this->formatTranslation($locale, 'creatorProfileTitle', '{name} — Wave', ['{name}' => $creator->getDisplayName()])
                    : $this->formatTranslation($locale, 'creatorProfileTitleDetails', '{name} — {details} | Wave', ['{name}' => $creator->getDisplayName(), '{details}' => $details]);
                $fallback = $details === '' ? $description : $this->formatTranslation($locale, 'creatorProfileDescription', '{name} · {details}.', ['{name}' => $creator->getDisplayName(), '{details}' => $details]);
                $description = $this->plainDescription($translation['bio'] ?? $creator->getBio(), $fallback);
                $image = $this->absoluteUrl($creator->getAvatarMedia()?->getUrl() ?? $creator->getAvatarUrl())
                    ?? $image;
                $entitySchema = $this->profileSchema->creator($creator, $locale);
            }
        } elseif ($name === 'company-profile') {
            $company = $this->entityManager->getRepository(Company::class)->createQueryBuilder('company')
                ->andWhere('company.archivedProfile = false')
                ->leftJoin('company.owner', 'owner')
                ->andWhere('company.slug = :slug')
                ->andWhere('owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible)')
                ->setParameter('slug', $params['slug'])
                ->setParameter('approved', true)
                ->setParameter('visible', false)
                ->getQuery()
                ->getOneOrNullResult();
            if (!$company instanceof Company) {
                return $this->notFoundMetadata($locale);
            } else {
                $translation = $company->getTranslations()[$locale] ?? [];
                // Same label as the company API (and so the browser title): category label, translation, raw value.
                $industry = trim((string) (\App\Api\MarketplaceCategoryLabels::forLocale($this->entityManager, $locale)[$company->getIndustry()] ?? $translation['industry'] ?? $company->getIndustry()));
                $title = $industry === ''
                    ? $this->formatTranslation($locale, 'companyProfileTitle', '{name} — Wave', ['{name}' => $company->getName()])
                    : $this->formatTranslation($locale, 'companyProfileTitleDetails', '{name} — {details} | Wave', ['{name}' => $company->getName(), '{details}' => $industry]);
                $description = $this->plainDescription($company->getAbout() ?? '', $translation['industry'] ?? $company->getIndustry());
                $image = $this->absoluteUrl($company->getLogoMedia()?->getUrl() ?? $company->getLogoUrl())
                    ?? $image;
                $entitySchema = $this->profileSchema->company($company, $locale);
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
                return $this->notFoundMetadata($locale);
            } else {
                $indexable = $indexable && $campaign->isListed();
                $translation = $campaign->getTranslations()[$locale] ?? [];
                $title = $this->formatTranslation(
                    $locale,
                    'campaignProfileTitle',
                    '{name} — Wave',
                    ['{name}' => $translation['title'] ?? $campaign->getTitle()],
                );
                $description = $translation['summary'] ?? $campaign->getSummary();
                $image = $this->absoluteUrl($campaign->getCoverMedia()?->getUrl()) ?? $image;
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
            'title' => self::pageTitle($title),
            'description' => $description,
            'locale' => $locale,
            'canonical' => $canonicalUrl,
            'alternates' => $alternates,
            'image' => $image,
            // The homepage hero is text and search only; it has no image to preload.
            'preloadImage' => null,
            'homepageStyles' => $name === 'home',
            'noindex' => !$indexable,
            'robots' => $searchResults ? 'noindex, follow' : ($indexable ? 'index, follow' : 'noindex, nofollow'),
            'contentHtml' => $contentHtml,
            'structuredData' => $indexable ? $this->structuredData(self::pageTitle($title), $description, $canonicalUrl, $locale, $name, $entitySchema) : [],
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
            'cnr' => 'sr-Latn-ME',
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
                    'urlTemplate' => $this->siteOrigin->url($this->routeMap->localizedPath('search', $locale)).'?q={search_term_string}',
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
                'availableLanguage' => ['bs', 'hr', 'sr-Latn', 'sr-Latn-ME', 'sl', 'en', 'de'],
            ],
        ];
        $page = [
            '@context' => 'https://schema.org',
            '@type' => match ($routeName) {
                'creators', 'companies', 'campaigns', 'country-creators', 'country-topic', 'seo-guides', 'creator-guides' => 'CollectionPage',
                'creator-profile', 'company-profile' => 'ProfilePage',
                'support' => 'ContactPage',
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
        if ($routeName === 'home') {
            $faq = $this->catalogs[$locale]['siteFaq'];
            $page['hasPart'] = ['@type' => 'FAQPage', '@id' => $canonicalUrl.'#faq', 'mainEntity' => array_map(static fn (int $index): array => ['@type' => 'Question', 'name' => $faq['question'.$index], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer'.$index]]], range(1, 5))];
        }

        $nodes = [];
        if ($routeName !== 'home') {
            $crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => $this->catalogs[$locale]['app']['home'], 'item' => $this->siteOrigin->url($this->routeMap->localizedPath('home', $locale))]];
            $parent = $this->breadcrumbParents[$routeName] ?? null;
            if ($parent !== null) {
                $label = $this->catalogs[$locale];
                foreach (explode('.', $parent['label']) as $key) $label = $label[$key];
                $crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1, 'name' => $label, 'item' => $this->siteOrigin->url($this->routeMap->localizedPath($parent['route'], $locale))];
            }
            $crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1, 'name' => preg_replace('/\s*(?:[|—]\s*Wave)\s*$/u', '', $title), 'item' => $canonicalUrl];
            $breadcrumb = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', '@id' => $canonicalUrl.'#breadcrumbs', 'itemListElement' => $crumbs];
            $page['breadcrumb'] = ['@id' => $breadcrumb['@id']];
            $nodes[] = $breadcrumb;
        }
        return [$website, $organization, $page, ...$nodes];
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

    private function notFoundMetadata(string $locale = 'bs'): array
    {
        return [
            'title' => self::pageTitle($this->translation($locale, 'notFoundTitle', 'Page not found — Wave')),
            'description' => $this->translation($locale, 'notFoundDescription', 'The requested page could not be found.'),
            'locale' => $locale,
            'canonical' => null,
            'alternates' => [],
            'image' => null,
            'preloadImage' => null,
            'homepageStyles' => false,
            'noindex' => true,
            'structuredData' => [],
        ];
    }

    private function translation(string $locale, string $key, string $fallback = ''): string
    {
        $value = $this->translations[$locale][$key] ?? $fallback;

        return is_string($value) ? $value : $fallback;
    }

    private static function pageTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '' || $title === 'Wave') {
            return 'Wave';
        }
        if (preg_match('/^Wave\s*[|—–-]\s*/iu', $title)) {
            return preg_replace('/^Wave\s*[|—–-]\s*/iu', 'Wave | ', $title) ?? $title;
        }

        return trim(preg_replace('/\s*[|—–-]\s*Wave$/iu', '', $title) ?? $title).' | Wave';
    }

    /** "UGC kreator, Sarajevo": the first creator type and the city, as shown in profile titles. */
    private function creatorDetails(string $locale, array $creatorTypes, string $city): string
    {
        $labels = $this->catalogs[$locale]['creatorTypes']['options'] ?? [];
        $type = isset($creatorTypes[0]) && is_string($labels[$creatorTypes[0]] ?? null) ? $labels[$creatorTypes[0]] : '';

        return implode(', ', array_filter([$type, trim(explode(',', $city)[0])], static fn (string $part): bool => $part !== ''));
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
