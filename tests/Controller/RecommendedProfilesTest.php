<?php

namespace App\Tests\Controller;

use App\Account\ProfileStrength;
use App\Account\ProfileStrengthSql;
use App\Entity\{Application, Campaign, CampaignReview, Company, Creator, Offer, User};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\{Clock, MockClock, NativeClock};

final class RecommendedProfilesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private MockClock $clock;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-26 12:00:00 UTC');
        Clock::set($this->clock);
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testRecommendedUsesFeaturedThenCredibleRatingsCompletedWorkAndStrengthAcrossAllPages(): void
    {
        $brand = $this->brand('buyer');
        $featured = $this->creator('featured');
        $featured->setFeatured(true);
        $veteran = $this->creator('veteran');
        for ($i = 0; $i < 30; ++$i) $this->job($brand, $veteran, $i < 27 ? 5 : 4);
        $busy = $this->creator('busy');
        $this->job($brand, $busy, 5);
        $this->job($brand, $busy);
        $this->job($brand, $busy);
        $one = $this->creator('one-review');
        $this->job($brand, $one, 5);
        $complete = $this->creator('complete');
        $complete->setPortfolio([[], [], []]);
        $weak = $this->creator('weak');
        $expired = $this->creator('expired');
        $expired->setFeatured(true);
        $expired->setFeaturedUntil(new \DateTimeImmutable('2026-10-25 UTC'));
        $this->em->flush();
        $baseline = $this->get('/api/creators?sort=recommended&limit=20')['data'];
        self::assertSame(['featured', 'veteran', 'busy', 'one-review', 'complete'], array_slice(array_column($baseline, 'slug'), 0, 5));
        self::assertFalse($baseline[array_search('expired', array_column($baseline, 'slug'), true)]['featured']);
        self::assertSame(4.9, $baseline[1]['rating']['average']);
        self::assertSame(30, $baseline[1]['rating']['reviewCount']);
        self::assertSame(3, $baseline[2]['rating']['completedCampaigns']);
        self::assertNull($baseline[4]['rating']['average']);
        $homepage = $this->get('/api/homepage')['data']['creators'];
        self::assertSame(['featured'], array_column($homepage, 'slug'));
        $loaded = [];
        $cursor = null;
        do {
            $response = $this->get('/api/creators?sort=recommended&pagination=cursor&limit=2'.($cursor ? '&cursor='.urlencode($cursor) : ''));
            $loaded = [...$loaded, ...array_column($response['data'], 'slug')];
            $cursor = $response['meta']['nextCursor'];
        } while ($cursor);
        self::assertSame(array_column($baseline, 'slug'), $loaded);
        $filtered = $this->get('/api/creators?sort=recommended&q=complete')['data'];
        self::assertSame(['complete'], array_column($filtered, 'slug'));
    }

    public function testPositionsChooseTwelveHomepageCardsWhileEveryFeaturedItemRemainsInDirectories(): void
    {
        for ($i = 1; $i <= 15; ++$i) {
            $creator = $this->creator('ordered-creator-'.$i);
            $company = $this->brand('ordered-company-'.$i);
            $company->setIndustries(['Travel', 'Beauty', 'Fitness']);
            $campaign = new Campaign('ordered-campaign-'.$i, 'Campaign '.$i, 'Summary', 'Brief', 'Travel', ['Instagram'], ['Video'], 100, 200, 'Zenica', 1, new \DateTimeImmutable('+7 days'), new \DateTimeImmutable('today'), $company);
            foreach ([$creator, $company, $campaign] as $item) {
                $item->setFeatured(true);
                $item->setFeaturedPosition(16 - $i);
            }
            $this->em->persist($campaign);
        }
        $unpositioned = $this->creator('unpositioned');
        $unpositioned->setFeatured(true);
        $expired = $this->creator('expired-position');
        $expired->setFeatured(true);
        $expired->setFeaturedPosition(1);
        $expired->setFeaturedUntil(new \DateTimeImmutable('2026-10-25 UTC'));
        $hidden = $this->brand('hidden-position');
        $hidden->setFeatured(true);
        $hidden->setFeaturedPosition(1);
        $hidden->getOwner()->setHideMyAccount(true);
        $this->em->flush();
        $initial = $this->get('/api/homepage')['data'];
        self::assertFalse($initial['companiesSliderEnabled']);
        self::assertSame([], $initial['companies']);
        $settings = new \App\Entity\HomepageSettings();
        $settings->setCompaniesSliderEnabled(true);
        $this->em->persist($settings);
        $this->em->flush();
        $home = $this->get('/api/homepage')['data'];
        foreach (['creator', 'company', 'campaign'] as $kind) {
            $key = $kind === 'company' ? 'companies' : $kind.'s';
            $expected = array_map(static fn (int $i): string => 'ordered-'.$kind.'-'.$i, range(15, 1));
            self::assertSame(array_slice($expected, 0, 12), array_column($home[$key], 'slug'));
            $loaded = [];
            $cursor = null;
            do {
                $response = $this->get('/api/'.$key.'?sort=recommended&featured=true&pagination=cursor&limit=2'.($cursor ? '&cursor='.urlencode($cursor) : ''));
                $loaded = [...$loaded, ...array_column($response['data'], 'slug')];
                $cursor = $response['meta']['nextCursor'];
            } while ($cursor);
            if ($kind === 'creator') $expected[] = 'unpositioned';
            self::assertSame($expected, $loaded);
        }
        self::assertArrayNotHasKey('portfolio', $home['creators'][0]);
        self::assertArrayNotHasKey('description', $home['campaigns'][0]);
        self::assertArrayNotHasKey('businessDetails', $home['companies'][0]);
    }

    public function testRotationIsRepeatableAndCursorKeepsItsDayAcrossMidnight(): void
    {
        for ($i = 0; $i < 8; ++$i) $this->creator('equal-'.$i);
        $this->em->flush();
        $before = array_column($this->get('/api/creators?sort=recommended')['data'], 'id');
        self::assertSame($before, array_column($this->get('/api/creators?sort=recommended')['data'], 'id'));
        $first = $this->get('/api/creators?sort=recommended&pagination=cursor&limit=1');
        $loaded = array_column($first['data'], 'id');
        $cursor = $first['meta']['nextCursor'];
        $this->clock->modify('+1 day');
        while ($cursor) {
            $response = $this->get('/api/creators?sort=recommended&pagination=cursor&limit=1&cursor='.urlencode($cursor));
            $loaded = [...$loaded, ...array_column($response['data'], 'id')];
            $cursor = $response['meta']['nextCursor'];
        }
        self::assertSame($before, $loaded);
        self::assertNotSame($before, array_column($this->get('/api/creators?sort=recommended')['data'], 'id'));
    }

    public function testStrengthProjectionMatchesTheAccountChecklistForCreatorsBrandsAndAgencies(): void
    {
        $creator = $this->creator('strength');
        $creator->setPortfolio([[], [], []]);
        $creator->setPackages([['price' => '150']]);
        $creator->setFaqs([['question' => 'Q', 'answer' => 'Answer']]);
        $creator->updateProfile(displayName: 'strength', category: 'Travel', city: 'Zenica', bio: '<p>'.str_repeat('a', 150).'</p>',
            socialProfiles: [['handle' => 'wave', 'followers' => 100, 'engagementRate' => 3]], tags: [], avatarUrl: '/avatar.webp', tagline: '',
            portfolio: [[], [], []], packages: [['price' => '150']], categories: [], faqs: [['question' => 'Q', 'answer' => 'Answer']]);
        $brand = $this->brand('strength-brand');
        $brand->updateProfile('Brand', 'Travel', '/logo.webp', '<p>'.str_repeat('a', 80).'</p>');
        $brand->setSocialLinks([['platform' => 'Website', 'url' => 'https://wave.ba']]);
        $agency = $this->brand('strength-agency');
        $agency->setCompanyType('agency');
        $agency->setServices([['id' => 'service', 'title' => 'Video']]);
        $this->em->flush();
        $db = $this->em->getConnection();
        self::assertSame(100, ProfileStrength::forUser($creator->getOwner())['score']);
        $score = $db->fetchOne('SELECT '.ProfileStrengthSql::creator().' FROM creator c WHERE c.id = ?', [$creator->getId()]);
        self::assertSame(ProfileStrength::forUser($creator->getOwner())['score'], (int) $score);
        foreach ([$brand, $agency] as $company) {
            $score = $db->fetchOne('SELECT '.ProfileStrengthSql::company().' FROM company c LEFT JOIN creator studio ON studio.owner_id = c.owner_id AND studio.agency_profile = TRUE WHERE c.id = ?', [$company->getId()]);
            self::assertSame(ProfileStrength::forUser($company->getOwner())['score'], (int) $score);
        }
    }

    public function testUnpublishedReviewsDoNotAffectRankingOrDisplayedRatings(): void
    {
        $brand = $this->brand('private-buyer');
        $private = $this->creator('private-review');
        $public = $this->creator('public-review');
        $this->job($brand, $private, 5, false);
        $this->job($brand, $public, 4);
        $this->em->flush();
        $rows = $this->get('/api/creators?sort=recommended')['data'];
        self::assertSame(['public-review', 'private-review'], array_column($rows, 'slug'));
        self::assertNull($rows[1]['rating']['average']);
    }

    public function testAgenciesRankByProviderFeedbackAndBrandsByTheirClientFeedback(): void
    {
        $buyer = $this->brand('buyer');
        $agency = $this->brand('agency');
        $agency->setCompanyType('agency');
        $studio = new Creator('agency-studio', 'Studio', 'Travel', 'Zenica', '', []);
        $studio->markAgencyProfile();
        $agency->getOwner()->setCreator($studio);
        $this->em->persist($studio);
        $this->job($buyer, $studio, 4);
        $provider = $this->creator('provider');
        $this->job($agency, $provider, 5);
        $this->em->flush();
        // Add client-side feedback to the agency's own campaign; it must not inflate its provider rating.
        $application = $this->em->getRepository(Application::class)->findOneBy(['creator' => $provider]);
        $this->em->persist(new CampaignReview($application, $provider->getOwner(), $agency->getOwner(), 5, 'As a client'));
        $application->getCampaign()->setStatus('finished');
        $this->em->flush();
        $rows = $this->get('/api/companies?sort=recommended')['data'];
        $agencyRow = $rows[array_search('agency', array_column($rows, 'slug'), true)];
        self::assertSame(4, $agencyRow['rating']['average']);
        self::assertSame(1, $agencyRow['rating']['reviewCount']);
        self::assertSame(1, $agencyRow['rating']['completedCampaigns']);
        // Completing a provider's work alone does not count an unfinished campaign as a finished brand campaign.
        $buyerRow = $rows[array_search('buyer', array_column($rows, 'slug'), true)];
        self::assertSame(0, $buyerRow['rating']['completedCampaigns']);
        self::assertSame(['provider'], array_column($this->get('/api/creators?sort=recommended')['data'], 'slug'));
    }

    public function testFeaturedExpiryIsValidatedSavedAndAutomaticallyExpiresWithoutAWorker(): void
    {
        $creator = $this->creator('timed');
        $company = $this->brand('timed-brand');
        $admin = new User('ranking-admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused');
        $admin->setAdmin(true);
        $this->em->persist($admin);
        $this->em->flush();
        $creatorId = $creator->getId();
        $companyId = $company->getId();
        $this->client->loginUser($admin);
        $body = ['creatorMode' => 'featured', 'featuredCreatorIds' => [$creatorId], 'featuredCompanyIds' => [$companyId], 'featuredCampaignIds' => [],
            'featuredExpirations' => ['creators' => [$creatorId => '2026-10-26'], 'companies' => [$companyId => '2026-10-27']]];
        $this->client->jsonRequest('PUT', '/api/admin/homepage', $body);
        self::assertResponseStatusCodeSame(403);
        $token = $this->get('/api/auth/csrf')['csrfToken'];
        $bad = $body;
        $bad['featuredExpirations']['creators'][$creatorId] = '2026-02-30';
        $this->client->jsonRequest('PUT', '/api/admin/homepage', $bad, ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(400);
        foreach ([0, -1, 1.5, '2', true, 1000001] as $invalidPosition) {
            $bad = $body;
            $bad['featuredPositions'] = ['creators' => [$creatorId => $invalidPosition]];
            $this->client->jsonRequest('PUT', '/api/admin/homepage', $bad, ['HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseStatusCodeSame(400);
        }
        foreach (['true', 1, null] as $invalidToggle) {
            $bad = $body;
            $bad['companiesSliderEnabled'] = $invalidToggle;
            $this->client->jsonRequest('PUT', '/api/admin/homepage', $bad, ['HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseStatusCodeSame(400);
        }
        $body['companiesSliderEnabled'] = true;
        $body['featuredPositions'] = ['creators' => [$creatorId => 2], 'companies' => [$companyId => 1]];
        $this->client->jsonRequest('PUT', '/api/admin/homepage', $body, ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->get('/api/creators?sort=recommended')['data'][0]['featured']);
        $saved = $this->get('/api/admin/dashboard?section=homepage')['data'];
        self::assertSame('2026-10-26', $saved['creators'][0]['featuredUntil']);
        self::assertTrue($saved['companiesSliderEnabled']);
        self::assertTrue($this->get('/api/homepage')['data']['companiesSliderEnabled']);
        self::assertSame(2, $saved['creators'][0]['featuredPosition']);
        self::assertSame(1, $saved['companies'][0]['featuredPosition']);
        $body['companiesSliderEnabled'] = false;
        $this->client->jsonRequest('PUT', '/api/admin/homepage', $body, ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsSuccessful();
        $hiddenCompanies = $this->get('/api/homepage')['data'];
        self::assertFalse($hiddenCompanies['companiesSliderEnabled']);
        self::assertSame([], $hiddenCompanies['companies']);
        self::assertTrue($this->get('/api/companies?featured=true')['data'][0]['featured']);
        $this->clock->modify('+1 day');
        self::assertFalse($this->get('/api/creators?sort=recommended')['data'][0]['featured']);
        self::assertSame([], $this->get('/api/homepage')['data']['creators']);
        self::assertTrue($this->get('/api/companies?sort=recommended')['data'][0]['featured']);
        $this->clock->modify('+1 day');
        self::assertFalse($this->get('/api/companies?sort=recommended')['data'][0]['featured']);
        // Expired picks remain available to the administrator for renewal or removal.
        self::assertTrue($this->get('/api/admin/dashboard?section=homepage')['data']['creators'][0]['featured']);
    }

    private function creator(string $slug): Creator
    {
        $user = new User($slug.'@example.test', 'ROLE_CREATOR');
        $user->setPassword('unused');
        $user->setApproved(true);
        $creator = new Creator($slug, $slug, 'Travel', 'Zenica', '', []);
        $user->setCreator($creator);
        $this->em->persist($user);
        return $creator;
    }

    private function brand(string $slug): Company
    {
        $user = new User($slug.'@example.test', 'ROLE_COMPANY');
        $user->setPassword('unused');
        $user->setApproved(true);
        $company = new Company($slug, $slug, 'Travel');
        $user->setCompany($company);
        $this->em->persist($user);
        return $company;
    }

    private function job(Company $brand, Creator $creator, ?int $rating = null, bool $published = true): void
    {
        $campaign = new Campaign('ranking-job-'.++$this->sequence, 'Ranking job', 'Summary', 'Brief', 'Travel', ['Instagram'], ['Video'], 100, 200, 'Zenica', 1, new \DateTimeImmutable('+7 days'), new \DateTimeImmutable('today'), $brand);
        $application = new Application($campaign, $creator, 'Application');
        $offer = new Offer($application, 100, 'Agreed');
        $offer->respond('accepted');
        $offer->completeWork();
        (new \ReflectionProperty(Offer::class, 'workCompletedAt'))->setValue($offer, new \DateTimeImmutable($published ? '2026-09-01 UTC' : '2026-10-26 11:00:00 UTC'));
        foreach ([$campaign, $application, $offer] as $entity) $this->em->persist($entity);
        if ($rating !== null) $this->em->persist(new CampaignReview($application, $brand->getOwner(), $creator->getOwner(), $rating, 'Feedback'));
    }

    private function get(string $path): array
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
