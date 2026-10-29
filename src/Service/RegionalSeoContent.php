<?php

namespace App\Service;

use App\Localization\LocalizedRouteMap;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class RegionalSeoContent
{
    private array $countries;
    private array $catalogs = [];
    /** @var array{minCreators: int, categories: array<string, string>, creatorTypes: array<string, string>, cities: array<string, array<string, array{name: string, aliases: list<string>, in: array<string, string>}>>} */
    private array $topics;
    /** @var array<string, list<array<string, mixed>>> */
    private array $countryCreators = [];

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        $this->countries = json_decode((string) file_get_contents($projectDir.'/config/seo_countries.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->topics = json_decode((string) file_get_contents($projectDir.'/config/seo_topics.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * A landing page below a country: an area (ljepota), a creator type (ugc-kreatori) or a city (sarajevo).
     *
     * @return array{slug: string, kind: 'category'|'type'|'city', key: string, city?: array{name: string, aliases: list<string>, in: array<string, string>}}|null
     */
    public function topic(string $countryCode, string $slug): ?array
    {
        if (isset($this->topics['categories'][$slug])) return ['slug' => $slug, 'kind' => 'category', 'key' => $this->topics['categories'][$slug]];
        if (isset($this->topics['creatorTypes'][$slug])) return ['slug' => $slug, 'kind' => 'type', 'key' => $this->topics['creatorTypes'][$slug]];
        if (isset($this->topics['cities'][$countryCode][$slug])) return ['slug' => $slug, 'kind' => 'city', 'key' => $slug, 'city' => $this->topics['cities'][$countryCode][$slug]];

        return null;
    }

    /**
     * Landing pages with enough public creators to be worth indexing, most populated first.
     *
     * @return list<array{slug: string, kind: string, key: string, count: int, name?: string}>
     */
    public function availableTopics(string $countryCode): array
    {
        $creators = $this->creatorsInCountry($countryCode);
        $groups = ['category' => [], 'type' => [], 'city' => []];
        $candidates = array_merge(
            array_keys($this->topics['categories']),
            array_keys($this->topics['creatorTypes']),
            array_keys($this->topics['cities'][$countryCode] ?? []),
        );
        foreach ($candidates as $slug) {
            $topic = $this->topic($countryCode, $slug);
            if ($topic === null) continue;
            $count = count(array_filter($creators, fn (array $creator): bool => $this->matches($creator, $topic)));
            if ($count < $this->topics['minCreators']) continue;
            $row = ['slug' => $slug, 'kind' => $topic['kind'], 'key' => $topic['key'], 'count' => $count];
            if (isset($topic['city'])) $row['name'] = $topic['city']['name'];
            $groups[$topic['kind']][] = $row;
        }
        foreach ($groups as &$rows) {
            usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['slug'], $b['slug']));
        }

        return [...$groups['category'], ...$groups['type'], ...$groups['city']];
    }

    /** @return array{creators: list<array<string, mixed>>, total: int} */
    public function topicCreators(string $countryCode, array $topic): array
    {
        $matching = array_values(array_filter($this->creatorsInCountry($countryCode), fn (array $creator): bool => $this->matches($creator, $topic)));

        return ['creators' => array_slice($matching, 0, 24), 'total' => count($matching)];
    }

    public function topicIndexable(string $countryCode, array $topic): bool
    {
        return $this->topicCreators($countryCode, $topic)['total'] >= $this->topics['minCreators'];
    }

    /**
     * Title, description and intro of a landing page, with the country or city phrase filled in.
     *
     * @return array{title: string, description: string, intro: string, in: string, countryIn: string}
     */
    public function topicCopy(string $locale, string $countryCode, array $topic): array
    {
        $copy = $this->catalog($locale);
        $countryIn = $copy['countries'][$countryCode]['in'];
        $in = $countryIn;
        if ($topic['kind'] === 'city') {
            $group = in_array($locale, ['bs', 'hr', 'sr', 'cnr'], true) ? 'bhs' : $locale;
            $in = $topic['city']['in'][$group] ?? ($locale === 'de' || $locale === 'en' ? 'in '.$topic['city']['name'] : $topic['city']['name']);
            $text = $copy['topics']['city'];
        } elseif ($topic['kind'] === 'type') {
            $text = $copy['topics']['ugc'];
        } else {
            $text = $copy['topics']['categories'][$topic['key']];
        }
        $fill = static fn (string $value): string => str_replace('{in}', $in, $value);

        return ['title' => $fill($text['title']), 'description' => $fill($text['description']), 'intro' => $fill($text['intro']), 'in' => $in, 'countryIn' => $countryIn];
    }

    /** @return list<array<string, mixed>> */
    private function creatorsInCountry(string $countryCode): array
    {
        return $this->countryCreators[$countryCode] ??= $this->connection->fetchAllAssociative(
            'SELECT c.display_name AS name, c.slug, COALESCE(u.city, c.city) AS city, c.category, CAST(c.categories AS TEXT) AS categories, CAST(c.creator_types AS TEXT) AS creator_types FROM creator c JOIN wave_user u ON u.id = c.owner_id WHERE c.agency_profile = FALSE AND u.approved = TRUE AND u.hide_my_account = FALSE AND UPPER(u.country_code) = :code ORDER BY c.created_at DESC, c.id DESC',
            ['code' => $countryCode],
        );
    }

    /** @param array<string, mixed> $creator */
    private function matches(array $creator, array $topic): bool
    {
        if ($topic['kind'] === 'category') {
            $values = json_decode((string) ($creator['categories'] ?? '[]'), true);
            $values = is_array($values) && $values !== [] ? $values : [(string) ($creator['category'] ?? '')];

            return in_array($topic['key'], array_map(static fn ($value): string => mb_strtolower((string) $value), $values), true);
        }
        if ($topic['kind'] === 'type') {
            $values = json_decode((string) ($creator['creator_types'] ?? '[]'), true);

            return is_array($values) && in_array($topic['key'], $values, true);
        }
        $city = self::normalizedCity((string) ($creator['city'] ?? ''));
        foreach ($topic['city']['aliases'] as $alias) {
            if ($city === self::normalizedCity($alias)) return true;
        }

        return false;
    }

    private static function normalizedCity(string $city): string
    {
        $city = mb_strtolower(trim(explode(',', $city)[0]));
        $city = strtr($city, ['č' => 'c', 'ć' => 'c', 'ž' => 'z', 'š' => 's', 'đ' => 'dj']);

        return (string) preg_replace('/[\s-]+/', ' ', $city);
    }

    public function catalog(string $locale): array
    {
        if (!isset($this->catalogs[$locale])) {
            $catalog = json_decode((string) file_get_contents($this->projectDir.'/frontend/src/locales/'.$locale.'.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->catalogs[$locale] = $catalog['regionalSeo'];
        }
        return $this->catalogs[$locale];
    }

    public function countrySlug(string $code): ?string
    {
        return $this->countries[$code]['slug'] ?? null;
    }

    public function countryCode(string $slug): ?string
    {
        foreach ($this->countries as $code => $country) {
            if ($country['slug'] === $slug) {
                return $code;
            }
        }

        return null;
    }

    public function countryCounts(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT UPPER(u.country_code) AS code, COUNT(*) AS total FROM creator c JOIN wave_user u ON u.id = c.owner_id WHERE c.agency_profile = FALSE AND u.approved = TRUE AND u.hide_my_account = FALSE GROUP BY UPPER(u.country_code)');
        $counts = [];
        foreach ($rows as $row) {
            if (isset($this->countries[$row['code']])) {
                $counts[$row['code']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    public function extraPages(): array
    {
        $pages = [['seo-guides', []], ['creator-guides', []], ['how-it-works', []]];
        foreach (array_keys($this->catalog('bs')['guides']) as $guide) {
            $pages[] = ['seo-guide', ['guide' => $guide]];
        }
        foreach (array_keys($this->catalog('bs')['creatorGuides']['guides']) as $guide) {
            $pages[] = ['creator-guide', ['guide' => $guide]];
        }
        foreach ($this->countryCounts() as $code => $count) {
            if ($count > 0) {
                $pages[] = ['country-creators', ['country' => $this->countries[$code]['slug']]];
                foreach ($this->availableTopics($code) as $topic) {
                    $pages[] = ['country-topic', ['country' => $this->countries[$code]['slug'], 'topic' => $topic['slug']]];
                }
            }
        }

        return $pages;
    }

    public function creators(string $code): array
    {
        return $this->connection->fetchAllAssociative('SELECT c.display_name AS name, c.slug, COALESCE(u.city, c.city) AS city FROM creator c JOIN wave_user u ON u.id = c.owner_id WHERE c.agency_profile = FALSE AND u.approved = TRUE AND u.hide_my_account = FALSE AND UPPER(u.country_code) = :code ORDER BY c.created_at DESC, c.id DESC LIMIT 24', ['code' => $code]);
    }

    /** Initial public content matches the Vue page and remains readable without JavaScript. */
    public function html(string $route, string $locale, array $params, LocalizedRouteMap $routes, array $creators = []): string
    {
        $copy = $this->catalog($locale);
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $link = static fn (string $name, array $parameters, string $text): string => '<a href="'.$escape($routes->localizedPath($name, $locale, $parameters)).'">'.$escape($text).'</a>';
        if ($route === 'how-it-works') {
            $how = $copy['howItWorks'];
            $html = '<main class="page-width seo-content"><h1>'.$escape($how['title']).'</h1><p>'.$escape($how['intro']).'</p>';
            foreach (['creators', 'companies'] as $audience) {
                $html .= '<section><h2>'.$escape($how[$audience]['title']).'</h2><p>'.$escape($how[$audience]['intro']).'</p><ol>';
                foreach ($how[$audience]['steps'] as $step) {
                    $html .= '<li><h3>'.$escape($step['heading']).'</h3><p>'.$escape($step['body']).'</p></li>';
                }
                $html .= '</ol>'.$link($audience === 'creators' ? 'creator-guides' : 'seo-guides', [], $how[$audience]['guides']).'</section>';
            }

            return $html.'<h2>'.$escape($how['startTitle']).'</h2><p>'.$escape($how['startIntro']).'</p><p>'.$link('account', [], $how['start']).' · '.$link('creators', [], $copy['browse']).' · '.$link('campaigns', [], $copy['creatorGuides']['browse']).'</p></main>';
        }
        $forCreators = in_array($route, ['creator-guides', 'creator-guide'], true);
        if ($forCreators) {
            $copy['guides'] = $copy['creatorGuides']['guides'];
            $copy['guideTitle'] = $copy['creatorGuides']['title'];
            $copy['guideIntro'] = $copy['creatorGuides']['intro'];
            $copy['eyebrow'] = $copy['creatorGuides']['eyebrow'];
        }
        $html = '<main class="page-width seo-content"><p class="eyebrow">'.$escape($copy['eyebrow']).'</p>';
        if ($route === 'country-creators' || $route === 'country-topic') {
            $code = (string) $this->countryCode($params['country']);
            $country = $copy['countries'][$code];
            if ($route === 'country-topic') {
                $topic = $this->topic($code, $params['topic']);
                $topicCopy = $topic === null ? null : $this->topicCopy($locale, $code, $topic);
                $html .= '<h1>'.$escape($topicCopy['title'] ?? '').'</h1><p>'.$escape($topicCopy['intro'] ?? '').'</p>';
            } else {
                $html .= '<h1>'.$escape($country['title']).'</h1><p>'.$escape($country['intro']).'</p>';
            }
            if ($creators === []) {
                $html .= '<p>'.$escape($copy['empty']).'</p>';
            } else {
                $html .= '<ul>';
                foreach ($creators as $creator) {
                    $html .= '<li>'.$link('creator-profile', ['slug' => $creator['slug']], $creator['name']).' '.$escape($creator['city'] ?? '').'</li>';
                }
                $html .= '</ul>';
            }
            foreach (['select', 'process'] as $section) {
                $html .= '<h2>'.$escape($copy[$section.'Title']).'</h2><p>'.$escape($copy[$section.'Body']).'</p>';
            }
            $html .= $this->topicLinksHtml($locale, $code, $routes, $params['topic'] ?? null);
        } elseif (in_array($route, ['seo-guide', 'creator-guide'], true)) {
            $guide = $copy['guides'][$params['guide']];
            $html .= '<h1>'.$escape($guide['title']).'</h1><p>'.$escape($guide['intro']).'</p>';
            foreach ($guide['sections'] as $section) {
                $html .= '<h2>'.$escape($section['heading']).'</h2><p>'.$escape($section['body']).'</p>';
            }
        } else {
            $html .= '<h1>'.$escape($copy['guideTitle']).'</h1><p>'.$escape($copy['guideIntro']).'</p>';
        }
        if (!in_array($route, ['seo-guides', 'creator-guides'], true)) {
            $html .= '<h2>'.$escape($copy['guideTitle']).'</h2>';
        }
        $html .= '<ul>';
        foreach ($copy['guides'] as $slug => $guide) {
            $html .= '<li>'.$link($forCreators ? 'creator-guide' : 'seo-guide', ['guide' => $slug], $guide['title']).'</li>';
        }
        $html .= '</ul><h2>'.$escape($copy['countriesTitle']).'</h2><ul>';
        foreach ($this->countryCounts() as $code => $count) {
            if ($count > 0) {
                $html .= '<li>'.$link('country-creators', ['country' => $this->countries[$code]['slug']], $copy['countries'][$code]['name']).'</li>';
            }
        }
        $html .= '</ul><p>'.($forCreators
            ? $link('campaigns', [], $copy['creatorGuides']['browse']).' · '.$link('account', [], $copy['creatorGuides']['profile'])
            : $link('creators', [], $copy['browse']).' · '.$link('account-campaign-create', [], $copy['campaign'])).' · '.$link('how-it-works', [], $copy['howItWorks']['title']).'</p>';

        return $html.'</main>';
    }

    /** Links from a country page (or a sibling landing page) to every populated landing page. */
    public function topicLinksHtml(string $locale, string $countryCode, LocalizedRouteMap $routes, ?string $current = null): string
    {
        $copy = $this->catalog($locale);
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $country = $this->countries[$countryCode]['slug'];
        $groups = ['category' => [], 'city' => []];
        foreach ($this->availableTopics($countryCode) as $topic) {
            if ($topic['slug'] === $current) continue;
            $label = $this->topicCopy($locale, $countryCode, $this->topic($countryCode, $topic['slug']))['title'];
            $groups[$topic['kind'] === 'city' ? 'city' : 'category'][] = '<li><a href="'.$escape($routes->localizedPath('country-topic', $locale, ['country' => $country, 'topic' => $topic['slug']])).'">'.$escape($label).'</a></li>';
        }
        $html = '';
        foreach (['category' => 'byCategory', 'city' => 'byCity'] as $kind => $heading) {
            if ($groups[$kind] !== []) $html .= '<h2>'.$escape($copy['topics'][$heading]).'</h2><ul>'.implode('', $groups[$kind]).'</ul>';
        }
        if ($current !== null) {
            $html .= '<p><a href="'.$escape($routes->localizedPath('country-creators', $locale, ['country' => $country])).'">'.$escape(str_replace('{in}', $copy['countries'][$countryCode]['in'], $copy['topics']['allIn'])).'</a></p>';
        }

        return $html;
    }
}
