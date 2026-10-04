<?php

namespace App\Tests\Controller;

use App\Entity\EmailTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class ContactControllerTest extends WebTestCase
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

    public function testContactMessageIsSentToWaveInbox(): void
    {
        $this->jsonRequest('POST', '/api/contact?locale=en', [
            'role' => 'creator',
            'name' => 'Alex Creator',
            'email' => 'alex@example.test',
            'interest' => 'collaboration',
            'message' => 'I would love to learn more about working with Wave.',
        ], $this->csrfToken());

        self::assertResponseIsSuccessful();
        self::assertSame(
            "Thanks for reaching out. We'll be in touch soon.",
            $this->payload()['message'],
        );
        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('info@wave.ba', $email->getTo()[0]->getAddress());
        self::assertSame('alex@example.test', $email->getReplyTo()[0]->getAddress());
        self::assertStringContainsString('Role: Creator', $email->getTextBody());
        self::assertStringContainsString('Interest: Collaboration', $email->getTextBody());
        self::assertStringContainsString('Alex Creator', $email->getTextBody());
        self::assertStringContainsString('working with Wave', $email->getTextBody());
        self::assertEmailHtmlBodyContains($email, '<td style="padding-left:10px;color:#173c35;');
        self::assertEmailHtmlBodyContains($email, 'border-top:4px solid #e7967c');
        self::assertEmailHtmlBodyContains($email, 'CONTACT FORM MESSAGE');
        self::assertEmailHtmlBodyContains($email, 'Reply directly to the sender using the Reply button.');
    }

    public function testContactEmailUsesSelectedLocaleForDefaultTemplateCopy(): void
    {
        $this->jsonRequest('POST', '/api/contact?locale=sl', [
            'role' => 'creator',
            'name' => 'Alex Creator',
            'email' => 'alex@example.test',
            'interest' => 'collaboration',
            'message' => 'I would love to learn more about working with Wave.',
        ], $this->csrfToken());

        self::assertResponseIsSuccessful();
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailSubjectContains($email, 'Novo sporočilo prek Wave kontaktnega obrazca');
        self::assertEmailTextBodyContains($email, 'Vloga: Ustvarjalec');
        self::assertEmailTextBodyContains($email, 'Zanimanje: Sodelovanje');
        self::assertEmailHtmlBodyContains($email, '<html lang="sl">');
        self::assertEmailHtmlBodyContains($email, 'SPOROČILO S KONTAKTNEGA OBRAZCA');
        self::assertEmailHtmlBodyContains($email, 'border-top:4px solid #e7967c');
        self::assertEmailHtmlBodyContains($email, 'Sporočilo</p>');
    }

    public function testContactMessageRequiresCsrfAndValidFields(): void
    {
        $this->jsonRequest('POST', '/api/contact?locale=en', [
            'role' => 'creator',
            'name' => 'Alex Creator',
            'email' => 'alex@example.test',
            'interest' => 'collaboration',
            'message' => 'A valid message that is long enough.',
        ], '');
        self::assertResponseStatusCodeSame(403);

        $this->jsonRequest('POST', '/api/contact?locale=en', [
            'role' => 'creator',
            'name' => 'Alex Creator',
            'email' => 'not-an-email',
            'interest' => 'collaboration',
            'message' => 'A valid message that is long enough.',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(400);

        $this->jsonRequest('POST', '/api/contact?locale=en', [
            'role' => 'visitor',
            'name' => 'Alex Creator',
            'email' => 'alex@example.test',
            'interest' => 'collaboration',
            'message' => 'A valid message that is long enough.',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(400);

        self::assertEmailCount(0);
    }

    public function testContactMessageWithHoneypotIsRejected(): void
    {
        $this->jsonRequest('POST', '/api/contact?locale=en', [
            'role' => 'creator',
            'name' => 'Alex Creator',
            'email' => 'alex@example.test',
            'interest' => 'collaboration',
            'message' => 'A valid message that is long enough.',
            'trap' => 'A bot filled this field.',
        ], $this->csrfToken());

        self::assertResponseStatusCodeSame(400);
        self::assertEmailCount(0);
    }

    public function testSavedContactEmailTemplateIsUsedForContactNotifications(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new EmailTemplate(
            'contact',
            'en',
            'A custom contact subject',
            '<html><body><p>{{name}} wrote: {{message}}</p></body></html>',
        ));
        $entityManager->flush();

        $this->jsonRequest('POST', '/api/contact?locale=en', [
            'role' => 'creator',
            'name' => 'Alex Creator',
            'email' => 'alex@example.test',
            'interest' => 'collaboration',
            'message' => 'I would love to learn more about working with Wave.',
        ], $this->csrfToken());

        self::assertResponseIsSuccessful();
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailSubjectContains($email, 'A custom contact subject');
        self::assertEmailHtmlBodyContains(
            $email,
            '<p>Alex Creator wrote: I would love to learn more about working with Wave.</p>',
        );
    }

    private function csrfToken(): string
    {
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        self::assertResponseIsSuccessful();

        return $this->payload()['csrfToken'];
    }

    private function jsonRequest(string $method, string $path, array $body, string $csrf): void
    {
        $this->client->request($method, $path, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
