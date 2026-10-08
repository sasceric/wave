<?php

namespace App\Tests\Controller;

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Localization\LocalizedRouteMap;
use App\Service\FrontendAssets;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SeoControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testCreatorGuidesAndHowItWorksHaveReadableContentCanonicalAlternatesAndSitemapEntries(): void
    {
        $routes = static::getContainer()->get(LocalizedRouteMap::class);
        foreach ($routes->locales() as $locale) {
            foreach ([['creator-guides', []], ['creator-guide', ['guide' => 'create-profile']], ['creator-guide', ['guide' => 'offer-packages']], ['creator-guide', ['guide' => 'apply-to-campaigns']], ['how-it-works', []]] as [$name, $params]) {
                $path = $routes->localizedPath($name, $locale, $params);
                $crawler = $this->client->request('GET', $path);
                self::assertResponseIsSuccessful();
                self::assertCount(1, $crawler->filter('#app h1'));
                self::assertGreaterThan(100, mb_strlen($crawler->filter('#app')->text()));
                self::assertSame('http://127.0.0.1:8000'.$path, $crawler->filter('link[rel="canonical"]')->attr('href'));
                self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
                self::assertCount(7, $crawler->filter('link[rel="alternate"][hreflang]'));
            }
        }
        $this->client->request('GET', '/vodici-za-kreatore/not-a-guide');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/vodici-za-kreatore/create-profile', $this->client->getResponse()->getContent());
        self::assertStringContainsString('/kako-funkcionise', $this->client->getResponse()->getContent());
    }

    public function testCountryDirectoriesUseRealCountriesAndRespectProfileVisibility(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([['ba-public', 'BA', true, false], ['hr-public', 'HR', true, false], ['ba-hidden', 'BA', true, true], ['ba-pending', 'BA', false, false]] as [$slug, $country, $approved, $hidden]) {
            $owner = new \App\Entity\User($slug.'@example.test', 'ROLE_CREATOR');
            $owner->setPassword('test-only-unused-hash');
            $owner->setCountryCode($country);
            $owner->setApproved($approved);
            $owner->setHideMyAccount($hidden);
            $creator = new Creator($slug, $slug, 'Lifestyle', 'Sarajevo', 'Public portfolio and packages.', []);
            $owner->setCreator($creator);
            $em->persist($owner);
            $em->persist($creator);
        }
        $em->flush();
        $routes = static::getContainer()->get(LocalizedRouteMap::class);
        foreach ($routes->locales() as $locale) {
            $path = $routes->localizedPath('country-creators', $locale, ['country' => 'bosna-i-hercegovina']);
            $crawler = $this->client->request('GET', $path.'?q=ignored&utm_source=test');
            self::assertResponseIsSuccessful();
            self::assertSame('http://127.0.0.1:8000'.$path, $crawler->filter('link[rel="canonical"]')->attr('href'));
            self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
            self::assertCount(1, $crawler->filter('#app h1'));
            self::assertStringContainsString('ba-public', $crawler->filter('#app')->text());
            self::assertStringNotContainsString('hr-public', $crawler->filter('#app')->text());
            self::assertStringNotContainsString('ba-hidden', $crawler->filter('#app')->text());
            self::assertStringNotContainsString('ba-pending', $crawler->filter('#app')->text());
            self::assertStringContainsString('"@type":"ItemList"', $crawler->filter('#wave-schema')->text());
            foreach ($routes->localizedAlternates('country-creators', ['country' => 'bosna-i-hercegovina']) as $language => $alternate) {
                self::assertSame('http://127.0.0.1:8000'.$alternate, $crawler->filter('link[hreflang="'.$language.'"]')->attr('href'));
            }
        }
        $this->client->request('GET', '/api/creators?countries=%5B%22BA%22%5D&view=card&sort=newest&limit=24');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['ba-public'], array_column($data['data'], 'slug'));
        $this->client->request('GET', '/sitemap.xml');
        $xml = $this->client->getResponse()->getContent();
        self::assertStringContainsString('/influenseri/bosna-i-hercegovina</loc>', $xml);
        self::assertStringContainsString('/hr/influenceri/hrvatska</loc>', $xml);
        self::assertStringNotContainsString('/influenseri/srbija</loc>', $xml);
        self::assertStringNotContainsString('ba-hidden', $xml);
        $directory = sys_get_temp_dir().'/wave-regional-sitemap-'.bin2hex(random_bytes(6));
        $files = new \Symfony\Component\Filesystem\Filesystem();
        $cached = new \App\Background\CachedSitemap(
            $em->getConnection(), $routes, new \App\Service\SiteOrigin('http://127.0.0.1:8000'),
            static::getContainer()->get(\App\Background\JobDispatcher::class), $files, $directory,
            static::getContainer()->get(\App\Service\RegionalSeoContent::class),
        );
        try {
            $cached->generate();
            $parts = glob($directory.'/*-0.xml');
            self::assertCount(1, $parts);
            $cachedXml = file_get_contents($parts[0]);
            self::assertStringContainsString('/influenseri/bosna-i-hercegovina</loc>', $cachedXml);
            self::assertStringContainsString('/hr/influenceri/hrvatska</loc>', $cachedXml);
            self::assertStringContainsString('/si/vodniki/campaign-brief</loc>', $cachedXml);
            self::assertStringNotContainsString('/influenseri/srbija</loc>', $cachedXml);
            self::assertStringNotContainsString('/sr/', $cachedXml);
            self::assertStringNotContainsString('ba-hidden', $cachedXml);
        } finally {
            $files->remove($directory);
        }
        $crawler = $this->client->request('GET', '/rs/influenseri/srbija');
        self::assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(0, $crawler->filter('link[hreflang]'));
        $this->client->request('GET', '/influenseri/not-a-country');
        self::assertResponseStatusCodeSame(404);
    }

    public function testLocalizedGuidesRenderTheirContentBeforeJavaScriptAndRejectUnknownArticles(): void
    {
        $routes = static::getContainer()->get(LocalizedRouteMap::class);
        foreach ($routes->locales() as $locale) {
            $catalog = json_decode(file_get_contents(dirname(__DIR__, 2).'/frontend/src/locales/'.$locale.'.json'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($catalog['regionalSeo']['guides'] as $slug => $guide) {
                $path = $routes->localizedPath('seo-guide', $locale, ['guide' => $slug]);
                $crawler = $this->client->request('GET', $path);
                self::assertResponseIsSuccessful();
                self::assertSame($guide['title'], $crawler->filter('#app h1')->text());
                foreach ($guide['sections'] as $section) {
                    self::assertStringContainsString($section['body'], $crawler->filter('#app')->text());
                }
                self::assertSame('http://127.0.0.1:8000'.$path, $crawler->filter('link[rel="canonical"]')->attr('href'));
                self::assertCount(7, $crawler->filter('link[hreflang]'));
                self::assertStringContainsString('"@type":"Article"', $crawler->filter('#wave-schema')->text());
            }
        }
        $this->client->request('GET', '/en/guides/not-a-guide');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAgentDiscoveryReturnsActualDocumentsInsteadOfTheSpaShell(): void
    {
        $this->client->request('GET', '/llms.txt');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        $text = $this->client->getResponse()->getContent();
        self::assertStringStartsWith('# Wave', $text);
        self::assertStringContainsString('[Campaigns](http://127.0.0.1:8000/kampanje)', $text);
        self::assertStringNotContainsString('<!doctype', $text);
        foreach (['/.well-known/ai-catalog.json', '/.well-known/ard.json'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/json');
            $catalog = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('1.0', $catalog['specVersion']);
            self::assertSame([], $catalog['entries']);
            self::assertSame('http://127.0.0.1:8000/llms.txt', $catalog['host']['documentationUrl']);
        }
    }

    public function testSitemapContainsLocalizedPublicPagesAndExcludesPrivateOrClosedContent(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $company = new Company('field-notes', 'Field Notes', 'Travel');
        $creator = new Creator(
            'maya-chen',
            'Maya Chen',
            'Travel',
            'Los Angeles, CA',
            'Slow travel and local guides.',
            [['platform' => 'Instagram', 'handle' => '@maya', 'followers' => 12000]],
        );
        $openCampaign = new Campaign(
            'scenic-route',
            'Take the scenic route',
            'A local guide for curious travelers.',
            'Share a place that makes you slow down.',
            'Travel',
            ['Instagram'],
            ['1 photo carousel'],
            500,
            1200,
            'United States',
            3,
            new DateTimeImmutable('+14 days'),
            new DateTimeImmutable('today'),
            $company,
        );
        $closedCampaign = new Campaign(
            'closed-brief',
            'Closed brief',
            'This campaign has ended.',
            'Past campaign details.',
            'Travel',
            ['Instagram'],
            ['1 post'],
            200,
            400,
            'United States',
            1,
            new DateTimeImmutable('-1 day'),
            new DateTimeImmutable('-30 days'),
            $company,
        );
        $secondOpenCampaign = new Campaign(
            'second-open-brief',
            'Second open brief',
            'Public immediately.',
            'Published campaigns are visible immediately.',
            'Travel',
            ['Instagram'],
            ['1 post'],
            200,
            400,
            'United States',
            1,
            new DateTimeImmutable('+14 days'),
            new DateTimeImmutable('today'),
            $company,
        );
        $entityManager->persist($company);
        $entityManager->persist($creator);
        $entityManager->persist($openCampaign);
        $entityManager->persist($closedCampaign);
        $entityManager->persist($secondOpenCampaign);
        $entityManager->flush();

        $this->client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        $xml = $this->client->getResponse()->getContent();
        self::assertStringContainsString('<loc>http://127.0.0.1:8000/rs/kreatori/maya-chen</loc>', $xml);
        self::assertStringContainsString('hreflang="sr-RS"', $xml);
        self::assertStringContainsString('hreflang="sr-ME"', $xml);
        self::assertStringNotContainsString('hreflang="cnr', $xml);
        self::assertStringContainsString('<loc>http://127.0.0.1:8000/en/companies/field-notes</loc>', $xml);
        self::assertStringContainsString('<loc>http://127.0.0.1:8000/en/campaigns/scenic-route</loc>', $xml);
        self::assertStringContainsString('<loc>http://127.0.0.1:8000/en/campaigns/second-open-brief</loc>', $xml);
        self::assertStringContainsString('<loc>http://127.0.0.1:8000/impresum</loc>', $xml);
        self::assertStringContainsString('<loc>http://127.0.0.1:8000/en/imprint</loc>', $xml);
        self::assertStringContainsString('<loc>http://127.0.0.1:8000/en/privacy-policy</loc>', $xml);
        self::assertStringContainsString('<loc>http://127.0.0.1:8000/en/cookies</loc>', $xml);
        self::assertStringContainsString('href="http://127.0.0.1:8000/politika-privatnosti"', $xml);
        self::assertStringNotContainsString('closed-brief', $xml);
        self::assertStringNotContainsString('/account', $xml);
        self::assertStringNotContainsString('/api/', $xml);
    }

    public function testRobotsTxtPointsToSitemapAndDisallowsPrivateAreas(): void
    {
        $this->client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        $robots = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Allow: /', $robots);
        self::assertStringContainsString('Disallow: /api/me/', $robots);
        self::assertStringNotContainsString("Disallow: /api/\n", $robots);
        self::assertStringContainsString('Disallow: /racun', $robots);
        self::assertStringContainsString('Disallow: /en/account', $robots);
        self::assertStringContainsString('Disallow: /moderacija', $robots);
        self::assertStringContainsString('Sitemap: http://127.0.0.1:8000/sitemap.xml', $robots);
    }

    public function testRenderedProfileHeadIncludesCanonicalHreflangAndPersonSchema(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new Creator(
            'maya-chen',
            'Maya Chen',
            'Travel',
            'Los Angeles, CA',
            'Slow travel and local guides.',
            [['platform' => 'Instagram', 'handle' => '@maya', 'followers' => 12000]],
        ));
        $entityManager->flush();

        $this->client->request('GET', '/rs/kreatori/maya-chen');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('<html lang="sr-Latn">', $html);
        self::assertStringContainsString('name="wave:origin" content="http://127.0.0.1:8000"', $html);
        self::assertStringNotContainsString('rel="preload" as="image"', $html);
        self::assertStringContainsString('<title>Maya Chen — kreator/ka na Waveu</title>', $html);
        self::assertStringContainsString('rel="canonical" href="http://127.0.0.1:8000/rs/kreatori/maya-chen"', $html);
        self::assertStringContainsString('property="og:image" content="http://127.0.0.1:8000/images/logo-icon.svg"', $html);
        self::assertStringContainsString('hreflang="x-default" href="http://127.0.0.1:8000/kreatori/maya-chen"', $html);
        self::assertStringContainsString('"@type":"Person"', $html);
        self::assertStringContainsString('Slow travel and local guides.', $html);
    }

    public function testLocalizedHomepageUsesDefaultLocaleAlternateUrls(): void
    {
        $this->client->request('GET', '/en/');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('imagesrcset="/images/banner-girl-360.webp 360w', $html);
        self::assertStringContainsString('fetchpriority="high"', $html);
        self::assertStringContainsString('rel="canonical" href="http://127.0.0.1:8000/en/"', $html);
        self::assertStringContainsString('property="og:image" content="http://127.0.0.1:8000/images/share.webp"', $html);
        self::assertStringContainsString('hreflang="x-default" href="http://127.0.0.1:8000/"', $html);
        self::assertStringContainsString('"@type":"WebSite"', $html);
    }

    public function testHomepageStylesLoadEarlyWithoutLoadingThemOnDirectoryPages(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wave-home-styles-');
        self::assertIsString($path);
        try {
            file_put_contents($path, json_encode([
                'src/views/HomeView.vue' => ['css' => ['build/assets/HomeView-test.css']],
            ], JSON_THROW_ON_ERROR));
            $this->client->disableReboot();
            static::getContainer()->set(FrontendAssets::class, new FrontendAssets($path));
            foreach (['/', '/en/', '/hr/', '/rs/', '/me/', '/si/'] as $homepage) {
                $this->client->request('GET', $homepage);
                self::assertSame(200, $this->client->getResponse()->getStatusCode(), $homepage);
                self::assertStringContainsString(
                    'rel="stylesheet" crossorigin href="/build/assets/HomeView-test.css"',
                    $this->client->getResponse()->getContent(),
                );
            }
            $this->client->request('GET', '/en/creators');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('HomeView-test.css', $this->client->getResponse()->getContent());
        } finally {
            unlink($path);
        }
    }

    public function testPublicLocaleCanonicalsAndAlternatesAreReciprocalAndMatchTheSitemap(): void
    {
        $routes = static::getContainer()->get(LocalizedRouteMap::class);
        $languages = ['bs' => 'bs', 'hr' => 'hr', 'sr' => 'sr-RS', 'sl' => 'sl', 'en' => 'en', 'cnr' => 'sr-ME'];
        $this->client->request('GET', '/sitemap.xml');
        $sitemap = new \Symfony\Component\DomCrawler\Crawler($this->client->getResponse()->getContent());
        $sitemap->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $sitemap->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
        foreach (['home', 'creators', 'companies', 'campaigns', 'privacy-policy'] as $route) {
            $expected = [];
            foreach ($languages as $locale => $language) {
                $expected[$language] = 'http://127.0.0.1:8000'.$routes->localizedPath($route, $locale);
            }
            $expected['x-default'] = $expected['bs'];
            ksort($expected);
            foreach ($languages as $locale => $language) {
                $url = $expected[$language];
                $crawler = $this->client->request('GET', $url.'?q=example&utm_source=test');
                self::assertResponseIsSuccessful();
                self::assertSame($url, $crawler->filter('head link[rel="canonical"]')->attr('href'));
                self::assertSame(1, $crawler->filter('head link[rel="canonical"]')->count());
                self::assertSame(['sr' => 'sr-Latn', 'cnr' => 'sr-Latn-ME'][$locale] ?? $locale, $crawler->filter('html')->attr('lang'));
                $actual = [];
                $crawler->filter('head link[rel="alternate"][hreflang]')->each(static function ($link) use (&$actual): void {
                    $actual[$link->attr('hreflang')] = $link->attr('href');
                });
                ksort($actual);
                self::assertSame($expected, $actual);
                $entry = $sitemap->filterXPath('//sm:url[sm:loc="'.$url.'"]');
                self::assertSame(1, $entry->count());
                $xmlAlternates = [];
                $sitemap->filterXPath('//sm:url[sm:loc="'.$url.'"]/xhtml:link')->each(static function ($link) use (&$xmlAlternates): void {
                    $xmlAlternates[$link->attr('hreflang')] = $link->attr('href');
                });
                ksort($xmlAlternates);
                self::assertSame($expected, $xmlAlternates);
            }
        }
    }

    public function testPublicAliasesRedirectToTheCanonicalPathAndPreserveSearch(): void
    {
        foreach ([['/creators', '/kreatori'], ['/hr/kreatori/', '/hr/kreatori'], ['/cnr', '/me/'], ['/sr/kreatori', '/rs/kreatori'], ['/sl/ustvarjalci', '/si/ustvarjalci'], ['/cnr/kreatori', '/me/kreatori']] as [$alias, $path]) {
            $this->client->request('GET', $alias.'?q=travel');
            self::assertResponseRedirects('http://127.0.0.1:8000'.$path.'?q=travel', 301);
        }
    }

    public function testCompanyProfileUsesItsLogoOrTheWavePlaceholderForSharing(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new Company('field-notes', 'Field Notes', 'Travel'));
        $entityManager->flush();

        $this->client->request('GET', '/en/companies/field-notes');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'property="og:image" content="http://127.0.0.1:8000/images/logo-icon.svg"',
            $this->client->getResponse()->getContent(),
        );
    }

    public function testLegalPagesAreIndexableWithLocalizedCanonicalAlternatesAndOrganizationSchema(): void
    {
        $this->client->request('GET', '/en/privacy-policy');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('<title>Privacy policy | Wave</title>', $html);
        self::assertStringContainsString('name="robots" content="index, follow"', $html);
        self::assertStringContainsString('rel="canonical" href="http://127.0.0.1:8000/en/privacy-policy"', $html);
        self::assertStringContainsString('hreflang="sr-RS" href="http://127.0.0.1:8000/rs/politika-privatnosti"', $html);
        self::assertStringContainsString('hreflang="x-default" href="http://127.0.0.1:8000/politika-privatnosti"', $html);
        self::assertStringContainsString('"@type":"Organization"', $html);
        self::assertStringContainsString('"vatID":"320531730002"', $html);
        self::assertStringNotContainsString('"telephone"', $html);

        foreach ([
            ['/en/imprint', 'Legal notice | Wave'],
            ['/en/cookies', 'Cookie policy | Wave'],
        ] as [$path, $title]) {
            $this->client->request('GET', $path);

            self::assertResponseIsSuccessful();
            $legalHtml = $this->client->getResponse()->getContent();
            self::assertStringContainsString('<title>'.$title.'</title>', $legalHtml);
            self::assertStringContainsString('name="robots" content="index, follow"', $legalHtml);
            self::assertStringContainsString('rel="canonical" href="http://127.0.0.1:8000'.$path.'"', $legalHtml);
        }

        $this->client->request('GET', '/privacy-policy');

        self::assertResponseRedirects('http://127.0.0.1:8000/politika-privatnosti', 301);
    }

    public function testPrivateAndMissingPagesAreMarkedNoindexWithoutStructuredData(): void
    {
        $this->client->request('GET', '/en/account');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
        self::assertStringNotContainsString('id="wave-schema"', $html);
        self::assertStringNotContainsString('hreflang=', $html);

        $this->client->request('GET', '/unknown-page');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('name="robots" content="noindex, nofollow"', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('rel="canonical"', $this->client->getResponse()->getContent());
    }
}
