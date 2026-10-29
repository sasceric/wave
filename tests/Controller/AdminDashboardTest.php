<?php

namespace App\Tests\Controller;

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class AdminDashboardTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    public function testCatalogPagesSearchSortAndRequireAdmin(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('paged-admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused');
        $admin->setAdmin(true);
        $entityManager->persist($admin);
        for ($index = 1; $index <= 61; ++$index) {
            $name = sprintf('Creator %03d', $index);
            $entityManager->persist(new Creator('admin-creator-' . $index, $name, 'Travel', 'Sarajevo', 'Bio', []));
        }
        $entityManager->flush();
        $this->client->request('GET', '/api/admin/catalog/creators');
        self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/api/admin/catalog/creators?page=2&limit=25&sort=displayName&direction=desc');
        self::assertResponseIsSuccessful();
        $result = $this->payload();
        self::assertCount(25, $result['data']);
        self::assertSame(61, $result['meta']['total']);
        self::assertSame('Creator 036', $result['data'][0]['displayName']);
        $ids = [];
        foreach ([1 => 20, 2 => 20, 3 => 20, 4 => 1] as $page => $count) {
            $this->client->request('GET', '/api/admin/catalog/creators?limit=20&page='.$page);
            self::assertResponseIsSuccessful();
            $batch = $this->payload();
            self::assertCount($count, $batch['data']);
            $ids = [...$ids, ...array_column($batch['data'], 'id')];
        }
        self::assertCount(61, array_unique($ids));
        $this->client->request('GET', '/api/admin/catalog/creators?q=Creator+061');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['meta']['total']);
        $this->client->request('GET', '/api/admin/catalog/creators?page=99');
        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->payload()['meta']['page']);
        self::assertCount(11, $this->payload()['data']);
        $this->client->request('GET', '/api/admin/catalog/creators?q=%25');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->payload()['meta']['total']);
        foreach (['limit=1000', 'sort=unknown', 'direction=invalid', 'page=-1'] as $invalid) {
            $this->client->request('GET', '/api/admin/catalog/creators?' . $invalid);
            self::assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', '/api/admin/dashboard?section=overview');
        self::assertResponseIsSuccessful();
        self::assertSame(61, $this->payload()['data']['metrics']['creators']);
        self::assertSame([], $this->payload()['data']['creators']);
        $this->client->request('GET', '/api/admin/dashboard');
        self::assertCount(25, $this->payload()['data']['creators']);
    }

    public function testCreationTimesDefaultToNewestFirstAndRemainSortableAndSearchableInAllFourLists(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('creation-admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused');
        $admin->setAdmin(true);
        $em->persist($admin);
        $owners = [];
        foreach (['old' => '2026-10-22 12:13:00', 'new' => '2026-10-23 12:13:00'] as $key => $time) {
            $em->persist(new Creator('timestamp-'.$key, 'Timestamp '.$key, 'Travel', 'Sarajevo', '', [], createdAt: new \DateTimeImmutable($time, new \DateTimeZone('UTC'))));
            $company = new User('timestamp-company-'.$key.'@example.test', 'ROLE_COMPANY');
            $company->setPassword('unused');
            $company->setCompany(new Company('timestamp-company-'.$key, 'Timestamp '.$key, 'Travel'));
            $em->persist(new Campaign('timestamp-campaign-'.$key, 'Timestamp '.$key, 'Summary', 'Description', 'Travel', ['Instagram'], ['Post'], 100, 200, 'Sarajevo', 1, new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable($time, new \DateTimeZone('UTC')), $company->getCompany()));
            $pending = new User('timestamp-pending-'.$key.'@example.test', 'ROLE_CREATOR');
            $pending->setPassword('unused');
            $pending->setApproved(false);
            $pending->setCreator(new Creator('timestamp-pending-'.$key, 'Timestamp '.$key, 'Travel', 'Sarajevo', '', []));
            $em->persist($company);
            $em->persist($pending);
            $owners[$time] = [$company, $pending];
        }
        $em->persist(new Company('legacy-company', 'Legacy company', 'Travel'));
        $em->flush();
        foreach ($owners as $time => $users) {
            foreach ($users as $user) {
                $em->getConnection()->executeStatement("UPDATE account_milestone SET occurred_at = ? WHERE user_id = ? AND stage = 'registered'", [$time, $user->getId()]);
            }
        }
        $this->client->loginUser($admin);
        foreach (['creators', 'companies', 'registrations', 'campaigns'] as $kind) {
            $this->client->request('GET', '/api/admin/catalog/'.$kind.'?q=Timestamp');
            self::assertResponseIsSuccessful();
            self::assertSame(['2026-10-23T12:13:00+00:00', '2026-10-22T12:13:00+00:00'], array_column($this->payload()['data'], 'createdAt'));
            foreach (['asc', 'desc'] as $direction) {
                $this->client->request('GET', '/api/admin/catalog/'.$kind.'?q=Timestamp&sort=createdAt&direction='.$direction);
                self::assertResponseIsSuccessful();
                $data = $this->payload()['data'];
                self::assertCount(2, $data);
                self::assertSame($direction === 'asc' ? ['2026-10-22T12:13:00+00:00', '2026-10-23T12:13:00+00:00'] : ['2026-10-23T12:13:00+00:00', '2026-10-22T12:13:00+00:00'], array_column($data, 'createdAt'));
            }
        }
        $this->client->request('GET', '/api/admin/catalog/companies?q=Legacy');
        self::assertResponseIsSuccessful();
        self::assertNull($this->payload()['data'][0]['createdAt']);
        $this->client->request('GET', '/api/admin/catalog/companies');
        self::assertResponseIsSuccessful();
        $data = $this->payload()['data'];
        self::assertNull($data[array_key_last($data)]['createdAt']);
    }

    public function testAdminCanHideCampaignListingsWithoutClosingOrRemovingDirectAccess(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('listing-admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused');
        $admin->setAdmin(true);
        $owner = new User('listing-owner@example.test', 'ROLE_COMPANY');
        $owner->setPassword('unused');
        $owner->setApproved(true);
        $owner->setEmailVerified(true);
        $owner->setHideMyAccount(false);
        $company = new Company('listing-brand', 'Listing Brand', 'Travel');
        $owner->setCompany($company);
        $folder = new \App\Entity\MediaFolder('campaign-cover', 'Campaign covers');
        $cover = new \App\Entity\Media($folder, $owner, 'cover.webp', 'campaign-listing-test.webp', 'image/webp', 123);
        $cover->setDimensions(600, 400);
        $old = new Campaign('listing-old', 'Listing old', 'Listing summary', 'Listing description', 'Travel', ['Instagram'], ['Post'], 100, 200, 'Sarajevo', 1, new \DateTimeImmutable('+1 year'), new \DateTimeImmutable('-2 days'), $company);
        $new = new Campaign('listing-new', 'Listing new', 'Listing summary', 'Listing description', 'Travel', ['Instagram'], ['Post'], 100, 200, 'Sarajevo', 1, new \DateTimeImmutable('+1 year'), new \DateTimeImmutable('-1 day'), $company, coverMedia: $cover, countryCode: 'BA');
        foreach ([$admin, $owner, $folder, $cover, $old, $new] as $item) $em->persist($item);
        $em->flush();
        $url = '/api/admin/catalog/campaigns/'.$new->getId().'/visibility?locale=en';
        $this->client->request('PUT', $url);
        self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($owner);
        $this->client->jsonRequest('PUT', $url, ['listed' => false]);
        self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($admin);
        $this->client->jsonRequest('PUT', $url, ['listed' => false]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/admin/catalog/campaigns?locale=en');
        self::assertResponseIsSuccessful();
        $rows = $this->payload()['data'];
        self::assertSame(['listing-new', 'listing-old'], array_column($rows, 'slug'));
        self::assertTrue($rows[0]['listed']);
        self::assertSame($cover->getUrl(), $rows[0]['coverImageUrl']);
        self::assertNotNull($rows[0]['coverImage']);
        self::assertNull($rows[1]['coverImageUrl']);
        self::assertNotEmpty($rows[0]['createdAt']);
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = ['HTTP_X_CSRF_TOKEN' => $this->payload()['csrfToken']];
        foreach (['false', 0, null] as $invalid) {
            $this->client->jsonRequest('PUT', $url, ['listed' => $invalid], $csrf);
            self::assertResponseStatusCodeSame(400);
        }
        $this->client->jsonRequest('PUT', '/api/admin/catalog/campaigns/999999/visibility?locale=en', ['listed' => false], $csrf);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('PUT', $url, ['listed' => false], $csrf);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['data']['listed']);
        $this->client->request('GET', '/api/admin/catalog/campaigns?locale=en');
        self::assertFalse($this->payload()['data'][0]['listed']);
        self::assertSame('open', $this->payload()['data'][0]['status']);
        $this->client->request('GET', '/api/campaigns?locale=en');
        self::assertSame(['listing-old'], array_column($this->payload()['data'], 'slug'));
        $this->client->request('GET', '/api/homepage?locale=en');
        self::assertSame([], array_column($this->payload()['data']['campaigns'], 'slug'));
        $this->client->request('GET', '/api/campaigns/filters?locale=en');
        self::assertSame([], $this->payload()['data']['countries']);
        $this->client->request('GET', '/api/campaigns/listing-new?locale=en');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['data']['listed']);
        self::assertSame('open', $this->payload()['data']['status']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $documents = self::getContainer()->get(\App\Search\SearchDocuments::class);
        self::assertSame([], $documents->build('campaign', $new->getId(), ['en' => []], 'en'));
        $search = self::getContainer()->get(\App\Search\DatabaseSearch::class)->search('Listing', 'en', 'campaigns', 1, false);
        self::assertSame(['listing-old'], array_column($search['data'], 'slug'));
        $alerts = self::getContainer()->get(\App\Account\CampaignAlertDelivery::class)->matches($admin->getId(), '2000-01-01', '2100-01-01', [], []);
        self::assertSame(['listing-old'], array_column($alerts, 'slug'));
        $xml = self::getContainer()->get(\App\Service\SitemapGenerator::class)->generate();
        self::assertStringNotContainsString('/listing-new', $xml);
        self::assertStringContainsString('/listing-old', $xml);
        $metadata = self::getContainer()->get(\App\Service\SeoMetadataProvider::class)->forPath('/kampanje/listing-new');
        self::assertTrue($metadata['noindex']);
        $this->client->loginUser($owner);
        $this->client->request('GET', '/api/me/campaigns?locale=en');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->payload()['data']);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/api/auth/csrf');
        $this->client->jsonRequest('PUT', $url, ['listed' => true], ['HTTP_X_CSRF_TOKEN' => $this->payload()['csrfToken']]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/campaigns?locale=en');
        self::assertCount(2, $this->payload()['data']);
    }

    public function testAdminCanCurateHomepageAndNonAdminCannotAccessDashboard(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused-test-hash');
        $admin->setAdmin(true);
        $regular = new User('creator@example.test', 'ROLE_CREATOR');
        $regular->setPassword('unused-test-hash');
        $featuredCreator = new Creator(
            'featured-creator',
            'Featured Creator',
            'Travel',
            'Sarajevo',
            'A creator profile.',
            [],
            createdAt: new \DateTimeImmutable('2026-10-01'),
        );
        $latestCreator = new Creator(
            'latest-creator',
            'Latest Creator',
            'Beauty',
            'Zagreb',
            'Another creator profile.',
            [],
            createdAt: new \DateTimeImmutable('2026-10-02'),
        );
        $company = new Company('sample-company', 'Sample Company', 'Travel');
        $campaign = new Campaign(
            'sample-campaign',
            'Sample campaign',
            'A campaign summary.',
            'A campaign description.',
            'Travel',
            ['Instagram'],
            ['A post'],
            100,
            500,
            'Croatia',
            2,
            new \DateTimeImmutable('+10 days'),
            new \DateTimeImmutable('today'),
            $company,
        );

        foreach ([$admin, $regular, $featuredCreator, $latestCreator, $company, $campaign] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        self::assertTrue($admin->hasRole('ROLE_ADMIN'));
        self::assertTrue($admin->hasRole('ROLE_MODERATOR'));

        $this->client->loginUser($regular, 'main');
        $this->client->request('GET', '/api/admin/dashboard?locale=en');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/api/admin/dashboard?locale=en');
        self::assertResponseIsSuccessful();
        $dashboard = $this->payload()['data'];
        self::assertSame(2, $dashboard['metrics']['users']);
        self::assertSame(2, $dashboard['metrics']['creators']);
        self::assertSame(1, $dashboard['metrics']['companies']);
        self::assertCount(1, $dashboard['companies']);
        self::assertSame('Sample Company', $dashboard['companies'][0]['name']);
        self::assertSame('latest', $dashboard['creatorMode']);

        $this->client->request('GET', '/api/homepage?locale=en');
        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(
            [],
            array_column($this->payload()['data']['creators'], 'slug'),
        );

        $csrfToken = $this->csrfToken();
        $this->client->request(
            'PUT',
            '/api/admin/homepage?locale=en',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $csrfToken,
            ],
            content: json_encode([
                'creatorMode' => 'featured',
                'featuredCreatorIds' => [$featuredCreator->getId()],
                'featuredCompanyIds' => [$company->getId()],
                'featuredCampaignIds' => [$campaign->getId()],
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/homepage?locale=en');
        self::assertResponseIsSuccessful();
        $homepage = $this->payload()['data'];
        self::assertSame('featured', $homepage['creatorMode']);
        self::assertSame(['featured-creator'], array_column($homepage['creators'], 'slug'));
        self::assertSame(['sample-campaign'], array_column($homepage['campaigns'], 'slug'));

        $this->client->request('GET', '/api/admin/dashboard?locale=en');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['data']['companies'][0]['featured']);

        $this->client->request('GET', '/api/companies?locale=en');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['data'][0]['featured']);
    }

    public function testAdminApprovalControlsRegistrationVisibilityAndCanBulkDeleteCampaigns(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused-test-hash');
        $admin->setAdmin(true);
        $creatorUser = new User('new-creator@example.test', 'ROLE_CREATOR');
        $creatorUser->setPassword('unused-test-hash');
        $creatorUser->setEmailVerified(true);
        $creatorUser->setApproved(false);
        $creatorUser->setHideMyAccount(true);
        $creatorUser->setPreferredLocale('en');
        $creatorUser->setPhone('+38761123456');
        $creatorUser->setCity('Sarajevo');
        $creatorUser->setCountryCode('BA');
        $creator = new Creator('new-creator', 'New Creator', 'Travel', 'Sarajevo', 'A new account.', []);
        $creatorUser->setCreator($creator);
        $company = new Company('bulk-company', 'Bulk Company', 'Food');
        $campaign = new Campaign(
            'bulk-campaign',
            'Bulk campaign',
            'A campaign summary.',
            'A campaign description.',
            'Food',
            ['Instagram'],
            ['A post'],
            100,
            500,
            'Bosnia and Herzegovina',
            1,
            new \DateTimeImmutable('+10 days'),
            new \DateTimeImmutable('today'),
            $company,
        );

        foreach ([$admin, $creatorUser, $company, $campaign] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        self::assertFalse($creatorUser->isApproved());

        $this->client->loginUser($creatorUser, 'main');
        $this->client->request(
            'POST',
            '/api/campaigns/not-a-campaign/applications?locale=en',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
            ],
            content: json_encode(['message' => 'This account is waiting for admin approval.'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/api/creators/new-creator?locale=en');
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/api/creators/new-creator?locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame('New Creator', $this->payload()['data']['displayName']);
        $this->client->request('GET', '/api/admin/dashboard?locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['data']['metrics']['pendingRegistrations']);
        self::assertSame('new-creator@example.test', $this->payload()['data']['registrations'][0]['email']);
        self::assertNotContains(
            'new-creator',
            array_column($this->payload()['data']['creators'], 'slug'),
        );

        $this->client->request(
            'POST',
            '/api/admin/registrations/' . $creatorUser->getId() . '/approve?locale=en',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
            ],
        );
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        $approvalEmail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $approvalEmail);
        self::assertEmailSubjectContains($approvalEmail, 'Your Wave account has been approved');
        self::assertEmailTextBodyContains($approvalEmail, 'now publicly visible');
        self::assertEmailTextBodyContains($approvalEmail, 'Install Wave as an app');
        self::assertEmailTextBodyContains($approvalEmail, 'Complete your profile');
        self::assertEmailHtmlBodyContains($approvalEmail, \App\Account\ApprovalWelcomeEmailCopy::IPHONE_URL);
        self::assertEmailHtmlBodyContains($approvalEmail, \App\Account\ApprovalWelcomeEmailCopy::ANDROID_URL);
        self::assertTrue($entityManager->getRepository(User::class)->find($creatorUser->getId())->isApproved());
        self::assertFalse($entityManager->getRepository(User::class)->find($creatorUser->getId())->isHideMyAccount());
        $this->client->request(
            'POST',
            '/api/admin/registrations/' . $creatorUser->getId() . '/approve?locale=en',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
            ],
        );
        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
        $this->client->request('GET', '/api/creators/new-creator?locale=en');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/admin/dashboard?locale=en');
        self::assertResponseIsSuccessful();
        self::assertNotContains(
            $creatorUser->getId(),
            array_column($this->payload()['data']['registrations'], 'id'),
        );
        self::assertContains(
            'new-creator',
            array_column($this->payload()['data']['creators'], 'slug'),
        );

        $this->client->request(
            'DELETE',
            '/api/admin/campaigns/bulk-delete?locale=en',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
            ],
            content: json_encode(['ids' => [$campaign->getId()]], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['data']['deleted']);
        self::assertNull($entityManager->getRepository(Campaign::class)->find($campaign->getId()));
    }

    public function testBulkApprovalMakesCreatorsAndCompaniesPublic(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused-test-hash');
        $admin->setAdmin(true);

        $creatorUser = new User('creator-pending@example.test', 'ROLE_CREATOR');
        $creatorUser->setPassword('unused-test-hash');
        $creatorUser->setEmailVerified(true);
        $creatorUser->setApproved(false);
        $creatorUser->setHideMyAccount(true);
        $creatorUser->setPreferredLocale('en');
        $creatorUser->setPhone('+38761123456');
        $creatorUser->setCity('Sarajevo');
        $creatorUser->setCountryCode('BA');
        $creator = new Creator('pending-creator', 'Pending Creator', 'Travel', 'Sarajevo', 'Private profile.', []);
        $creatorUser->setCreator($creator);

        $companyUser = new User('company-pending@example.test', 'ROLE_COMPANY');
        $companyUser->setPassword('unused-test-hash');
        $companyUser->setEmailVerified(true);
        $companyUser->setApproved(false);
        $companyUser->setHideMyAccount(true);
        $companyUser->setPreferredLocale('en');
        $companyUser->setPhone('+385911234567');
        $companyUser->setCity('Zagreb');
        $companyUser->setCountryCode('HR');
        $company = new Company('pending-company', 'Pending Company', 'Food');
        $companyUser->setCompany($company);

        $incompleteUser = new User('incomplete@example.test', 'ROLE_CREATOR');
        $incompleteUser->setPassword('unused-test-hash');
        $incompleteUser->setEmailVerified(true);
        $incompleteUser->setApproved(false);
        $incompleteUser->setHideMyAccount(true);
        $incompleteUser->setCreator(new Creator('incomplete-creator', 'Incomplete Creator', '', '', '', []));

        $unverifiedUser = new User('unverified@example.test', 'ROLE_CREATOR');
        $unverifiedUser->setPassword('unused-test-hash');
        $unverifiedUser->setEmailVerified(false);
        $unverifiedUser->setApproved(false);
        $unverifiedUser->setHideMyAccount(true);
        $unverifiedUser->setCreator(
            new Creator('unverified-creator', 'Unverified Creator', 'Travel', 'Mostar', 'Private profile.', []),
        );

        $campaign = new Campaign(
            'private-campaign',
            'Private campaign',
            'A campaign from an unapproved company.',
            'This campaign should not be visible yet.',
            'Food',
            ['Instagram'],
            ['A post'],
            100,
            500,
            'Bosnia and Herzegovina',
            1,
            new \DateTimeImmutable('+10 days'),
            new \DateTimeImmutable('today'),
            $company,
        );

        foreach ([$admin, $creatorUser, $companyUser, $incompleteUser, $unverifiedUser, $campaign] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $this->client->request('GET', '/api/creators?locale=en');
        self::assertResponseIsSuccessful();
        self::assertNotContains('pending-creator', array_column($this->payload()['data'], 'slug'));
        $this->client->request('GET', '/api/creators/pending-creator?locale=en');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/companies?locale=en');
        self::assertResponseIsSuccessful();
        self::assertNotContains('pending-company', array_column($this->payload()['data'], 'slug'));
        $this->client->request('GET', '/api/companies/pending-company?locale=en');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/campaigns?locale=en');
        self::assertResponseIsSuccessful();
        self::assertNotContains('private-campaign', array_column($this->payload()['data'], 'slug'));
        $this->client->request('GET', '/api/campaigns/private-campaign?locale=en');
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/api/creators/pending-creator?locale=en');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/companies/pending-company?locale=en');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/admin/dashboard?locale=en');
        self::assertResponseIsSuccessful();
        self::assertCount(4, $this->payload()['data']['registrations']);
        self::assertSame([], $this->payload()['data']['creators']);
        self::assertSame([], $this->payload()['data']['companies']);

        $this->client->request(
            'POST',
            '/api/admin/registrations/' . $incompleteUser->getId() . '/approve?locale=en',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
            ],
        );
        self::assertResponseStatusCodeSame(400);
        self::assertFalse($incompleteUser->isApproved());

        $this->client->request(
            'POST',
            '/api/admin/registrations/bulk-approve?locale=en',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
            ],
            content: json_encode([
                'ids' => [$creatorUser->getId(), $companyUser->getId(), $unverifiedUser->getId(), $incompleteUser->getId()],
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->payload()['data']['approved']);
        self::assertSame(1, $this->payload()['data']['skippedUnverified']);
        self::assertSame(1, $this->payload()['data']['skippedIncomplete']);
        self::assertEmailCount(2);
        foreach ([$creatorUser, $companyUser] as $approvedUser) {
            $this->client->loginUser($approvedUser, 'main');
            $this->client->request('GET', '/api/auth/me?locale=en');
            self::assertTrue($this->payload()['data']['approved']);
            self::assertSame(0, $this->payload()['data']['hide_my_account']);
        }
        $this->client->loginUser($admin, 'main');

        $this->client->request('GET', '/api/admin/dashboard?locale=en');
        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(
            [$unverifiedUser->getId(), $incompleteUser->getId()],
            array_column($this->payload()['data']['registrations'], 'id'),
        );
        self::assertContains('pending-creator', array_column($this->payload()['data']['creators'], 'slug'));
        self::assertContains('pending-company', array_column($this->payload()['data']['companies'], 'slug'));
        self::assertNotContains('incomplete-creator', array_column($this->payload()['data']['creators'], 'slug'));

        $this->client->request('GET', '/api/creators/pending-creator?locale=en');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/companies/pending-company?locale=en');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/campaigns/private-campaign?locale=en');
        self::assertResponseIsSuccessful();

        $this->client->getCookieJar()->clear();
        foreach (['/creators/pending-creator', '/companies/pending-company', '/campaigns/private-campaign'] as $path) {
            $this->client->request('GET', '/api'.$path.'?locale=en');
            self::assertResponseIsSuccessful();
        }

        foreach ([$creatorUser, $companyUser] as $approvedUser) {
            $this->client->loginUser($approvedUser, 'main');
            $this->client->jsonRequest('PUT', '/api/me/account-visibility?locale=en', ['hide_my_account' => 1], [
                'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
            ]);
            self::assertResponseIsSuccessful();
            self::assertSame(1, $this->payload()['data']['hide_my_account']);
        }

        $this->client->loginUser($admin, 'main');
        $this->client->jsonRequest('POST', '/api/admin/registrations/bulk-approve?locale=en', [
            'ids' => [$creatorUser->getId(), $companyUser->getId()],
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrfToken()]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->payload()['data']['approved']);
        self::assertSame(2, $this->payload()['data']['alreadyApproved']);
        self::assertEmailCount(0);

        $this->client->getCookieJar()->clear();
        foreach (['/creators/pending-creator', '/companies/pending-company', '/campaigns/private-campaign'] as $path) {
            $this->client->request('GET', '/api'.$path.'?locale=en');
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testSingleCompanyApprovalPublishesProfileAndPreservesLaterHiding(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('approval-admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused-test-hash');
        $admin->setAdmin(true);
        $owner = new User('approval-company@example.test', 'ROLE_COMPANY');
        $owner->setPassword('unused-test-hash');
        $owner->setApproved(false);
        $owner->setHideMyAccount(true);
        $owner->setEmailVerified(true);
        $owner->setPhone('+38761123456');
        $owner->setCity('Sarajevo');
        $owner->setCountryCode('BA');
        $owner->setCompany(new Company('approval-company', 'Approval Company', 'Food'));
        $entityManager->persist($admin);
        $entityManager->persist($owner);
        $entityManager->flush();

        $this->client->request('GET', '/api/companies/approval-company?locale=en');
        self::assertResponseStatusCodeSame(404);
        $this->client->loginUser($admin, 'main');
        $this->client->jsonRequest('POST', '/api/admin/registrations/'.$owner->getId().'/approve?locale=en', [], [
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
        ]);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/companies/approval-company?locale=en');
        self::assertResponseIsSuccessful();
        $this->client->loginUser($owner, 'main');
        $this->client->jsonRequest('PUT', '/api/me/account-visibility?locale=en', ['hide_my_account' => 1], [
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
        ]);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($admin, 'main');
        $this->client->jsonRequest('POST', '/api/admin/registrations/'.$owner->getId().'/approve?locale=en', [], [
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
        ]);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/companies/approval-company?locale=en');
        self::assertResponseStatusCodeSame(404);
    }

    private function csrfToken(): string
    {
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        self::assertResponseIsSuccessful();

        return $this->payload()['csrfToken'];
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
