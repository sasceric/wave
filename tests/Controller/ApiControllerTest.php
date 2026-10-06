<?php

namespace App\Tests\Controller;

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
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
            new \DateTimeImmutable('+14 days'),
            new \DateTimeImmutable('today'),
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
            new \DateTimeImmutable('-1 day'),
            new \DateTimeImmutable('-30 days'),
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
                new \DateTimeImmutable($closesAt),
                new \DateTimeImmutable('today'),
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
        $today = new \DateTimeImmutable('today');
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

    public function testDirectoriesReturnOffsetPagesAndTotalCounts(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $company = new Company('page-test-company-01', 'Page Test Company 01', 'Lifestyle');
        $entityManager->persist($company);

        for ($index = 1; $index <= 31; ++$index) {
            $suffix = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $entityManager->persist(new Creator(
                'page-test-creator-' . $suffix,
                'Page Test Creator ' . $suffix,
                'Lifestyle',
                'Sarajevo',
                'A creator profile for directory pagination.',
                [['platform' => 'Instagram', 'handle' => '@creator' . $suffix, 'followers' => 1000]],
                ['Lifestyle'],
            ));
            if ($index > 1) {
                $entityManager->persist(new Company(
                    'page-test-company-' . $suffix,
                    'Page Test Company ' . $suffix,
                    'Lifestyle',
                ));
            }
            $entityManager->persist(new Campaign(
                'page-test-campaign-' . $suffix,
                'Page Test Campaign ' . $suffix,
                'A campaign brief for pagination.',
                'An open campaign created to test directory pagination.',
                'Lifestyle',
                ['Instagram'],
                ['1 post'],
                100,
                200,
                'Sarajevo',
                1,
                new \DateTimeImmutable('+14 days'),
                new \DateTimeImmutable('today'),
                $company,
            ));
        }
        $entityManager->flush();

        foreach (['creators', 'companies', 'campaigns'] as $directory) {
            $this->client->request('GET', '/api/' . $directory . '?limit=30&offset=30');

            self::assertResponseIsSuccessful();
            $payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(1, $payload['meta']['count'], $directory);
            self::assertSame(31, $payload['meta']['total'], $directory);
            self::assertSame(30, $payload['meta']['offset'], $directory);
        }
    }

    public function testCursorDirectoriesPreserveTiesAndRejectAlteredQueries(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $company = new Company('page-test-company-01', 'Page Test Company 01', 'Lifestyle');
        $entityManager->persist($company);

        for ($index = 1; $index <= 61; ++$index) {
            $suffix = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $entityManager->persist(new Creator(
                'page-test-creator-' . $suffix,
                'Same creator',
                'Lifestyle',
                'Sarajevo',
                'A creator profile for directory pagination.',
                [['platform' => 'Instagram', 'handle' => '@creator' . $suffix, 'followers' => 1000]],
                ['Lifestyle'],
            ));
            if ($index > 1) {
                $entityManager->persist(new Company(
                    'page-test-company-' . $suffix,
                    'Same company',
                    'Lifestyle',
                ));
            }
            $entityManager->persist(new Campaign(
                'page-test-campaign-' . $suffix,
                'Page Test Campaign ' . $suffix,
                'A campaign brief for pagination.',
                'An open campaign created to test directory pagination.',
                'Lifestyle',
                ['Instagram'],
                ['1 post'],
                100,
                200,
                'Sarajevo',
                1,
                new \DateTimeImmutable('+14 days'),
                new \DateTimeImmutable('today'),
                $company,
            ));
        }
        $entityManager->flush();

        foreach (['creators', 'companies', 'campaigns'] as $directory) {
            $path = '/api/' . $directory . '?pagination=cursor&view=card&limit=30&locale=en';
            $ids = [];
            $next = null;
            for ($page = 0; $page < 3; ++$page) {
                $this->client->request('GET', $path . ($next === null ? '' : '&cursor=' . urlencode($next)));
                self::assertResponseIsSuccessful();
                $payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($page < 2 ? 30 : 1, count($payload['data']), $directory);
                if ($page === 0) {
                    self::assertSame(61, $payload['meta']['total']);
                    $firstCursor = $payload['meta']['nextCursor'];
                } else {
                    self::assertArrayNotHasKey('total', $payload['meta']);
                }
                $ids = [...$ids, ...array_column($payload['data'], 'id')];
                $next = $payload['meta']['nextCursor'];
                self::assertSame($page < 2, $payload['meta']['hasMore']);
            }
            self::assertCount(61, array_unique($ids));
            self::assertNull($next);
            foreach (['&q=changed', '&offset=30', '&cursor=invalid'] as $invalid) {
                $this->client->request('GET', $path . '&cursor=' . urlencode($firstCursor) . $invalid);
                self::assertResponseStatusCodeSame(400);
            }
            $this->client->request('GET', str_replace('locale=en', 'locale=bs', $path) . '&cursor=' . urlencode($firstCursor));
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testCreatorCursorSurvivesAnEarlierDeletionAndInsertion(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach (['B', 'C', 'D', 'E'] as $name) {
            $entityManager->persist(new Creator('cursor-' . $name, $name, 'Travel', 'Sarajevo', 'Bio', []));
        }
        $entityManager->flush();
        $this->client->request('GET', '/api/creators?pagination=cursor&limit=2');
        $first = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->remove($entityManager->getRepository(Creator::class)->findOneBy(['slug' => 'cursor-B']));
        $entityManager->persist(new Creator('cursor-A', 'A', 'Travel', 'Sarajevo', 'Bio', []));
        $entityManager->flush();
        $this->client->request('GET', '/api/creators?pagination=cursor&limit=2&cursor=' . urlencode($first['meta']['nextCursor']));
        self::assertResponseIsSuccessful();
        $second = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['D', 'E'], array_column($second['data'], 'displayName'));
    }

    public function testPublicDirectoryBatchesExcludeFinishedCampaignsAndHiddenAccounts(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $makeCampaign = static fn (string $slug, Company $company, string $closesAt = '+14 days', string $status = 'open'): Campaign => new Campaign(
            $slug, 'Directory campaign', 'Summary', 'Brief', 'Travel', ['Instagram'], ['One post'],
            300, 700, 'Sarajevo', 1, new \DateTimeImmutable($closesAt), new \DateTimeImmutable('today'),
            $company, status: $status,
        );

        foreach (['hidden', 'unapproved'] as $visibility) {
            $creatorOwner = new User($visibility . '-creator@example.test', 'ROLE_CREATOR');
            $companyOwner = new User($visibility . '-company@example.test', 'ROLE_COMPANY');
            foreach ([$creatorOwner, $companyOwner] as $owner) {
                $owner->setPassword('unused-test-hash');
                $owner->setHideMyAccount($visibility === 'hidden');
                $owner->setApproved($visibility !== 'unapproved');
                $entityManager->persist($owner);
            }
            $creatorOwner->setCreator(new Creator($visibility . '-creator', 'Directory creator', 'Travel', 'Sarajevo', 'Bio', [], []));
            $company = new Company($visibility . '-company', 'Directory Brand', 'Travel');
            $companyOwner->setCompany($company);
            $entityManager->persist($makeCampaign($visibility . '-campaign', $company));
        }

        $expected = ['creators' => [], 'companies' => [], 'campaigns' => []];
        for ($index = 1; $index <= 61; ++$index) {
            $suffix = sprintf('%03d', $index);
            $creator = new Creator('creator-' . $suffix, 'Directory creator', 'Travel', 'Sarajevo', 'Bio', [], []);
            // Equal ranking and names must still produce stable batches by ID.
            $company = new Company('company-' . $suffix, 'Directory Brand', 'Travel');
            $campaign = $makeCampaign('campaign-' . $suffix, $company);
            foreach ([$creator, $company, $campaign] as $entity) {
                $entityManager->persist($entity);
            }
            $expected['creators'][] = $creator->getSlug();
            $expected['companies'][] = $company->getSlug();
            $expected['campaigns'][] = $campaign->getSlug();
            if ($index === 1) {
                $entityManager->persist($makeCampaign('closed-campaign', $company, status: 'closed'));
                $entityManager->persist($makeCampaign('expired-campaign', $company, '-1 day'));
            }
        }
        $entityManager->flush();

        foreach ($expected as $directory => $slugs) {
            $loaded = [];
            foreach ([0 => 30, 30 => 30, 60 => 1, 90 => 0] as $offset => $count) {
                $this->client->request('GET', '/api/' . $directory . '?limit=30&offset=' . $offset);
                self::assertResponseIsSuccessful();
                $payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(61, $payload['meta']['total']);
                self::assertSame($count, $payload['meta']['count']);
                self::assertSame($offset, $payload['meta']['offset']);
                self::assertSame(array_slice($slugs, $offset, 30), array_column($payload['data'], 'slug'));
                $loaded = [...$loaded, ...array_column($payload['data'], 'slug')];
                if ($directory === 'companies') {
                    foreach ($payload['data'] as $company) {
                        self::assertSame(1, $company['availableCampaignCount']);
                    }
                }
            }
            self::assertSame($slugs, $loaded);
            self::assertCount(61, array_unique($loaded));
        }
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
            $this->client->request('GET', '/api/creators/maya-chen?locale=' . $locale);
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
