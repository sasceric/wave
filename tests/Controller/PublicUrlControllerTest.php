<?php

namespace App\Tests\Controller;

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use App\Localization\LocalizedRouteMap;
use App\Service\PublicUrlSlugs;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicUrlControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    protected function setUp(): void
    {
        $this->client = self::createClient(); $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($this->em); $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata); $schema->createSchema($metadata);
        $this->user = new User('url-owner@example.test', 'ROLE_CREATOR'); $this->user->setPassword('unused');
        $this->user->setCreator(new Creator('old-url', 'Amina Kovač', 'Travel', 'Sarajevo', '', []));
        $this->em->persist($this->user); $this->em->flush();
    }
    public function testNumericAllocationReservesHistoryAndDoesNotConsumeExistingHoles(): void
    {
        $slugs = self::getContainer()->get(PublicUrlSlugs::class);
        self::assertSame('amina-kovac', $slugs->reserve('creator', 'Amina Kovač'));
        $slugs->remember('creator', 'amina-kovac-2');
        self::assertSame('amina-kovac-1', $slugs->reserve('creator', 'Amina Kovač'));
        self::assertSame('amina-kovac-3', $slugs->reserve('creator', 'Amina Kovač'));
        self::assertSame('amina-kovac', $slugs->reserve('company', 'Amina Kovač'));
    }
    public function testFirstChangeIsImmediateAndLaterChangeRequiresFourteenDaysAndCollapsesHistory(): void
    {
        $this->client->request('GET', '/api/me/public-url'); self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($this->user);
        $this->client->jsonRequest('PUT', '/api/me/public-url', ['slug' => 'new-url']); self::assertResponseStatusCodeSame(403);
        $this->write('/api/me/public-url', ['slug' => '../bad']); self::assertResponseStatusCodeSame(400);
        $this->write('/api/me/public-url', ['slug' => 'new-url']); self::assertResponseIsSuccessful();
        self::assertSame('new-url', $this->payload()['data']['slug']);
        self::assertNotNull($this->payload()['data']['availableAt']);
        self::assertStringEndsWith('/kreatori/new-url', $this->payload()['data']['url']);
        $this->client->request('GET', '/en/creators/old-url'); self::assertResponseRedirects('/kreatori/new-url', 301);
        $this->write('/api/me/public-url', ['slug' => 'third-url']); self::assertResponseStatusCodeSame(409);
        self::assertSame('new-url', $this->em->getConnection()->fetchOne('SELECT slug FROM creator'));
        $this->em->getConnection()->executeStatement("UPDATE creator SET public_url_changed_at = ?", [(new \DateTimeImmutable('-14 days -1 minute'))->format('Y-m-d H:i:s')]);
        $this->write('/api/me/public-url', ['slug' => 'third-url']); self::assertResponseIsSuccessful();
        foreach (['/kreatori/old-url', '/en/creators/old-url', '/kreatori/new-url'] as $path) {
            $this->client->request('GET', $path); self::assertResponseRedirects('/kreatori/third-url', 301);
            self::assertSame('/kreatori/third-url', $this->em->getConnection()->fetchOne('SELECT target_path FROM url_redirect WHERE source_path = ?', [$path]));
        }
        self::assertSame('old-url-1', self::getContainer()->get(PublicUrlSlugs::class)->reserve('creator', 'old-url'));
    }
    public function testCampaignUrlRequiresOwnerAndSharedRoutesKeepLocalizedDirectories(): void
    {
        $companyUser = new User('company-url@example.test', 'ROLE_COMPANY'); $companyUser->setPassword('unused');
        $company = new Company('company-url', 'Company', 'Travel'); $companyUser->setCompany($company);
        $campaign = new Campaign('campaign-url', 'Campaign', 'Summary', 'Description', 'Travel', ['Instagram'], ['Post'], 100, 300, 'Sarajevo', 1, new \DateTimeImmutable('+1 month'), new \DateTimeImmutable(), $company);
        $this->em->persist($companyUser); $this->em->persist($campaign); $this->em->flush();
        $endpoint = '/api/me/campaigns/'.$campaign->getId().'/public-url';
        $this->client->loginUser($this->user); $this->write($endpoint, ['slug' => 'my-campaign']); self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($companyUser); $this->write($endpoint, ['slug' => 'my-campaign']); self::assertResponseIsSuccessful();
        self::assertSame('/kampanje/my-campaign', $this->payload()['data']['path']);
        $routes = self::getContainer()->get(LocalizedRouteMap::class);
        foreach ($routes->locales() as $locale) {
            self::assertSame('/kreatori/amina', $routes->localizedPath('creator-profile', $locale, ['slug' => 'amina']));
            self::assertSame('/kompanije/agency', $routes->localizedPath('company-profile', $locale, ['slug' => 'agency']));
            self::assertSame('/kampanje/brief', $routes->localizedPath('campaign-detail', $locale, ['slug' => 'brief']));
        }
        self::assertSame('/en/creators', $routes->localizedPath('creators', 'en'));
        self::assertSame([], $routes->localizedAlternates('creator-profile', ['slug' => 'amina']));
    }
    private function write(string $path, array $body): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = $this->payload()['csrfToken'];
        $this->client->jsonRequest('PUT', $path, $body, ['HTTP_X_CSRF_TOKEN' => $csrf]);
    }
    private function payload(): array { return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }
}
