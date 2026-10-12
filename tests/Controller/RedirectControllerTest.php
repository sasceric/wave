<?php

namespace App\Tests\Controller;

use App\Account\AccountDeletion;
use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\UrlRedirect;
use App\Entity\User;
use App\Localization\LocalizedRouteMap;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RedirectControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    public function testAdminAccessCsrfEditingQueryTransferAndDisabling(): void
    {
        $this->client->request('GET', '/api/admin/redirects?locale=en');
        self::assertResponseStatusCodeSame(401);
        $user = new User('redirect-admin@example.test', 'ROLE_CREATOR');
        $user->setPassword('unused-test-hash');
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user, 'main');
        $this->client->request('GET', '/api/admin/redirects?locale=en');
        self::assertResponseStatusCodeSame(403);
        $admin = new User('admin@example.test', 'ROLE_CREATOR');
        $admin->setPassword('unused-test-hash');
        $admin->setAdmin(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $csrf = $this->payload()['csrfToken'];
        $this->write('POST', '', ['sourcePath' => '/old-page', 'targetPath' => '/en/creators', 'enabled' => true, 'preserveQuery' => false]);
        self::assertResponseStatusCodeSame(403);
        $this->write('POST', '', ['sourcePath' => '/old-page', 'targetPath' => '/en/creators', 'enabled' => true, 'preserveQuery' => false], $csrf);
        self::assertResponseStatusCodeSame(201);
        $row = $this->payload()['data'];
        $this->client->request('GET', '/old-page?utm_source=poster');
        self::assertResponseRedirects('/en/creators', 301);
        $this->client->request('HEAD', '/old-page');
        self::assertResponseRedirects('/en/creators', 301);
        $this->write('PUT', '/'.$row['id'], $row, $csrf);
        self::assertResponseIsSuccessful();
        $row['targetPath'] = '/en/companies?category=beauty';
        $row['preserveQuery'] = true;
        $this->write('PUT', '/'.$row['id'], $row, $csrf);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/old-page?utm_source=poster&tag=a&tag=b');
        self::assertResponseRedirects('/en/companies?category=beauty&utm_source=poster&tag=a&tag=b', 301);
        $this->client->request('GET', '/api/redirects/resolve?path='.urlencode('/old-page?utm_source=poster'));
        self::assertSame('/en/companies?category=beauty&utm_source=poster', $this->payload()['data']['targetPath']);
        $this->client->request('GET', '/api/admin/redirects?locale=en&search=old-page&pageSize=25');
        self::assertSame(1, $this->payload()['meta']['total']);
        $row['enabled'] = false;
        $this->write('PUT', '/'.$row['id'], $row, $csrf);
        $this->client->request('GET', '/old-page');
        self::assertResponseStatusCodeSame(404);
        $this->write('DELETE', '/'.$row['id'], [], $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->em->getRepository(UrlRedirect::class)->count([]));
    }

    public function testColumnSortingAndBulkDeletionAreAdminOnlyAndAtomic(): void
    {
        $this->write('DELETE', '/bulk-delete', ['ids' => [1]]);
        self::assertResponseStatusCodeSame(401);
        $admin = new User('bulk-admin@example.test', 'ROLE_CREATOR');
        $admin->setPassword('unused');
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');
        $this->write('DELETE', '/bulk-delete', ['ids' => [1]]);
        self::assertResponseStatusCodeSame(403);
        $admin = new User('bulk-admin-verified@example.test', 'ROLE_CREATOR');
        $admin->setPassword('unused');
        $admin->setAdmin(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');
        $records = [];
        for ($i = 26; $i >= 0; --$i) {
            $source = '/old-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $row = new UrlRedirect($source, $i % 2 === 0 ? '/kreatori' : '/kompanije', $i % 2 === 0 ? 'manual' : 'deleted-company');
            $row->update($source, $row->getTargetPath(), $i % 2 === 0, false);
            $this->em->persist($row);
            $records[] = $row;
        }
        $this->em->flush();
        $this->client->request('GET', '/api/admin/redirects?locale=en&sort=sourcePath&direction=asc&page=2&limit=25');
        self::assertResponseIsSuccessful();
        self::assertSame(['/old-25', '/old-26'], array_column($this->payload()['data'], 'sourcePath'));
        foreach (['sourcePath', 'targetPath', 'status', 'origin', 'updatedAt'] as $sort) {
            $this->client->request('GET', '/api/admin/redirects?locale=en&limit=100&sort='.$sort.'&direction=desc');
            self::assertResponseIsSuccessful();
            $column = array_column($this->payload()['data'], $sort === 'status' ? 'enabled' : $sort);
            $expected = $column;
            rsort($expected);
            self::assertSame($expected, $column);
        }
        foreach (['sort=sourcePath%20DESC', 'direction=invalid', 'sort=actions'] as $query) {
            $this->client->request('GET', '/api/admin/redirects?locale=en&'.$query);
            self::assertResponseStatusCodeSame(400);
        }
        $ids = [$records[0]->getId(), $records[1]->getId()];
        $this->write('DELETE', '/bulk-delete', ['ids' => $ids]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $csrf = $this->payload()['csrfToken'];
        foreach ([[], ['1'], [0], [-1], ['bad' => 1], array_fill(0, 1001, 1)] as $invalid) {
            $this->write('DELETE', '/bulk-delete', ['ids' => $invalid], $csrf);
            self::assertResponseStatusCodeSame(400);
        }
        $this->write('DELETE', '/bulk-delete', ['ids' => [$ids[0], 999999]], $csrf);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(27, $this->em->getRepository(UrlRedirect::class)->count([]));
        $this->write('DELETE', '/bulk-delete', ['ids' => [...$ids, $ids[0]]], $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->payload()['data']['deleted']);
        self::assertSame(25, $this->em->getRepository(UrlRedirect::class)->count([]));
        $this->client->request('GET', '/old-26');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/old-24');
        self::assertResponseRedirects('/kreatori', 301);
    }

    public function testInvalidSourcesTargetsDuplicateAliasesAndCyclesAreRejected(): void
    {
        $user = new User('redirect-admin@example.test', 'ROLE_CREATOR');
        $user->setPassword('unused-test-hash');
        $user->setAdmin(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user, 'main');
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $csrf = $this->payload()['csrfToken'];
        foreach (['//example.com/x', '/api/auth/me', '/admin', '/en/admin/unknown', '/racun?mode=login', '/hr/racun', '/q/token', '/%2e%2e/kreatori', '/old%2fpage', '/bad%0apath', '/bad\\path'] as $source) {
            $this->write('POST', '', ['sourcePath' => $source, 'targetPath' => '/kreatori', 'enabled' => true, 'preserveQuery' => false], $csrf);
            self::assertResponseStatusCodeSame(400);
        }
        foreach (['https://example.com', '//example.com/x', '/api/auth/me', '/racun', '/old-page', '/kreatori#fragment', "/kreatori?x=\r\nBad"] as $target) {
            $this->write('POST', '', ['sourcePath' => '/old-page', 'targetPath' => $target, 'enabled' => true, 'preserveQuery' => false], $csrf);
            self::assertResponseStatusCodeSame(400);
        }
        $this->write('POST', '', ['sourcePath' => '/creators/old', 'targetPath' => '/middle', 'enabled' => true, 'preserveQuery' => false], $csrf);
        self::assertResponseStatusCodeSame(201);
        $this->write('POST', '', ['sourcePath' => '/kreatori/old/', 'targetPath' => '/kampanje', 'enabled' => true, 'preserveQuery' => false], $csrf);
        self::assertResponseStatusCodeSame(400);
        $this->write('POST', '', ['sourcePath' => '/middle', 'targetPath' => '/kreatori/old', 'enabled' => true, 'preserveQuery' => false], $csrf);
        self::assertResponseStatusCodeSame(400);
        $this->write('POST', '', ['sourcePath' => '/middle', 'targetPath' => '/kompanije', 'enabled' => true, 'preserveQuery' => false], $csrf);
        self::assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/creators/old');
        self::assertResponseRedirects('/kompanije', 301);
    }

    public function testDeletionRegistersAllLocalesBeforeAnonymizationAndLeavesPrivateHistoryIntact(): void
    {
        $owner = new User('company@example.test', 'ROLE_COMPANY');
        $owner->setPassword('unused');
        $company = new Company('old-brand', 'Brand', 'Beauty');
        $owner->setCompany($company);
        $creatorOwner = new User('creator@example.test', 'ROLE_CREATOR');
        $creatorOwner->setPassword('unused');
        $creator = new Creator('old-creator', 'Creator', 'Beauty', 'Sarajevo', 'Portfolio', []);
        $creatorOwner->setCreator($creator);
        $campaign = new Campaign('old-campaign', 'Campaign', 'Summary', 'Description', 'Beauty', [], [], 100, 200, 'Sarajevo', 1, new \DateTimeImmutable('+20 days'), new \DateTimeImmutable(), $company);
        $application = new \App\Entity\Application($campaign, $creator, 'Application history');
        foreach ([$owner, $company, $creatorOwner, $creator, $campaign, $application] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $campaignId = $campaign->getId();
        static::getContainer()->get(AccountDeletion::class)->delete([$owner, $creatorOwner]);
        self::assertSame(18, $this->em->getRepository(UrlRedirect::class)->count([]));
        self::assertNotNull($this->em->find(Campaign::class, $campaignId));
        self::assertSame(1, $this->em->getRepository(\App\Entity\Application::class)->count([]));
        $routes = static::getContainer()->get(LocalizedRouteMap::class);
        foreach ($routes->locales() as $locale) {
            foreach ([['creator-profile', 'creators', 'old-creator'], ['company-profile', 'companies', 'old-brand'], ['campaign-detail', 'campaigns', 'old-campaign']] as [$detail, $listing, $slug]) {
                $this->client->request('GET', $routes->localizedPath($detail, $locale, ['slug' => $slug]).'?utm_source=old');
                self::assertResponseRedirects($routes->localizedPath($listing, $locale), 301);
            }
        }
        $this->client->request('GET', '/sr/kreatori/old-creator');
        self::assertResponseRedirects('/rs/kreatori', 301);
        $this->client->request('GET', '/api/creators/old-creator?locale=en');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/sitemap.xml');
        self::assertStringNotContainsString('old-campaign', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('old-creator', $this->client->getResponse()->getContent());
    }

    public function testAdminCampaignDeletionKeepsPermanentUrls(): void
    {
        $admin = new User('admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused');
        $admin->setAdmin(true);
        $company = new Company('brand', 'Brand', 'Beauty');
        $admin->setCompany($company);
        $campaign = new Campaign('removed-campaign', 'Campaign', 'Summary', 'Description', 'Beauty', [], [], 100, 200, 'Sarajevo', 1, new \DateTimeImmutable('+20 days'), new \DateTimeImmutable(), $company);
        foreach ([$admin, $company, $campaign] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $this->client->jsonRequest('DELETE', '/api/admin/campaigns/bulk-delete?locale=en', ['ids' => [$campaign->getId()]], ['HTTP_X_CSRF_TOKEN' => $this->payload()['csrfToken']]);
        self::assertResponseIsSuccessful();
        self::assertSame(6, $this->em->getRepository(UrlRedirect::class)->count([]));
        $this->client->request('GET', '/kampanje/removed-campaign');
        self::assertResponseRedirects('/kampanje', 301);
    }

    public function testAutomaticDeletionCannotIntroduceACycleThroughAnExistingAdminRule(): void
    {
        $owner = new User('owner@example.test', 'ROLE_CREATOR');
        $owner->setPassword('unused');
        $creator = new Creator('old', 'Creator', 'Beauty', 'Sarajevo', 'Portfolio', []);
        $owner->setCreator($creator);
        $this->em->persist($owner);
        $this->em->persist(new UrlRedirect('/kreatori', '/kreatori/old'));
        $this->em->flush();
        static::getContainer()->get(AccountDeletion::class)->delete([$owner]);
        $row = $this->em->getRepository(UrlRedirect::class)->findOneBy(['sourcePath' => '/kreatori/old']);
        self::assertFalse($row->isEnabled());
        self::assertTrue($owner->isDeleted());
        $this->client->request('GET', '/kreatori/old');
        self::assertResponseStatusCodeSame(404);
    }

    private function write(string $method, string $suffix, array $data, string $csrf = ''): void
    {
        $this->client->jsonRequest($method, '/api/admin/redirects'.$suffix.'?locale=en', $data, ['HTTP_X_CSRF_TOKEN' => $csrf]);
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
