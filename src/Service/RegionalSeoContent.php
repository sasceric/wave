<?php

namespace App\Service;

use App\Localization\LocalizedRouteMap;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class RegionalSeoContent
{
    private array $countries;
    private array $catalogs = [];

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        $this->countries = json_decode((string) file_get_contents($projectDir.'/config/seo_countries.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function catalog(string $locale): array
    {
        if (!isset($this->catalogs[$locale])) {
            $catalog = json_decode((string) file_get_contents($this->projectDir.'/frontend/src/locales/'.$locale.'.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->catalogs[$locale] = $catalog['regionalSeo'];
        }
        return $this->catalogs[$locale];
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
        $rows = $this->connection->fetchAllAssociative('SELECT UPPER(u.country_code) AS code, COUNT(*) AS total FROM creator c JOIN wave_user u ON u.id = c.owner_id WHERE u.approved = TRUE AND u.hide_my_account = FALSE GROUP BY UPPER(u.country_code)');
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
            }
        }

        return $pages;
    }

    public function creators(string $code): array
    {
        return $this->connection->fetchAllAssociative('SELECT c.display_name AS name, c.slug, COALESCE(u.city, c.city) AS city FROM creator c JOIN wave_user u ON u.id = c.owner_id WHERE u.approved = TRUE AND u.hide_my_account = FALSE AND UPPER(u.country_code) = :code ORDER BY c.created_at DESC, c.id DESC LIMIT 24', ['code' => $code]);
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
        if ($route === 'country-creators') {
            $country = $copy['countries'][$this->countryCode($params['country'])];
            $html .= '<h1>'.$escape($country['title']).'</h1><p>'.$escape($country['intro']).'</p>';
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
}
