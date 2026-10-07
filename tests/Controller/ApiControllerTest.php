<?php

namespace App\Tests\Controller;

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\MarketplaceCategory;
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

    public function testAdminAreasAreSharedByCompaniesCreatorsAndCampaigns(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $category = new MarketplaceCategory('Photography', ['bs' => 'Fotografija', 'en' => 'Photography'], 0);
        $inactive = new MarketplaceCategory('Inactive', ['en' => 'Inactive'], 1);
        $inactive->update($inactive->getLabels(), 1, false);
        $company = new Company('shared-brand', 'Shared Brand', 'Photography');
        $creator = new Creator('shared-creator', 'Shared Creator', 'Photography', 'Zenica', 'Profile', []);
        $campaign = new Campaign('shared-campaign', 'Shared Campaign', 'Summary', 'Brief', 'Photography', ['Instagram'], ['Post'], 10, 20, 'Zenica', 1, new \DateTimeImmutable('+7 days'), new \DateTimeImmutable('today'), $company);
        foreach ([$category, $inactive, $company, $creator, $campaign] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $this->client->request('GET', '/api/marketplace/categories?locale=bs');
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame([['value' => 'Photography', 'label' => 'Fotografija']], $options);
        $this->client->request('GET', '/api/marketplace/company-industries?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertSame($options, json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']);

        // An admin edit must reach all directories on subsequent requests.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $category = $em->getRepository(MarketplaceCategory::class)->findOneBy(['value' => 'Photography']);
        $category->update(['bs' => 'Fotografija i vizuelne priče', 'en' => 'Photography and visual stories'], 0, true);
        $em->flush();
        foreach (['companies/shared-brand' => 'industry', 'creators/shared-creator' => 'categoryLabel', 'campaigns/shared-campaign' => 'categoryLabel'] as $path => $labelKey) {
            $this->client->request('GET', '/api/' . $path . '?locale=bs');
            self::assertResponseIsSuccessful();
            $data = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
            self::assertSame('Fotografija i vizuelne priče', $data[$labelKey]);
            if (isset($data['company'])) {
                self::assertSame(['Fotografija i vizuelne priče'], $data['company']['industryLabels']);
            }
        }
        $this->client->request('GET', '/api/companies/filters?locale=bs');
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['industries'];
        self::assertSame([['value' => 'Photography', 'label' => 'Fotografija i vizuelne priče', 'count' => 1]], $options);
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

    public function testCreatorFiltersAndSortingUseVisibleExistingFieldsAcrossCursorPages(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([0, 9999, 10000, 49999, 50000, 50000, 200000, 1000] as $index => $followers) {
            $creator = new Creator('filter-creator-' . $index, 'Creator ' . $index, $index === 0 ? 'Travel' : 'Food', 'Legacy city', 'Profile', [
                ['platform' => $index === 5 ? 'TikTok' : 'Instagram', 'followers' => $followers],
            ], categories: $index === 2 ? ['Food', 'Travel'] : [], createdAt: new \DateTimeImmutable('2026-10-' . sprintf('%02d', min($index + 1, 5)) . ' 12:00:00'));
            $owner = new User('creator-filter-' . $index . '@example.test', 'ROLE_CREATOR');
            $owner->setPassword('unused');
            $owner->setApproved($index !== 7);
            $owner->setHideMyAccount($index === 6);
            $owner->setCity($index === 0 ? 'Zenica' : 'Sarajevo');
            $owner->setCountryCode($index % 2 === 0 ? 'BA' : 'HR');
            $owner->setCreator($creator);
            $em->persist($owner);
            $em->persist($creator);
        }
        $em->flush();
        $this->client->request('GET', '/api/creators/filters');
        self::assertResponseIsSuccessful();
        $facets = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame(['BA', 'HR'], array_column($facets['countries'], 'value'));
        self::assertSame([3, 3], array_column($facets['countries'], 'count'));
        $filters = http_build_query(['pagination' => 'cursor', 'limit' => 2, 'sort' => 'followers', 'categories' => '["Travel","Food"]', 'countries' => '["ba","HR"]']);
        $this->client->request('GET', '/api/creators?' . $filters);
        self::assertResponseIsSuccessful();
        $first = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(6, $first['meta']['total']);
        self::assertSame(['Creator 5', 'Creator 4'], array_column($first['data'], 'displayName'));
        $this->client->request('GET', '/api/creators?' . $filters . '&cursor=' . urlencode($first['meta']['nextCursor']));
        self::assertResponseIsSuccessful();
        $second = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['Creator 3', 'Creator 2'], array_column($second['data'], 'displayName'));
        self::assertArrayNotHasKey('total', $second['meta']);
        $this->client->request('GET', '/api/creators?' . $filters . '&cursor=' . urlencode($first['meta']['nextCursor']) . '&audience=large');
        self::assertResponseStatusCodeSame(400);
        foreach (['small' => ['Creator 1'], 'medium' => ['Creator 2', 'Creator 3'], 'large' => ['Creator 4', 'Creator 5']] as $range => $expected) {
            $this->client->request('GET', '/api/creators?audience=' . $range);
            self::assertResponseIsSuccessful();
            self::assertSame($expected, array_column(json_decode($this->client->getResponse()->getContent(), true)['data'], 'displayName'));
        }
        $this->client->request('GET', '/api/creators?' . http_build_query(['categories' => '["Travel"]', 'countries' => '["BA"]', 'city' => 'Zenica']));
        self::assertResponseIsSuccessful();
        self::assertSame(['Creator 0'], array_column(json_decode($this->client->getResponse()->getContent(), true)['data'], 'displayName'));
        $this->client->request('GET', '/api/creators?platforms=' . urlencode('["TikTok"]'));
        self::assertResponseIsSuccessful();
        self::assertSame(['Creator 5'], array_column(json_decode($this->client->getResponse()->getContent(), true)['data'], 'displayName'));
        $this->client->request('GET', '/api/creators?categories=' . urlencode('["Travel"]'));
        self::assertResponseIsSuccessful();
        self::assertSame(['Creator 0', 'Creator 2'], array_column(json_decode($this->client->getResponse()->getContent(), true)['data'], 'displayName'));
        $newest = 'pagination=cursor&sort=newest&limit=1';
        $this->client->request('GET', '/api/creators?' . $newest);
        self::assertResponseIsSuccessful();
        $first = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Creator 5', $first['data'][0]['displayName']);
        $this->client->request('GET', '/api/creators?' . $newest . '&cursor=' . urlencode($first['meta']['nextCursor']));
        self::assertResponseIsSuccessful();
        self::assertSame('Creator 4', json_decode($this->client->getResponse()->getContent(), true)['data'][0]['displayName']);
        foreach (['categories' => '[123]', 'countries' => '["Bosnia"]', 'platforms' => '{"value":"TikTok"}', 'sort' => 'invalid', 'audience' => 'invalid'] as $key => $value) {
            $this->client->request('GET', '/api/creators?' . http_build_query([$key => $value]));
            self::assertResponseStatusCodeSame(400);
        }
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

    public function testCampaignEntityFiltersAndSortingPreserveLazyLoadingScope(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $company = new Company('campaign-filter-brand', 'Campaign Filter Brand', 'Food');
        $hiddenOwner = new User('hidden-campaign-filter@example.com', 'ROLE_COMPANY');
        $hiddenOwner->setPassword('unused-test-password');
        $hiddenOwner->setApproved(true);
        $hiddenOwner->setHideMyAccount(true);
        $hiddenCompany = new Company('hidden-campaign-brand', 'Hidden Campaign Brand', 'Food');
        $hiddenCompany->setOwner($hiddenOwner);
        foreach ([$company, $hiddenOwner, $hiddenCompany] as $entity) {
            $em->persist($entity);
        }
        $make = static fn (string $slug, string $category, array $channels, int $min = 100, int $max = 500, string $location = 'Zenica', string $currency = 'BAM', string $close = '+7 days', string $published = 'today', bool $featured = false, string $status = 'open', ?Company $brand = null): Campaign => new Campaign(
            $slug, $slug, 'Summary', 'Brief', $category, $channels, ['One post'], $min, $max, $location, 2,
            new \DateTimeImmutable($close), new \DateTimeImmutable($published), $brand ?? $company, $featured, $status, currency: $currency,
        );
        foreach ([
            $make('campaign-filter-a', 'Travel', ['Instagram'], featured: true),
            $make('campaign-filter-b', 'Food', ['TikTok'], close: '+3 days'),
            $make('campaign-filter-c', 'Travel', ['Instagram'], close: '+3 days'),
            $make('campaign-filter-other-channel', 'Food', ['YouTube']),
            $make('campaign-filter-other-area', 'Technology', ['Instagram']),
            $make('campaign-filter-other-location', 'Food', ['TikTok'], location: 'Sarajevo'),
            $make('campaign-filter-other-currency', 'Food', ['TikTok'], currency: 'EUR'),
            $make('campaign-filter-other-budget', 'Food', ['TikTok'], min: 600, max: 900),
            $make('campaign-filter-ended', 'Food', ['TikTok'], close: '-1 day'),
            $make('campaign-filter-closed', 'Food', ['TikTok'], status: 'closed'),
            $make('campaign-filter-hidden', 'Food', ['TikTok'], brand: $hiddenCompany),
        ] as $campaign) {
            $em->persist($campaign);
        }
        $em->flush();
        $filters = ['categories' => '["Travel","Food"]', 'channels' => '["Instagram","TikTok"]', 'location' => 'ZENICA', 'currency' => 'BAM', 'budgetMin' => '200', 'budgetMax' => '500', 'pagination' => 'cursor', 'view' => 'card', 'limit' => 1];
        $firstCursor = null;
        foreach (['recommended' => ['a', 'b', 'c'], 'newest' => ['c', 'b', 'a'], 'closing' => ['b', 'c', 'a']] as $sort => $expected) {
            $path = '/api/campaigns?' . http_build_query($filters + ['sort' => $sort]);
            $next = null;
            $loaded = [];
            do {
                $this->client->request('GET', $path . ($next === null ? '' : '&cursor=' . urlencode($next)));
                self::assertResponseIsSuccessful();
                $payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
                if ($next === null) {
                    self::assertSame(3, $payload['meta']['total']);
                    if ($sort === 'recommended') {
                        $firstCursor = $payload['meta']['nextCursor'];
                    }
                }
                $loaded = array_merge($loaded, array_column($payload['data'], 'slug'));
                $next = $payload['meta']['nextCursor'];
                self::assertLessThanOrEqual(3, count($loaded));
            } while ($next !== null);
            self::assertSame(array_map(static fn ($suffix) => 'campaign-filter-' . $suffix, $expected), $loaded);
        }
        foreach (['channels' => '["TikTok"]', 'location' => 'Sarajevo', 'currency' => 'EUR', 'budgetMin' => '300', 'budgetMax' => '400', 'sort' => 'newest'] as $key => $value) {
            $this->client->request('GET', '/api/campaigns?' . http_build_query(array_replace($filters + ['sort' => 'recommended'], [$key => $value, 'cursor' => $firstCursor])));
            self::assertResponseStatusCodeSame(400);
        }
        // JSON value matching must not mistake a substring for a channel name.
        $this->client->request('GET', '/api/campaigns?' . http_build_query(['channels' => '["Insta"]']));
        self::assertResponseIsSuccessful();
        self::assertSame(0, json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['meta']['total']);
    }

    public function testCampaignDirectoryRejectsInvalidEntityFilters(): void
    {
        foreach ([['categories' => '{}'], ['channels' => '[1]'], ['sort' => 'bad'], ['currency' => 'USD'], ['budgetMin' => '-1'], ['budgetMax' => '1.5'], ['budgetMax' => '10000001'], ['budgetMin' => '10'], ['budgetMin' => '500', 'budgetMax' => '200', 'currency' => 'BAM']] as $filters) {
            $this->client->request('GET', '/api/campaigns?' . http_build_query($filters));
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testCompanyFiltersUseVisibleCompanyFieldsAndPreserveCursorScope(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach (['Food' => 'Hrana', 'Travel' => 'Putovanja i turizam', 'Technology' => 'Tehnologija', 'Beauty' => 'Ljepota'] as $value => $label) {
            $em->persist(new MarketplaceCategory($value, ['bs' => $label, 'en' => $value], 0));
        }
        $travel = new Company('company-travel', 'Travel Brand', 'Travel', translations: ['bs' => ['industry' => 'Putovanja']], about: '<p>Thoughtful &amp; local</p>');
        $travel->setIndustries(['Travel', 'Technology']);
        $food = new Company('company-food', 'Food Brand', 'Food');
        $hidden = new Company('company-hidden', 'Hidden Brand', 'Private industry');
        $pending = new Company('company-pending', 'Pending Brand', 'Pending industry');
        foreach ([$travel, $food, $hidden, $pending] as $index => $company) {
            $owner = new User('company-filter-' . $index . '@example.test', 'ROLE_COMPANY');
            $owner->setPassword('unused');
            $owner->setApproved($company !== $pending);
            $owner->setHideMyAccount($company === $hidden);
            $owner->setCity($company === $travel ? 'Zenica' : 'Sarajevo');
            $owner->setCountryCode($company === $travel ? 'BA' : 'HR');
            $owner->setCompany($company);
            $em->persist($owner);
            $em->persist($company);
        }
        $em->flush();
        $this->client->request('GET', '/api/companies/filters?locale=bs');
        self::assertResponseIsSuccessful();
        $facets = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $options = array_column($facets['industries'], null, 'value');
        self::assertCount(4, $options);
        self::assertSame(1, $options['Food']['count']);
        self::assertSame(1, $options['Travel']['count']);
        self::assertSame(1, $options['Technology']['count']);
        self::assertSame(0, $options['Beauty']['count']);
        self::assertArrayNotHasKey('Private industry', $options);
        self::assertArrayNotHasKey('Pending industry', $options);
        self::assertSame('Putovanja i turizam', $options['Travel']['label']);
        $this->client->request('GET', '/api/companies?industries=' . urlencode('["Technology"]'));
        self::assertResponseIsSuccessful();
        self::assertSame(['Travel Brand'], array_column(json_decode($this->client->getResponse()->getContent(), true)['data'], 'name'));
        self::assertSame(['BA', 'HR'], array_column($facets['countries'], 'value'));
        self::assertSame([1, 1], array_column($facets['countries'], 'count'));

        $filters = http_build_query(['countries' => json_encode(['ba', 'HR', 'BA']), 'industries' => json_encode(['Food', 'Travel']), 'pagination' => 'cursor', 'view' => 'card', 'limit' => 1, 'sort' => 'name']);
        $this->client->request('GET', '/api/companies?' . $filters);
        self::assertResponseIsSuccessful();
        $first = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $first['meta']['total']);
        self::assertSame('Food Brand', $first['data'][0]['name']);
        $this->client->request('GET', '/api/companies?' . $filters . '&cursor=' . urlencode($first['meta']['nextCursor']));
        self::assertResponseIsSuccessful();
        $second = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Travel Brand', $second['data'][0]['name']);
        self::assertSame('Thoughtful & local', $second['data'][0]['summary']);
        self::assertArrayNotHasKey('about', $second['data'][0]);
        self::assertFalse($second['meta']['hasMore']);
        $this->client->request('GET', '/api/companies?' . $filters . '&cursor=' . urlencode($first['meta']['nextCursor']) . '&city=Zenica');
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/companies?industries=' . urlencode('[123]'));
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/companies?industries=' . urlencode('{"industry":"Travel"}'));
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/companies?' . $filters . '&cursor=' . urlencode($first['meta']['nextCursor']) . '&countries=' . urlencode('["BA"]'));
        self::assertResponseStatusCodeSame(400);
        foreach (['[123]', '{"country":"BA"}', '["Bosnia"]', '["BA\n"]'] as $invalidCountries) {
            $this->client->request('GET', '/api/companies?countries=' . urlencode($invalidCountries));
            self::assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', '/api/companies?countries=' . urlencode('["BA"]'));
        self::assertResponseIsSuccessful();
        self::assertSame(['Travel Brand'], array_column(json_decode($this->client->getResponse()->getContent(), true)['data'], 'name'));
        $this->client->request('GET', '/api/companies?country=BA&city=Zenica');
        self::assertResponseIsSuccessful();
        $result = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['Travel Brand'], array_column($result['data'], 'name'));
    }

    public function testCompanyCardUsesItsOwnCoverThumbnail(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $company = new Company('cover-brand', 'Cover Brand', 'Travel');
        $owner = new User('cover-brand@example.test', 'ROLE_COMPANY');
        $owner->setPassword('unused');
        $owner->setApproved(true);
        $owner->setCompany($company);
        $folder = new \App\Entity\MediaFolder('company-cover', 'Company covers');
        $media = new \App\Entity\Media($folder, $owner, 'cover.webp', 'cover.webp', 'image/webp', 100);
        $media->setDimensions(600, 400);
        foreach ([$company, $owner, $folder, $media] as $entity) {
            $em->persist($entity);
        }
        $active = new Campaign('active-cover', 'Active', 'Summary', 'Brief', 'Travel', ['Instagram'], ['Post'], 10, 20, 'Zenica', 1, new \DateTimeImmutable('+7 days'), new \DateTimeImmutable('today'), $company);
        $company->setCoverMedia($media);
        $em->persist($active);
        $em->flush();
        $this->client->request('GET', '/api/companies?view=card');
        self::assertResponseIsSuccessful();
        $cards = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame('/api/media/' . $media->getId() . '/thumbnail/v1/480', $cards[0]['coverImage']['src']);
        self::assertSame(480, $cards[0]['coverImage']['width']);
        self::assertSame(320, $cards[0]['coverImage']['height']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(Company::class)->findOneBy(['slug' => 'cover-brand'])->setCoverMedia(null);
        $em->flush();
        $this->client->request('GET', '/api/companies?view=card');
        self::assertResponseIsSuccessful();
        $cards = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertNull($cards[0]['coverImage']);
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
