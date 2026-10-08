<?php

namespace App\Tests\Controller;

use App\Entity\QrLink;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminQrControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    public function testOnlyAdminsCanReadAndWriteQrLinksAndWritesRequireCsrf(): void
    {
        $this->client->request('GET', '/api/admin/qr-links?locale=en');
        self::assertResponseStatusCodeSame(401);
        $user = $this->user();
        $this->client->loginUser($user, 'main');
        $this->client->request('GET', '/api/admin/qr-links?locale=en');
        self::assertResponseStatusCodeSame(403);
        $moderator = $this->user(false, true);
        $this->client->loginUser($moderator, 'main');
        $this->client->request('GET', '/api/admin/qr-links?locale=en');
        self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->user(true), 'main');
        $this->client->request('POST', '/api/admin/qr-links?locale=en', content: '{}');
        self::assertResponseStatusCodeSame(403);
        foreach (['page=0', 'pageSize=5', 'locale=xx'] as $query) {
            $this->client->request('GET', '/api/admin/qr-links?'.$query);
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testEditingDestinationKeepsTheSamePublicQrUrlAndRedirectIsNotCached(): void
    {
        $user = $this->user();
        $user->setAdmin(true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($user, 'main');
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $csrf = $this->payload()['csrfToken'];
        $this->write('POST', '', $csrf, ['label' => 'Printed campaign', 'destination' => 'https://example.com/first?campaign=wave']);
        self::assertResponseStatusCodeSame(201);
        $original = $this->payload()['data'];
        self::assertMatchesRegularExpression('~/q/[a-f0-9]{32}$~', $original['url']);
        $this->client->request('GET', '/api/admin/qr-links?locale=en&page=1&pageSize=25');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['meta']['total']);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $this->write('PUT', '/'.$original['id'], $csrf, ['label' => 'Updated campaign', 'destination' => 'https://example.org/second']);
        self::assertResponseIsSuccessful();
        self::assertSame($original['url'], $this->payload()['data']['url']);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', parse_url($original['url'], PHP_URL_PATH));
        self::assertResponseRedirects('https://example.org/second', 302);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        $this->client->request('GET', '/q/'.str_repeat('a', 32));
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnsafeDestinationsCannotBeSaved(): void
    {
        $user = $this->user();
        $user->setAdmin(true);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $this->client->loginUser($user, 'main');
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $csrf = $this->payload()['csrfToken'];
        foreach (['javascript:alert(1)', '/kampanje', 'data:text/html,hello', 'https://user:password@example.org', "https://example.org/\r\nLocation:bad", 'http://127.0.0.1:8000/q/'.str_repeat('a', 32)] as $destination) {
            $this->write('POST', '', $csrf, ['label' => 'Unsafe', 'destination' => $destination]);
            self::assertResponseStatusCodeSame(400);
        }
        self::assertSame(0, $em->getRepository(QrLink::class)->count([]));
        $this->write('PUT', '/999', $csrf, ['label' => 'Missing', 'destination' => 'https://example.org']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testScansAggregateAcrossEditsAndOnlyTrustedLocationHeadersAreUsed(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $link = new QrLink('Analytics', 'https://example.org/first');
        $em->persist($link);
        $em->flush();
        $path = '/q/'.$link->getToken();
        $browser = ['HTTP_USER_AGENT' => 'Mozilla/5.0', 'REMOTE_ADDR' => '173.245.48.10', 'HTTP_CF_IPCOUNTRY' => 'BA', 'HTTP_CF_IPCITY' => 'Sarajevo'];
        for ($index = 0; $index < 2; ++$index) {
            $this->client->request('GET', $path, server: $browser);
            self::assertResponseRedirects('https://example.org/first', 302);
        }
        $link = $em->find(QrLink::class, $link->getId());
        $link->update('Analytics updated', 'https://example.org/second');
        $em->flush();
        $this->client->request('GET', $path, server: array_replace($browser, ['REMOTE_ADDR' => '203.0.113.7']));
        self::assertResponseRedirects('https://example.org/second', 302);
        $this->client->request('HEAD', $path, server: $browser);
        $this->client->request('GET', $path, server: array_replace($browser, ['HTTP_USER_AGENT' => 'Googlebot']));
        $this->client->request('GET', $path, server: $browser + ['HTTP_SEC_PURPOSE' => 'prefetch']);
        $this->client->request('GET', '/api/admin/qr-links/'.$link->getId().'/statistics?locale=en');
        self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($this->user(), 'main');
        $this->client->request('GET', '/api/admin/qr-links/'.$link->getId().'/statistics?locale=en');
        self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->user(true), 'main');
        $this->client->request('GET', '/api/admin/qr-links/'.$link->getId().'/statistics?locale=en');
        self::assertResponseIsSuccessful();
        $data = $this->payload()['data'];
        self::assertSame(3, $data['scans']);
        self::assertSame(3, $data['daily'][0]['scans']);
        self::assertSame(['country' => 'BA', 'city' => 'Sarajevo', 'scans' => 2], $data['locations'][0]);
        self::assertSame(['country' => null, 'city' => null, 'scans' => 1], $data['locations'][1]);
        self::assertNotNull($data['lastScanAt']);
        self::assertArrayNotHasKey('ip', $data);
        $this->client->request('GET', '/api/admin/qr-links?locale=en');
        self::assertSame(3, $this->payload()['data'][0]['scans']);
        $this->client->request('GET', '/api/admin/qr-links/999/statistics?locale=en');
        self::assertResponseStatusCodeSame(404);
    }

    private function write(string $method, string $path, string $csrf, array $data): void
    {
        $this->client->request($method, '/api/admin/qr-links'.$path.'?locale=en', server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function user(bool $admin = false, bool $moderator = false): User
    {
        $user = new User(bin2hex(random_bytes(6)).'@example.test', 'ROLE_COMPANY');
        $user->setAdmin($admin);
        $user->setModerator($moderator);
        $user->setPassword('unused-test-hash');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        return $user;
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
