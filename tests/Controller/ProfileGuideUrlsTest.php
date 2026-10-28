<?php

namespace App\Tests\Controller;

use App\Entity\UrlRedirect;
use App\Entity\User;
use App\Localization\LocalizedRouteMap;
use App\Service\SitemapGenerator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfileGuideUrlsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        require_once dirname(__DIR__, 2).'/migrations/Version20261028100000.php';
    }

    private function migrate(bool $up = true): void
    {
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $migration = new \DoctrineMigrations\Version20261028100000($db, new NullLogger());
        if ($up) $migration->up(new Schema());
        else $migration->down(new Schema());
        foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
    }

    public function testLegacyQueriesRedirectToPublicGuidesWithAudienceSpecificMetadataInEveryLanguage(): void
    {
        $this->migrate();
        $routes = static::getContainer()->get(LocalizedRouteMap::class);
        foreach ($routes->locales() as $locale) {
            foreach (['creator', 'agency', 'brand'] as $kind) {
                $old = $routes->localizedPath('account-profile-guide', $locale);
                $target = $routes->localizedPath('profile-guide-'.$kind, $locale);
                $this->client->request('GET', $old.'?utm_source=email&type='.$kind);
                self::assertResponseRedirects($target, 301);
                $this->client->request('HEAD', $old.'?type='.$kind);
                self::assertResponseRedirects($target, 301);
                $this->client->request('GET', '/api/redirects/resolve?'.http_build_query(['path' => $old.'?type='.$kind]));
                self::assertSame($target, $this->payload()['data']['targetPath']);
                $crawler = $this->client->request('GET', $target);
                self::assertResponseIsSuccessful();
                self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
                self::assertStringEndsWith($target, $crawler->filter('link[rel="canonical"]')->attr('href'));
                self::assertStringNotContainsString('?', $crawler->filter('link[rel="canonical"]')->attr('href'));
                self::assertNotSame('', $crawler->filter('#app h1')->text());
                self::assertSame(5, $crawler->filter('#app li')->count());
                self::assertSame(8, $crawler->filter('link[rel="alternate"][hreflang]')->count());
            }
        }
        foreach (['/sr/nalog/vodic-za-profil' => '/rs/nalog/vodic-za-profil/agencija', '/cnr/nalog/vodic-za-profil' => '/me/nalog/vodic-za-profil/agencija', '/sl/racun/vodnik-za-profil' => '/si/racun/vodnik-za-profil/agencija'] as $old => $target) {
            $this->client->request('GET', $old.'?type=agency');
            self::assertResponseRedirects($target, 301);
        }
        $this->client->request('GET', '/racun/vodic-za-profil');
        self::assertResponseRedirects('/racun/vodic-za-profil/kreator', 301);
        $this->client->request('GET', '/racun/vodic-za-profil?type[]=agency');
        self::assertResponseRedirects('/racun/vodic-za-profil/kreator', 301);
    }

    public function testMigrationIsRepeatablePreservesOverridesAndRollsBackOnlyUnchangedGeneratedRules(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $existing = new UrlRedirect('/racun/vodic-za-profil?type=agency', '/kompanije');
        $existing->update($existing->getSourcePath(), '/kompanije', false, true);
        $em->persist($existing);
        $em->flush();
        $this->migrate();
        $this->migrate();
        $db = $em->getConnection();
        self::assertSame(28, (int) $db->fetchOne('SELECT COUNT(*) FROM url_redirect'));
        self::assertSame('/kompanije', $db->fetchOne('SELECT target_path FROM url_redirect WHERE source_path = ?', [$existing->getSourcePath()]));
        self::assertFalse((bool) $db->fetchOne('SELECT enabled FROM url_redirect WHERE source_path = ?', [$existing->getSourcePath()]));
        $db->executeStatement("UPDATE url_redirect SET enabled = FALSE, updated_at = created_at + INTERVAL '1 second' WHERE source_path = ?", ['/racun/vodic-za-profil?type=brand']);
        $this->migrate(false);
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM url_redirect'));
    }

    public function testAdminCanManageGuideRedirectsWithoutAllowingPrivatePathsOrLoops(): void
    {
        $this->migrate();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('guide-admin@example.test', 'ROLE_CREATOR');
        $admin->setAdmin(true);
        $admin->setPassword('unused');
        $em->persist($admin);
        $em->flush();
        $this->client->loginUser($admin);
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = $this->payload()['csrfToken'];
        $this->client->request('GET', '/api/admin/redirects?search='.urlencode('?type=agency'));
        self::assertSame(7, $this->payload()['meta']['total']);
        $row = array_values(array_filter($this->payload()['data'], fn (array $row): bool => $row['sourcePath'] === '/racun/vodic-za-profil?type=agency'))[0];
        $row['targetPath'] = '/kompanije';
        $this->client->jsonRequest('PUT', '/api/admin/redirects/'.$row['id'], $row, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/racun/vodic-za-profil?type=agency');
        self::assertResponseRedirects('/kompanije', 301);
        $row['enabled'] = false;
        $this->client->jsonRequest('PUT', '/api/admin/redirects/'.$row['id'], $row, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/redirects/resolve?'.http_build_query(['path' => '/racun/vodic-za-profil?type=agency']));
        self::assertNull($this->payload()['data']);
        $this->client->request('GET', '/racun/vodic-za-profil?type=brand');
        self::assertResponseRedirects('/racun/vodic-za-profil/brend', 301);
        foreach (['/racun?type=agency', '/racun/postavke?type=agency', '/racun/vodic-za-profil?token=private', '/racun/vodic-za-profil?type=agency&other=yes', '/racun/vodic-za-profil/kreator?type=agency'] as $source) {
            $this->client->jsonRequest('POST', '/api/admin/redirects', ['sourcePath' => $source, 'targetPath' => '/kreatori', 'enabled' => true, 'preserveQuery' => false], ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(400);
        }
        $this->client->jsonRequest('POST', '/api/admin/redirects', ['sourcePath' => '/racun/vodic-za-profil/kreator', 'targetPath' => '/racun/vodic-za-profil?type=creator', 'enabled' => true, 'preserveQuery' => false], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testPublicSitemapIncludesAllCleanGuidesAndNoLegacyQueryUrls(): void
    {
        $sitemaps = static::getContainer()->get(SitemapGenerator::class);
        $xml = $sitemaps->generate();
        $robots = $sitemaps->robotsTxt();
        $routes = static::getContainer()->get(LocalizedRouteMap::class);
        foreach ($routes->locales() as $locale) {
            self::assertStringContainsString('Allow: '.$routes->localizedPath('account-profile-guide', $locale)."\n", $robots);
            foreach (['creator', 'agency', 'brand'] as $kind) {
                self::assertStringContainsString($routes->localizedPath('profile-guide-'.$kind, $locale).'</loc>', $xml);
            }
        }
        self::assertStringNotContainsString('?type=', $xml);
        self::assertStringNotContainsString('/racun/vodic-za-profil</loc>', $xml);
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
