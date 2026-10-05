<?php

namespace App\Tests\Controller;

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
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
        self::assertStringContainsString('<loc>http://127.0.0.1:8000/sr/kreatori/maya-chen</loc>', $xml);
        self::assertStringContainsString('hreflang="sr-Latn"', $xml);
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

        $this->client->request('GET', '/sr/kreatori/maya-chen');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('<html lang="sr-Latn">', $html);
        self::assertStringContainsString('name="wave:origin" content="http://127.0.0.1:8000"', $html);
        self::assertStringContainsString('<title>Maya Chen — kreator/ka na Waveu</title>', $html);
        self::assertStringContainsString('rel="canonical" href="http://127.0.0.1:8000/sr/kreatori/maya-chen"', $html);
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
        self::assertStringContainsString('rel="canonical" href="http://127.0.0.1:8000/en/"', $html);
        self::assertStringContainsString('hreflang="x-default" href="http://127.0.0.1:8000/"', $html);
        self::assertStringContainsString('"@type":"WebSite"', $html);
    }

    public function testLegalPagesAreIndexableWithLocalizedCanonicalAlternatesAndOrganizationSchema(): void
    {
        $this->client->request('GET', '/en/privacy-policy');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('<title>Privacy policy | Wave</title>', $html);
        self::assertStringContainsString('name="robots" content="index, follow"', $html);
        self::assertStringContainsString('rel="canonical" href="http://127.0.0.1:8000/en/privacy-policy"', $html);
        self::assertStringContainsString('hreflang="sr-Latn" href="http://127.0.0.1:8000/sr/politika-privatnosti"', $html);
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

        $this->client->request('GET', '/unknown-page');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('name="robots" content="noindex, nofollow"', $this->client->getResponse()->getContent());
    }
}
