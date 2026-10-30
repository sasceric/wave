<?php

namespace App\Tests\Controller;

use App\Account\{AccountEmailSender, EmailTemplateRenderer};
use App\Entity\{Company, Creator, EmailTemplate, User};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\WebTestCase};
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mailer\{MailerInterface, Exception\TransportException};
use Symfony\Component\Mime\Email;

final class AdminUnapprovalTest extends WebTestCase
{
    use MailerAssertionsTrait;
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private User $owner;

    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($this->em); $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata); $schema->createSchema($metadata);
        $this->admin = new User('unapproval-admin@example.test', 'ROLE_COMPANY'); $this->admin->setAdmin(true); $this->admin->setPassword('unused');
        $this->owner = new User('unapproval-owner@example.test', 'ROLE_CREATOR', true); $this->owner->setPassword('unused'); $this->owner->setEmailVerified(true); $this->owner->setHideMyAccount(false); $this->owner->setPreferredLocale('en');
        $this->owner->setPhone('+38761123456'); $this->owner->setCity('Sarajevo'); $this->owner->setCountryCode('BA');
        $creator = new Creator('preserved-creator', 'Saved Creator', 'Beauty', 'Sarajevo', '<p>Saved bio.</p>', [['platform' => 'instagram', 'url' => 'https://instagram.com/example', 'followers' => 10]]);
        $creator->setCreatorTypes(['ugc_creator']); $this->owner->setCreator($creator);
        $this->em->persist($this->admin); $this->em->persist($this->owner); $this->em->persist($creator); $this->em->flush();
    }

    public function testOnlyAdminsWithCsrfCanUnapproveAndStaffCannotBeTargeted(): void
    {
        $this->client->jsonRequest('POST', $this->url()); self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($this->owner); $this->post(); self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->admin); $this->client->jsonRequest('POST', $this->url()); self::assertResponseStatusCodeSame(403);
        $this->post($this->admin->getId()); self::assertResponseStatusCodeSame(404);
        $this->owner = $this->em->find(User::class, $this->owner->getId()); $this->owner->setModerator(true); $this->em->flush();
        $this->post(); self::assertResponseStatusCodeSame(404);
        self::assertEmailCount(0);
    }

    public function testUnapprovalPreservesProfileVerificationAndHistoryAndAllowsManualReapproval(): void
    {
        $id = $this->owner->getId(); $creatorId = $this->owner->getCreator()->getId();
        $this->client->loginUser($this->admin); $this->post(); self::assertResponseIsSuccessful(); self::assertEmailCount(1);
        $message = self::getMailerMessage(); self::assertInstanceOf(Email::class, $message);
        self::assertSame('Your Wave profile is awaiting approval again', $message->getSubject());
        self::assertEmailTextBodyContains($message, 'creator types');
        self::assertEmailTextBodyContains($message, 'photo or logo');
        self::assertEmailHtmlBodyContains($message, '/en/account');
        $db = $this->em->getConnection(); $this->em->clear(); $saved = $this->em->find(User::class, $id);
        self::assertFalse($saved->isApproved()); self::assertTrue($saved->isHideMyAccount()); self::assertTrue($saved->isEmailVerified());
        self::assertSame($creatorId, $saved->getCreator()->getId());
        self::assertSame('<p>Saved bio.</p>', $saved->getCreator()->getBio());
        self::assertCount(1, $saved->getCreator()->getSocialProfiles()); self::assertSame(['ugc_creator'], $saved->getCreator()->getCreatorTypes());
        self::assertSame('preserved-creator', $saved->getCreator()->getSlug());
        $this->post(); self::assertResponseIsSuccessful(); self::assertEmailCount(0);
        $this->client->request('GET', '/api/admin/catalog/registrations');
        self::assertContains($id, array_column($this->payload()['data'], 'id'));
        $this->client->request('GET', '/api/admin/catalog/creators'); self::assertSame([], $this->payload()['data']);
        $this->client->request('GET', '/api/auth/csrf'); $csrf = $this->payload()['csrfToken'];
        $this->client->jsonRequest('POST', '/api/admin/registrations/'.$id.'/approve?locale=en', [], ['HTTP_X_CSRF_TOKEN' => $csrf]); self::assertResponseIsSuccessful();
        self::assertTrue((bool) $db->fetchOne('SELECT approved FROM wave_user WHERE id = ?', [$id]));
    }

    #[DataProvider('companyTypes')]
    public function testCompaniesAndAgenciesReceiveRelevantCompanyTips(string $type): void
    {
        $company = new User($type.'-unapproval@example.test', 'ROLE_COMPANY', true); $company->setPassword('unused'); $company->setPreferredLocale('bs');
        $profile = new Company($type.'-unapproval', 'Sačuvana kompanija', 'Beauty'); $profile->setCompanyType($type); $company->setCompany($profile);
        $this->em->persist($company); $this->em->persist($profile); $this->em->flush();
        $this->client->loginUser($this->admin); $this->post($company->getId()); self::assertResponseIsSuccessful();
        $message = self::getMailerMessage(); self::assertInstanceOf(Email::class, $message);
        self::assertEmailHtmlBodyContains($message, 'brend ili agencija');
        self::assertEmailHtmlBodyContains($message, 'Sačuvana kompanija');
        self::assertSame($company->getEmail(), $message->getTo()[0]->getAddress());
    }

    public static function companyTypes(): iterable { yield ['brand']; yield ['agency']; }

    public function testTransportFailureRollsBackUnapproval(): void
    {
        $mailer = $this->createMock(MailerInterface::class); $mailer->expects(self::once())->method('send')->willThrowException(new TransportException('Unavailable'));
        static::getContainer()->set(MailerInterface::class, $mailer);
        $id = $this->owner->getId(); $this->client->loginUser($this->admin); $this->post(); self::assertResponseStatusCodeSame(503);
        $db = static::getContainer()->get('doctrine')->getConnection();
        self::assertTrue((bool) $db->fetchOne('SELECT approved FROM wave_user WHERE id = ?', [$id]));
        self::assertFalse((bool) $db->fetchOne('SELECT hide_my_account FROM wave_user WHERE id = ?', [$id]));
    }

    public function testTemplateIsEditableAndEveryLocaleRendersWithoutPlaceholders(): void
    {
        $sender = static::getContainer()->get(AccountEmailSender::class); $renderer = static::getContainer()->get(EmailTemplateRenderer::class);
        foreach (['bs', 'hr', 'sr', 'cnr', 'sl', 'en', 'de'] as $locale) {
            $html = $renderer->render('approval_revoked', $sender->previewVariables('approval_revoked', $locale));
            self::assertStringNotContainsString('{{', $html); self::assertStringContainsString('100%', $html);
            self::assertSame([], $renderer->unknownVariables('approval_revoked', $renderer->getDefaultHtml('approval_revoked')));
        }
        $this->em->persist(new EmailTemplate('approval_revoked', 'en', 'Please update {{recipientName}}', '<html><body>{{heading}} <a href="{{actionUrl}}">{{buttonLabel}}</a></body></html>')); $this->em->flush();
        $this->client->loginUser($this->admin); $this->post();
        self::assertResponseIsSuccessful(); self::assertSame('Please update Saved Creator', self::getMailerMessage()->getSubject());
    }

    private function url(?int $id = null): string { return '/api/admin/registrations/'.($id ?? $this->owner->getId()).'/unapprove?locale=en'; }
    private function post(?int $id = null): void { $this->client->request('GET', '/api/auth/csrf'); $csrf = $this->payload()['csrfToken']; $this->client->jsonRequest('POST', $this->url($id), [], ['HTTP_X_CSRF_TOKEN' => $csrf]); }
    private function payload(): array { return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR); }
}
