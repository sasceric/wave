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

final class ApiControllerTest extends WebTestCase
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

    public function testCreatorDirectorySupportsSearchAndLabelsMetrics(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $creator = new Creator(
            'maya-chen',
            'Maya Chen',
            'Travel',
            'Los Angeles, CA',
            'Slow travel and local guides.',
            [['platform' => 'Instagram', 'handle' => '@maya', 'followers' => 12000, 'lastUpdated' => '2026-09-18']],
            ['Travel'],
        );
        $entityManager->persist($creator);
        $entityManager->flush();

        $this->client->request('GET', '/api/creators?q=los+angeles');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['meta']['count']);
        self::assertSame('maya-chen', $payload['data'][0]['slug']);
        self::assertSame('Prijavio/la kreator/ica', $payload['data'][0]['socialProfiles'][0]['source']);
        self::assertSame('2026-09-18', $payload['data'][0]['socialProfiles'][0]['lastUpdated']);
    }

    public function testCampaignBriefIncludesCompanyAndCanBeFiltered(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $company = new Company('field-notes', 'Field Notes', 'Travel', verified: true);
        $campaign = new Campaign(
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
            true,
        );
        $expiredCampaign = new Campaign(
            'field-notes-expired',
            'An old travel brief',
            'This call has closed.',
            'A past campaign.',
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
        $entityManager->persist($company);
        $entityManager->persist($campaign);
        $entityManager->persist($expiredCampaign);
        $entityManager->flush();

        $this->client->request('GET', '/api/campaigns?featured=true&category=travel&company=field-notes');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['meta']['count']);
        self::assertSame('Field Notes', $payload['data'][0]['company']['name']);
        self::assertTrue($payload['data'][0]['company']['verified']);
        self::assertNull($payload['data'][0]['coverImageUrl']);
        self::assertSame(500, $payload['data'][0]['budgetMin']);

        $this->client->request('GET', '/api/companies/field-notes');
        self::assertResponseIsSuccessful();
        $companyPayload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('field-notes', $companyPayload['data']['slug']);

        $this->client->request('GET', '/api/campaigns?q=Field+Notes');
        self::assertResponseIsSuccessful();
        $brandSearchPayload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $brandSearchPayload['meta']['count']);

        $this->client->request('GET', '/api/campaigns/field-notes-expired');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCompanyDirectoryCountsAvailableCampaignsAndOrdersFeaturedCompaniesFirst(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $featuredLeader = new Company('featured-leader', 'Featured Leader', 'Lifestyle');
        $featuredLeader->setFeatured(true);
        $featuredRunnerUp = new Company('featured-runner-up', 'Featured Runner Up', 'Food');
        $featuredRunnerUp->setFeatured(true);
        $unfeaturedLeader = new Company('unfeatured-leader', 'Unfeatured Leader', 'Travel');
        $unfeaturedCompany = new Company('unfeatured-company', 'Unfeatured Company', 'Beauty');

        foreach ([$featuredLeader, $featuredRunnerUp, $unfeaturedLeader, $unfeaturedCompany] as $company) {
            $entityManager->persist($company);
        }

        $makeCampaign = static function (
            string $slug,
            Company $company,
            string $closesAt = '+14 days',
        ): Campaign {
            return new Campaign(
                $slug,
                $slug,
                'A campaign summary.',
                'A campaign brief.',
                'Lifestyle',
                ['Instagram'],
                ['One post'],
                300,
                700,
                'Sarajevo',
                2,
                new DateTimeImmutable($closesAt),
                new DateTimeImmutable('today'),
                $company,
            );
        };

        $campaigns = [
            $makeCampaign('featured-leader-one', $featuredLeader),
            $makeCampaign('featured-leader-two', $featuredLeader),
            $makeCampaign('featured-leader-three', $featuredLeader),
            $makeCampaign('featured-leader-expired', $featuredLeader, '-1 day'),
            $makeCampaign('featured-runner-up-one', $featuredRunnerUp),
            $makeCampaign('unfeatured-leader-one', $unfeaturedLeader),
            $makeCampaign('unfeatured-leader-two', $unfeaturedLeader),
            $makeCampaign('unfeatured-leader-three', $unfeaturedLeader),
        ];

        foreach ($campaigns as $campaign) {
            $entityManager->persist($campaign);
        }
        $entityManager->flush();

        $this->client->request('GET', '/api/companies');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            ['featured-leader', 'featured-runner-up', 'unfeatured-leader', 'unfeatured-company'],
            array_column($payload['data'], 'slug'),
        );
        self::assertSame([3, 1, 3, 0], array_column($payload['data'], 'availableCampaignCount'));
    }

    public function testHomepageShowsFourFeaturedCampaignsAndFillsWithLatestOpenOnes(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $company = new Company('wave-brand', 'Wave Brand', 'Lifestyle');
        $today = new DateTimeImmutable('today');
        $entityManager->persist($company);
        $campaigns = [
            ['featured-old', true, '-12 days'],
            ['featured-new', true, '-8 days'],
            ['latest-one', false, '-1 day'],
            ['latest-two', false, '-2 days'],
            ['latest-three', false, '-3 days'],
        ];

        foreach ($campaigns as [$slug, $featured, $publishedOffset]) {
            $entityManager->persist(new Campaign(
                $slug,
                $slug,
                'A campaign summary.',
                'A campaign brief.',
                'Lifestyle',
                ['Instagram'],
                ['One video'],
                300,
                700,
                'Sarajevo',
                2,
                $today->modify('+20 days'),
                $today->modify($publishedOffset),
                $company,
                $featured,
            ));
        }
        $entityManager->flush();

        $this->client->request('GET', '/api/homepage');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            ['featured-new', 'featured-old', 'latest-one', 'latest-two'],
            array_column($payload['data']['campaigns'], 'slug'),
        );
    }

    public function testInvalidLimitAndUnknownProfilesReturnErrors(): void
    {
        $this->client->request('GET', '/api/creators?limit=500');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/creators/not-a-creator');
        self::assertResponseStatusCodeSame(404);
        self::assertJson($this->client->getResponse()->getContent());

        $this->client->request('GET', '/api/campaigns?featured[]=true');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/not-a-route');
        self::assertResponseStatusCodeSame(404);
        self::assertJson($this->client->getResponse()->getContent());
    }

    public function testLocalizedDemoFieldsAndUnsupportedLocales(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $creator = new Creator(
            'maya-chen',
            'Maya Chen',
            'Travel',
            'Ljubljana, Slovenia',
            'English base biography.',
            [],
            ['Travel'],
            translations: [
                'bs' => ['category' => 'Putovanja', 'bio' => 'Bosanski opis.', 'tags' => ['Putovanja']],
                'hr' => ['category' => 'Putovanja', 'bio' => 'Hrvatski opis.', 'tags' => ['Putovanja']],
                'sr' => ['category' => 'Putovanja', 'bio' => 'Srpski opis.', 'tags' => ['Putovanja']],
                'cnr' => ['category' => 'Putovanja', 'bio' => 'Crnogorski opis.', 'tags' => ['Putovanja']],
                'sl' => ['category' => 'Potovanja', 'bio' => 'Slovenski opis.', 'tags' => ['Potovanja']],
                'en' => ['category' => 'Travel', 'bio' => 'English description.', 'tags' => ['Travel']],
            ],
        );
        $entityManager->persist($creator);
        $entityManager->flush();

        foreach (['bs', 'hr', 'sr', 'cnr', 'sl', 'en'] as $locale) {
            $this->client->request('GET', '/api/creators/maya-chen?locale='.$locale);
            self::assertResponseIsSuccessful();
            $payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(
                [
                    'bs' => 'Bosanski opis.',
                    'hr' => 'Hrvatski opis.',
                    'sr' => 'Srpski opis.',
                    'cnr' => 'Crnogorski opis.',
                    'sl' => 'Slovenski opis.',
                    'en' => 'English description.',
                ][$locale],
                $payload['data']['bio'],
            );
        }

        $this->client->request('GET', '/api/creators?locale=fr');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Odabrani jezik nije podržan.', json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']);
    }
}
