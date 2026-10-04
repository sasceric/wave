<?php

namespace App\Tests\Controller;

use App\Account\AccountEmailSender;
use App\Entity\EmailTemplate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class EmailTemplateAdminControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAdminCanEditAndPreviewBackedTemplatesAndChangesAreUsedForSending(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $regular = new User('creator@example.test', 'ROLE_CREATOR');
        $regular->setPassword('unused-test-hash');
        $admin = new User('admin@example.test', 'ROLE_COMPANY');
        $admin->setPassword('unused-test-hash');
        $admin->setAdmin(true);
        $entityManager->persist($regular);
        $entityManager->persist($admin);
        $entityManager->flush();

        $this->client->loginUser($regular, 'main');
        $this->client->request('GET', '/api/admin/email-templates?locale=en');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/api/admin/email-templates?locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['verify', 'reset', 'registered', 'approval', 'contact'],
            array_column($this->payload()['data']['templates'], 'key'),
        );

        $contactSubjects = [
            'bs' => 'Nova poruka putem Wave kontakt forme',
            'hr' => 'Nova poruka putem Wave kontakt obrasca',
            'sr' => 'Nova poruka putem Wave kontakt forme',
            'cnr' => 'Nova poruka putem Wave kontakt forme',
            'sl' => 'Novo sporočilo prek Wave kontaktnega obrazca',
            'en' => 'New Wave contact form message',
        ];
        $contactHeadings = [
            'bs' => 'Nova poruka putem kontakt forme',
            'hr' => 'Nova poruka putem kontakt obrasca',
            'sr' => 'Nova poruka putem kontakt forme',
            'cnr' => 'Nova poruka putem kontakt forme',
            'sl' => 'Novo sporočilo prek kontaktnega obrazca',
            'en' => 'New Wave contact message',
        ];
        $verifySubjects = [
            'bs' => 'Registracija za Wave je zaprimljena',
            'hr' => 'Registracija za Wave je zaprimljena',
            'sr' => 'Registracija za Wave je primljena',
            'cnr' => 'Registracija za Wave je primljena',
            'sl' => 'Registracija v Wave je prejeta',
            'en' => 'Your Wave registration has been received',
        ];
        $resetSubjects = [
            'bs' => 'Promijeni lozinku za Wave',
            'hr' => 'Promijeni lozinku za Wave',
            'sr' => 'Promeni lozinku za Wave',
            'cnr' => 'Promijeni lozinku za Wave',
            'sl' => 'Spremeni geslo za Wave',
            'en' => 'Reset your Wave password',
        ];
        $verifyHeadings = [
            'bs' => 'Potvrdi e-mail adresu.',
            'hr' => 'Potvrdi svoju adresu e-pošte.',
            'sr' => 'Potvrdi svoju e-mail adresu.',
            'cnr' => 'Potvrdi e-mail adresu.',
            'sl' => 'Potrdi svoj e-poštni naslov.',
            'en' => 'Confirm your email address.',
        ];
        $registeredSubjects = [
            'bs' => 'Tvoja Wave registracija čeka pregled',
            'hr' => 'Tvoja Wave registracija čeka pregled',
            'sr' => 'Tvoja Wave registracija čeka pregled',
            'cnr' => 'Tvoja Wave registracija čeka pregled',
            'sl' => 'Tvoja registracija v Wave čaka na pregled',
            'en' => 'Your Wave registration is awaiting review',
        ];
        $approvalSubjects = [
            'bs' => 'Tvoj Wave račun je odobren',
            'hr' => 'Tvoj Wave račun je odobren',
            'sr' => 'Tvoj Wave nalog je odobren',
            'cnr' => 'Tvoj Wave nalog je odobren',
            'sl' => 'Tvoj račun Wave je odobren',
            'en' => 'Your Wave account has been approved',
        ];
        foreach ($contactSubjects as $locale => $subject) {
            $this->client->request('GET', '/api/admin/email-templates?locale='.$locale);
            self::assertResponseIsSuccessful();
            $templates = $this->payload()['data']['templates'];
            self::assertSame($verifySubjects[$locale], $templates[0]['subject']);
            self::assertSame($resetSubjects[$locale], $templates[1]['subject']);
            self::assertSame($verifyHeadings[$locale], $templates[0]['preview']['heading']);
            self::assertSame($registeredSubjects[$locale], $templates[2]['subject']);
            self::assertSame($approvalSubjects[$locale], $templates[3]['subject']);
            self::assertSame($subject, $templates[4]['subject']);
            self::assertStringContainsString('{{roleLabel}}', $templates[4]['htmlBody']);
            self::assertStringContainsString('border-top:4px solid #e7967c', $templates[4]['htmlBody']);
            self::assertContains('preheader', $templates[4]['variables']);
            self::assertContains('eyebrow', $templates[4]['variables']);
            self::assertContains('footer', $templates[4]['variables']);
            self::assertSame($locale, $templates[4]['locale']);
            self::assertSame($contactHeadings[$locale], $templates[4]['preview']['heading']);
        }

        $htmlBody = '<html><body><h1>{{heading}}</h1><a href="{{actionUrl}}">{{buttonLabel}}</a></body></html>';
        $this->jsonRequest('PUT', '/api/admin/email-templates/verify?locale=en', [
            'subject' => 'A custom verification subject',
            'htmlBody' => $htmlBody,
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();

        $slovenianHtml = '<html><body><h1>{{heading}}</h1></body></html>';
        $this->jsonRequest('PUT', '/api/admin/email-templates/verify?locale=sl', [
            'subject' => 'Slovenska zadeva potrditve',
            'htmlBody' => $slovenianHtml,
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();

        $this->jsonRequest('PUT', '/api/admin/email-templates/verify?locale=en', [
            'subject' => 'Unsafe template',
            'htmlBody' => '<html><script>alert(1)</script></html>',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/admin/email-templates?locale=en');
        self::assertResponseIsSuccessful();
        $templates = $this->payload()['data']['templates'];
        self::assertSame('A custom verification subject', $templates[0]['subject']);
        self::assertSame($htmlBody, $templates[0]['htmlBody']);

        $this->client->request('GET', '/api/admin/email-templates?locale=sl');
        self::assertResponseIsSuccessful();
        $slovenianTemplates = $this->payload()['data']['templates'];
        self::assertSame('Slovenska zadeva potrditve', $slovenianTemplates[0]['subject']);
        self::assertSame($slovenianHtml, $slovenianTemplates[0]['htmlBody']);

        static::getContainer()->get(AccountEmailSender::class)->sendVerification(
            new User('verified@example.test', 'ROLE_CREATOR'),
            str_repeat('a', 64),
            'en',
        );
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailSubjectContains($email, 'A custom verification subject');
        self::assertEmailHtmlBodyContains($email, '<h1>Confirm your email address.</h1>');
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
