<?php

namespace App\Tests\Controller;

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use DateTimeImmutable;
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
            createdAt: new DateTimeImmutable('2026-10-01'),
        );
        $latestCreator = new Creator(
            'latest-creator',
            'Latest Creator',
            'Beauty',
            'Zagreb',
            'Another creator profile.',
            [],
            createdAt: new DateTimeImmutable('2026-10-02'),
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
            new DateTimeImmutable('+10 days'),
            new DateTimeImmutable('today'),
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
        self::assertSame(
            ['latest-creator', 'featured-creator'],
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
            new DateTimeImmutable('+10 days'),
            new DateTimeImmutable('today'),
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
            '/api/admin/registrations/'.$creatorUser->getId().'/approve?locale=en',
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
        self::assertTrue($entityManager->getRepository(User::class)->find($creatorUser->getId())->isApproved());
        $this->client->request(
            'POST',
            '/api/admin/registrations/'.$creatorUser->getId().'/approve?locale=en',
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

    public function testBulkApprovalKeepsUnapprovedProfilesPrivateAndMovesApprovedProfilesIntoDirectories(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused-test-hash');
        $admin->setAdmin(true);

        $creatorUser = new User('creator-pending@example.test', 'ROLE_CREATOR');
        $creatorUser->setPassword('unused-test-hash');
        $creatorUser->setEmailVerified(true);
        $creatorUser->setApproved(false);
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
        $incompleteUser->setCreator(new Creator('incomplete-creator', 'Incomplete Creator', '', '', '', []));

        $unverifiedUser = new User('unverified@example.test', 'ROLE_CREATOR');
        $unverifiedUser->setPassword('unused-test-hash');
        $unverifiedUser->setEmailVerified(false);
        $unverifiedUser->setApproved(false);
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
            new DateTimeImmutable('+10 days'),
            new DateTimeImmutable('today'),
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
            '/api/admin/registrations/'.$incompleteUser->getId().'/approve?locale=en',
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
