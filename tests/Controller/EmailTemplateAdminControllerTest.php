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
            [
                'verify',
                'reset',
                'registered',
                'approval',
                'contact',
                'subscribed',
                'application_received',
                'creator_inquiry_received',
                'creator_inquiry_accepted',
                'creator_hired',
                'unread_message_reminder',
            ],
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
        $applicationSubjects = [
            'bs' => 'Nova prijava za kampanju na Waveu',
            'hr' => 'Nova prijava za kampanju na Waveu',
            'sr' => 'Nova prijava za kampanju na Waveu',
            'cnr' => 'Nova prijava za kampanju na Waveu',
            'sl' => 'Nova prijava za kampanjo na Waveu',
            'en' => 'New campaign application on Wave',
        ];
        $inquirySubjects = [
            'bs' => 'Novi upit za tvoj paket na Waveu',
            'hr' => 'Novi upit za tvoj paket na Waveu',
            'sr' => 'Novi upit za tvoj paket na Waveu',
            'cnr' => 'Novi upit za tvoj paket na Waveu',
            'sl' => 'Novo povpraševanje za tvoj paket na Waveu',
            'en' => 'New package inquiry on Wave',
        ];
        $acceptedInquirySubjects = [
            'bs' => 'Tvoj upit za saradnju je prihvaćen na Waveu',
            'hr' => 'Tvoj upit za suradnju prihvaćen je na Waveu',
            'sr' => 'Tvoj upit za saradnju je prihvaćen na Waveu',
            'cnr' => 'Tvoj upit za saradnju je prihvaćen na Waveu',
            'sl' => 'Tvoje povpraševanje za sodelovanje je sprejeto na Waveu',
            'en' => 'Your collaboration inquiry was accepted on Wave',
        ];
        $hiredSubjects = [
            'bs' => 'Ponuda je prihvaćena na Waveu',
            'hr' => 'Ponuda je prihvaćena na Waveu',
            'sr' => 'Ponuda je prihvaćena na Waveu',
            'cnr' => 'Ponuda je prihvaćena na Waveu',
            'sl' => 'Ponudba je sprejeta na Waveu',
            'en' => 'Your offer was accepted on Wave',
        ];
        $reminderSubjects = [
            'bs' => 'Imaš nepročitane poruke na Waveu',
            'hr' => 'Imaš nepročitane poruke na Waveu',
            'sr' => 'Imaš nepročitane poruke na Waveu',
            'cnr' => 'Imaš nepročitane poruke na Waveu',
            'sl' => 'Na Waveu imaš neprebrana sporočila',
            'en' => 'You have unread messages on Wave',
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
            self::assertSame($applicationSubjects[$locale], $templates[6]['subject']);
            self::assertSame($inquirySubjects[$locale], $templates[7]['subject']);
            self::assertContains('details', $templates[7]['variables']);
            self::assertSame($acceptedInquirySubjects[$locale], $templates[8]['subject']);
            self::assertContains('details', $templates[8]['variables']);
            self::assertSame($hiredSubjects[$locale], $templates[9]['subject']);
            self::assertSame($reminderSubjects[$locale], $templates[10]['subject']);
            self::assertContains('details', $templates[6]['variables']);
            self::assertSame($locale, $templates[10]['locale']);
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

        $applicationHtml = '<html><body><h1>{{heading}}</h1><p>{{firstValue}} / {{secondValue}}</p><blockquote>{{details}}</blockquote></body></html>';
        $this->jsonRequest('PUT', '/api/admin/email-templates/application_received?locale=en', [
            'subject' => 'A custom application email',
            'htmlBody' => $applicationHtml,
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();

        $brand = new User('brand@example.test', 'ROLE_COMPANY');
        $brand->setPreferredLocale('en');
        static::getContainer()->get(AccountEmailSender::class)->sendApplicationReceived(
            $brand,
            'Avery <Creator>',
            'Summer campaign',
            'Hello <script>alert("no")</script>',
        );
        self::assertEmailCount(1);
        $applicationEmail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $applicationEmail);
        self::assertSame('brand@example.test', $applicationEmail->getTo()[0]->getAddress());
        self::assertEmailSubjectContains($applicationEmail, 'A custom application email');
        self::assertEmailHtmlBodyContains($applicationEmail, 'Avery &lt;Creator&gt;');
        self::assertEmailHtmlBodyContains($applicationEmail, 'Hello &lt;script&gt;');
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
