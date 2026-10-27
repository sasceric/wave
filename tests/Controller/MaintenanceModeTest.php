<?php

namespace App\Tests\Controller;

use App\Entity\{UrlRedirect, User};
use App\Service\MaintenanceMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MaintenanceModeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->admin = new User('maintenance-admin@example.test', 'ROLE_COMPANY');
        $this->admin->setPassword($hasher->hashPassword($this->admin, 'Local-test-password-2026'));
        $this->admin->setAdmin(true);
        $this->member = new User('maintenance-member@example.test', 'ROLE_CREATOR');
        $this->member->setPassword('unused');
        $this->member->setApproved(true);
        $this->em->persist($this->admin);
        $this->em->persist($this->member);
        $this->em->flush();
    }

    public function testOnlyAdminsCanToggleWithCsrfAndABooleanValue(): void
    {
        $this->client->request('GET', '/api/maintenance');
        self::assertSame(['enabled' => false, 'bypass' => false], $this->payload()['data']);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $this->client->jsonRequest('PUT', '/api/admin/maintenance', ['enabled' => true]);
        self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($this->member);
        $this->client->jsonRequest('PUT', '/api/admin/maintenance', ['enabled' => true]);
        self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->admin);
        $this->client->jsonRequest('PUT', '/api/admin/maintenance', ['enabled' => true]);
        self::assertResponseStatusCodeSame(403);
        $csrf = $this->csrf();
        foreach (['true', 1, null, []] as $value) {
            $this->client->jsonRequest('PUT', '/api/admin/maintenance', ['enabled' => $value], $csrf);
            self::assertResponseStatusCodeSame(400);
        }
        $this->client->jsonRequest('PUT', '/api/admin/maintenance', ['enabled' => true], $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame(['enabled' => true, 'bypass' => true], $this->payload()['data']);
        $this->client->request('GET', '/api/creators');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/racun/postavke');
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('PUT', '/api/admin/maintenance', ['enabled' => false], $csrf);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['data']['enabled']);
    }

    public function testPublicDocumentsAndApiWritesAreBlockedWithoutSavingRedirects(): void
    {
        $redirect = new UrlRedirect('/old-maintenance-test', '/kreatori', 'manual');
        $this->em->persist($redirect);
        $this->em->flush();
        static::getContainer()->get(MaintenanceMode::class)->setEnabled(true);
        foreach (['/', '/kreatori', '/kampanje/anything', '/en/companies', '/racun/postavke', '/old-maintenance-test', '/maintenance', '/missing?admin=1'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/odrzavanje', 302);
            self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        }
        $this->client->request('GET', '/odrzavanje');
        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Retry-After', '60');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertSelectorTextContains('h1', 'Mala pauza.');
        self::assertSelectorExists('script[type="module"]');
        $this->client->request('GET', '/api/creators');
        self::assertResponseStatusCodeSame(503);
        self::assertSame('maintenance', $this->payload()['code']);
        $this->client->jsonRequest('POST', '/api/auth/register', ['email' => 'must-not-register@example.test']);
        self::assertResponseStatusCodeSame(503);
        $this->client->loginUser($this->member);
        $this->client->jsonRequest('POST', '/api/me/conversations/1/messages', ['body' => 'Must not send']);
        self::assertResponseStatusCodeSame(503);
        self::assertSame('maintenance', $this->payload()['code']);
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM campaign_message'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM url_redirect'));
        static::getContainer()->get(MaintenanceMode::class)->setEnabled(false);
        $this->client->request('GET', '/old-maintenance-test');
        self::assertResponseRedirects('/kreatori', 301);
        $this->client->request('GET', '/odrzavanje');
        self::assertResponseRedirects('/', 302);
    }

    public function testAdminCanSignBackInAndTurnItOffWhileHealthAndRecoveryStayAvailable(): void
    {
        static::getContainer()->get(MaintenanceMode::class)->setEnabled(true);
        foreach (['/prijava', '/en/login', '/zaboravljena-lozinka', '/api/auth/session', '/api/auth/csrf', '/api/auth/oauth/providers', '/api/maintenance', '/api/health', '/robots.txt'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
        }
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => $this->admin->getEmail(), 'password' => 'Local-test-password-2026'], $this->csrf());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/maintenance');
        self::assertTrue($this->payload()['data']['bypass']);
        $this->client->request('GET', '/odrzavanje');
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('PUT', '/api/admin/maintenance', ['enabled' => false], $this->csrf());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/homepage');
        self::assertResponseIsSuccessful();
    }

    private function csrf(): array
    {
        $this->client->request('GET', '/api/auth/csrf');
        return ['HTTP_X_CSRF_TOKEN' => $this->payload()['csrfToken']];
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
