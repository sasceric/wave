<?php

namespace App\Tests\Controller;

use App\Entity\EmailTemplate;
use App\Entity\NewsletterSubscriber;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class NewsletterSubscriberControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get('cache.rate_limiter')->clear();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testSignupPersistsSubscriberAndSendsLocalizedConfirmation(): void
    {
        $this->jsonRequest('POST', '/api/newsletter/subscribers?locale=en', [
            'email' => '  ALEX@Example.test ',
            'website' => '',
        ], $this->csrfToken());

        self::assertResponseIsSuccessful();
        self::assertSame('You’re subscribed to Wave updates.', $this->payload()['message']);
        self::assertSame(
            1,
            static::getContainer()->get(EntityManagerInterface::class)
                ->getRepository(NewsletterSubscriber::class)
                ->count([]),
        );
        self::assertSame(
            'alex@example.test',
            static::getContainer()->get(EntityManagerInterface::class)
                ->getRepository(NewsletterSubscriber::class)
                ->findOneBy(['email' => 'alex@example.test'])
                ?->getEmail(),
        );

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('alex@example.test', $email->getTo()[0]->getAddress());
        self::assertEmailSubjectContains($email, 'You’re on the Wave updates list');
        self::assertEmailHtmlBodyContains($email, '<html lang="en">');
        self::assertEmailHtmlBodyContains($email, 'Glad you’re here.');
    }

    public function testSignupHoneypotIsSilentlyAccepted(): void
    {
        $this->jsonRequest('POST', '/api/newsletter/subscribers?locale=en', [
            'email' => 'bot@example.test',
            'website' => 'Filled by a bot',
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
        self::assertSame(
            0,
            static::getContainer()->get(EntityManagerInterface::class)
                ->getRepository(NewsletterSubscriber::class)
                ->count([]),
        );
    }

    public function testDuplicateEmailIsIdempotent(): void
    {
        $csrfToken = $this->csrfToken();
        $this->jsonRequest('POST', '/api/newsletter/subscribers?locale=en', [
            'email' => 'duplicate@example.test',
            'website' => '',
        ], $csrfToken);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);

        $this->jsonRequest('POST', '/api/newsletter/subscribers?locale=en', [
            'email' => 'duplicate@example.test',
            'website' => '',
        ], $csrfToken);
        self::assertResponseIsSuccessful();

        self::assertEmailCount(0);
        self::assertSame(
            1,
            static::getContainer()->get(EntityManagerInterface::class)
                ->getRepository(NewsletterSubscriber::class)
                ->count([]),
        );
    }

    public function testSignupValidatesCsrfAndEmailAndUsesEditableTemplate(): void
    {
        $this->jsonRequest('POST', '/api/newsletter/subscribers?locale=en', [
            'email' => 'alex@example.test',
            'website' => '',
        ], '');
        self::assertResponseStatusCodeSame(403);

        $this->jsonRequest('POST', '/api/newsletter/subscribers?locale=en', [
            'email' => 'not-an-email',
            'website' => '',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(400);
        self::assertEmailCount(0);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new EmailTemplate(
            'subscribed',
            'en',
            'A custom newsletter subject',
            '<html><body><h1>{{heading}}</h1><p>{{message}}</p><footer>{{footer}}</footer></body></html>',
        ));
        $entityManager->flush();

        $this->jsonRequest('POST', '/api/newsletter/subscribers?locale=en', [
            'email' => 'alex@example.test',
            'website' => '',
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailSubjectContains($email, 'A custom newsletter subject');
        self::assertEmailHtmlBodyContains($email, '<h1>Glad you’re here.</h1>');
    }

    public function testSubscriberListIsAdminOnlyAndPaginated(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new NewsletterSubscriber('first@example.test', 'en'));
        $entityManager->persist(new NewsletterSubscriber('second@example.test', 'sl'));
        $entityManager->flush();

        $this->client->request('GET', '/api/admin/subscribers?locale=en');
        self::assertResponseStatusCodeSame(401);

        $user = new User('member@example.test', 'ROLE_COMPANY');
        $user->setPassword('unused-test-hash');
        $entityManager->persist($user);
        $entityManager->flush();
        $this->client->loginUser($user, 'main');
        $this->client->request('GET', '/api/admin/subscribers?locale=en');
        self::assertResponseStatusCodeSame(403);

        $admin = new User('admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused-test-hash');
        $admin->setAdmin(true);
        $entityManager->persist($admin);
        $entityManager->flush();
        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/api/admin/subscribers?locale=en&page=1&pageSize=25');

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->payload()['meta']['total']);
        self::assertSame(
            ['second@example.test', 'first@example.test'],
            array_column($this->payload()['data'], 'email'),
        );
    }

    private function csrfToken(): string
    {
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        self::assertResponseIsSuccessful();

        return $this->payload()['csrfToken'];
    }

    /**
     * @param array<string, string> $body
     */
    private function jsonRequest(string $method, string $path, array $body, string $csrf): void
    {
        $this->client->request($method, $path, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
