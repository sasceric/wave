<?php

namespace App\Tests\Controller;

use App\Entity\OAuthIdentity;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class OAuthRegistrationFlowTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get('cache.rate_limiter')->clear();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    public function testSocialSignupDefersProfileDetailsForBothAccountTypes(): void
    {
        foreach (['creator', 'company'] as $type) {
            $this->client->request('GET', '/api/auth/csrf?locale=en');
            $csrf = $this->payload()['csrfToken'];
            $session = $this->client->getRequest()->getSession();
            $session->set('wave_oauth_registration', ['provider' => 'google', 'subject' => 'short-'.$type, 'email' => 'short-'.$type.'@example.test', 'accountType' => $type, 'createdAt' => time()]);
            $session->save();
            $this->client->jsonRequest('POST', '/api/auth/oauth/complete?locale=en', ['accountType' => $type, 'name' => 'Amina Studio'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(201);
            self::assertTrue($this->payload()['data']['emailVerified']);
            self::assertFalse($this->payload()['data']['profileComplete']);
            self::assertFalse($this->payload()['data']['approved']);
            self::assertSame(1, $this->payload()['data']['hide_my_account']);
        }
    }

    public function testGoogleCompanyRegistrationStoresAgencyType(): void
    {
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $csrf = $this->payload()['csrfToken'];
        $session = $this->client->getRequest()->getSession();
        $session->set('wave_oauth_registration', ['provider' => 'google', 'subject' => 'agency-owner', 'email' => 'agency@example.test', 'givenName' => 'Agency', 'familyName' => 'Owner', 'accountType' => 'company', 'createdAt' => time()]);
        $session->save();
        $this->client->jsonRequest('POST', '/api/auth/oauth/complete?locale=en', [
            'accountType' => 'company', 'name' => 'Demo agency', 'companyType' => 'agency', 'industries' => ['Beauty'],
            'country' => 'BA', 'city' => 'Sarajevo', 'phone' => '+38761123456',
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('agency', $this->payload()['data']['profile']['companyType']);
        self::assertFalse($this->payload()['data']['approved']);
    }

    public function testGoogleRegistrationCollectsProfileAndCreatesPendingVerifiedAccount(): void
    {
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        self::assertResponseIsSuccessful();
        $csrfToken = $this->payload()['csrfToken'];
        $this->client->getRequest()->getSession()->set('wave_oauth_registration', [
            'provider' => 'google',
            'subject' => 'provider-user-1',
            'email' => 'creator@example.test',
            'givenName' => 'Avery',
            'familyName' => 'Creator',
            'accountType' => 'creator',
            'createdAt' => time(),
        ]);
        $this->client->getRequest()->getSession()->save();

        $this->client->request('GET', '/api/auth/oauth/pending?locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame('creator@example.test', $this->payload()['data']['email']);
        self::assertSame('Avery', $this->payload()['data']['firstName']);
        self::assertSame('Avery Creator', $this->payload()['data']['name']);

        $this->client->request(
            'POST',
            '/api/auth/oauth/complete?locale=en',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $csrfToken,
            ],
            content: json_encode([
                'accountType' => 'creator',
                'name' => 'Avery Creator',
                'birthday' => '2000-02-29',
                'phone' => '+387 61 123 456',
                'country' => 'BA',
                'city' => 'Sarajevo',
                'categories' => ['Travel'],
                'location' => 'Sarajevo, Bosnia and Herzegovina',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'creator@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isEmailVerified());
        self::assertFalse($user->isApproved());
        self::assertTrue($user->isHideMyAccount());
        self::assertTrue($user->hasCompleteProfile());
        self::assertSame('en', $user->getPreferredLocale());
        self::assertSame('Avery Creator', $user->getCreator()->getDisplayName());
        self::assertSame('2000-02-29', $user->getCreator()->getBirthday()->format('Y-m-d'));
        self::assertInstanceOf(OAuthIdentity::class, $entityManager->getRepository(OAuthIdentity::class)
            ->findOneBy(['provider' => 'google', 'subject' => 'provider-user-1']));
        self::assertEmailCount(1);
        $registrationEmail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $registrationEmail);
        self::assertEmailSubjectContains($registrationEmail, 'Your Wave registration is awaiting review');

        $this->client->request('GET', '/api/auth/me?locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame('creator@example.test', $this->payload()['data']['email']);
        self::assertTrue($this->payload()['data']['emailVerified']);
        self::assertFalse($this->payload()['data']['approved']);
    }

    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
    }
}
