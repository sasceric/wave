<?php

namespace App\Tests\Controller;

use App\Account\AccountEmailSender;
use App\Account\EmailTemplateRenderer;
use App\Account\UserActionTokenManager;
use App\Entity\Creator;
use App\Entity\Company;
use App\Entity\EmailTemplate;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class RegistrationReminderTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private User $pending;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $this->admin = new User('reminder-admin@example.test', 'ROLE_COMPANY');
        $this->admin->setAdmin(true);
        $this->admin->setPassword('unused');
        $this->pending = new User('reminder-pending@example.test', 'ROLE_CREATOR');
        $this->pending->setPassword('unused');
        $this->pending->setApproved(false);
        $this->pending->setEmailVerified(false);
        $this->pending->setPreferredLocale('en');
        $this->pending->setCreator(new Creator('reminder-pending', 'Pending Creator', 'Beauty', 'Sarajevo', '', []));
        $this->em->persist($this->admin);
        $this->em->persist($this->pending);
        $this->em->flush();
    }

    public function testRequiresAdminAndCsrfAndValidatesCurrentRecipientState(): void
    {
        $url = $this->url();
        $this->client->jsonRequest('POST', $url, ['kind' => 'verification']);
        self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($this->pending);
        $this->client->jsonRequest('POST', $url, ['kind' => 'verification']);
        self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->admin);
        $this->client->jsonRequest('POST', $url, ['kind' => 'verification']);
        self::assertResponseStatusCodeSame(403);
        $this->send('invalid');
        self::assertResponseStatusCodeSame(400);
        $this->pending = $this->em->find(User::class, $this->pending->getId());
        $this->pending->setEmailVerified(true);
        $this->em->flush();
        $this->send('verification');
        self::assertResponseStatusCodeSame(400);
        $this->pending = $this->em->find(User::class, $this->pending->getId());
        $this->pending->setApproved(true);
        $this->em->flush();
        $this->send('verification');
        self::assertResponseStatusCodeSame(400);
        self::assertEmailCount(0);
    }

    #[DataProvider('approvedAccountTypes')]
    public function testApprovedProfileListsCanSendEditableProfileTipsToTheirAccountOwners(string $type): void
    {
        $owner = new User($type.'-owner@example.test', $type === 'creators' ? 'ROLE_CREATOR' : 'ROLE_COMPANY');
        $owner->setPassword('unused');
        $owner->setApproved(true);
        $owner->setEmailVerified(true);
        $owner->setPreferredLocale('en');
        if ($type === 'creators') {
            $owner->setCreator(new Creator('approved-creator', 'Approved Creator', 'Beauty', 'Sarajevo', '', []));
        } else {
            $owner->setCompany(new Company('approved-company', 'Approved Company', 'Beauty'));
        }
        $this->em->persist($owner);
        $this->em->persist(new EmailTemplate('profile_tip', 'en', 'Complete your profile', '<html><body>Hi {{recipientName}}. <a href="{{actionUrl}}">Edit your profile</a></body></html>'));
        $this->em->flush();
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/api/admin/catalog/'.$type);
        self::assertResponseIsSuccessful();
        $rows = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $row = current(array_filter($rows, static fn (array $row): bool => $row['accountId'] === $owner->getId()));
        self::assertIsArray($row);
        self::assertNotSame($row['id'], $row['accountId']);
        self::assertSame($owner->getEmail(), $row['email']);
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['csrfToken'];
        $url = '/api/admin/registrations/'.$row['accountId'].'/reminder';
        $this->client->jsonRequest('POST', $url, ['kind' => 'profile'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame($owner->getEmail(), $message->getTo()[0]->getAddress());
        self::assertEmailSubjectContains($message, 'Complete your profile');
        self::assertEmailHtmlBodyContains($message, $type === 'creators' ? 'Approved Creator' : 'Approved Company');
        self::assertEmailHtmlBodyContains($message, '/en/');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_action_token'));
        $this->client->jsonRequest('POST', $url, ['kind' => 'profile'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(429);
        self::assertEmailCount(0);
    }

    public static function approvedAccountTypes(): iterable
    {
        yield 'creator' => ['creators'];
        yield 'company' => ['companies'];
    }

    public function testVerificationUsesEditedRecipientLanguageTemplateAndFreshSingleUseToken(): void
    {
        $this->client->loginUser($this->admin);
        $tokens = self::getContainer()->get(UserActionTokenManager::class);
        $old = $tokens->issue($this->pending, 'verify_email');
        $this->em->persist(new EmailTemplate('verification_reminder', 'en', 'Custom confirmation reminder', '<html><body>Hello {{recipientName}} <a href="{{actionUrl}}">Confirm</a></body></html>'));
        $this->em->flush();
        $this->send('verification');
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertEmailSubjectContains($message, 'Custom confirmation reminder');
        self::assertEmailHtmlBodyContains($message, 'Pending Creator');
        self::assertEmailHtmlBodyContains($message, '/en/');
        self::assertSame($this->pending->getEmail(), $message->getTo()[0]->getAddress());
        self::assertSame(1, preg_match('/#([a-f0-9]{64})/', $message->getHtmlBody(), $match));
        self::assertNotSame($old, $match[1]);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE purpose = ?', ['verify_email']));
        $tokenRows = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_action_token');
        $this->send('verification');
        self::assertResponseStatusCodeSame(429);
        self::assertSame($tokenRows, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_action_token'));
        self::assertTrue($tokens->consume($match[1], 'verify_email', static function (User $user): void { $user->setEmailVerified(true); }));
        self::assertFalse($tokens->consume($old, 'verify_email', static function (User $user): void { $user->setEmailVerified(true); }));
        self::assertFalse($tokens->consume($match[1], 'verify_email', static function (User $user): void { $user->setEmailVerified(true); }));
        self::assertFalse($this->pending->isApproved());
    }

    public function testAdminReminderPreservesOriginalLinkUntilItIsUsed(): void
    {
        $tokens = self::getContainer()->get(UserActionTokenManager::class);
        $original = $tokens->issue($this->pending, 'verify_email');
        $reset = $tokens->issue($this->pending, 'reset_password', new DateTimeImmutable('+1 hour'));
        $this->client->loginUser($this->admin);
        $this->send('verification');
        self::assertResponseIsSuccessful();
        self::assertTrue($tokens->consume($original, 'verify_email', static function (User $user): void { $user->setEmailVerified(true); }));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_action_token WHERE purpose = ?', ['verify_email']));
        self::assertTrue($tokens->consume($reset, 'reset_password', static function (User $user): void {}));
    }

    public function testVerificationDoesNotExpireButPasswordResetStillExpiresAndReplacesOldLinks(): void
    {
        $tokens = self::getContainer()->get(UserActionTokenManager::class);
        $original = $tokens->issue($this->pending, 'verify_email');
        $this->em->getConnection()->executeStatement("UPDATE user_action_token SET created_at = '2020-01-01 00:00:00' WHERE purpose = 'verify_email'");
        self::assertNull($this->em->getConnection()->fetchOne("SELECT expires_at FROM user_action_token WHERE purpose = 'verify_email'"));
        $expiredReset = $tokens->issue($this->pending, 'reset_password', new DateTimeImmutable('-1 minute'));
        self::assertFalse($tokens->consume($expiredReset, 'reset_password', static function (User $user): void {}));
        $oldReset = $tokens->issue($this->pending, 'reset_password', new DateTimeImmutable('+1 hour'));
        $newReset = $tokens->issue($this->pending, 'reset_password', new DateTimeImmutable('+1 hour'));
        self::assertFalse($tokens->consume($oldReset, 'reset_password', static function (User $user): void {}));
        self::assertTrue($tokens->consume($newReset, 'reset_password', static function (User $user): void {}));
        self::assertTrue($tokens->consume($original, 'verify_email', static function (User $user): void { $user->setEmailVerified(true); }));
    }

    public function testPasswordResetCannotBeIssuedWithoutAnExpiry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(UserActionTokenManager::class)->issue($this->pending, 'reset_password');
    }

    public function testProfileTipIsEditableDoesNotVerifyApproveOrIssueTokensAndHasItsOwnDailyLimit(): void
    {
        $this->client->loginUser($this->admin);
        $this->em->persist(new EmailTemplate('profile_tip', 'en', 'A better profile', '<html><body>Hi {{recipientName}}. <a href="{{actionUrl}}">Edit your profile</a></body></html>'));
        $this->em->flush();
        $this->send('profile');
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertEmailSubjectContains($message, 'A better profile');
        self::assertEmailHtmlBodyContains($message, 'Hi Pending Creator');
        self::assertStringNotContainsString('#', $message->getHtmlBody());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_action_token'));
        self::assertFalse($this->pending->isEmailVerified());
        self::assertFalse($this->pending->isApproved());
        $this->send('profile');
        self::assertResponseStatusCodeSame(429);
        $this->send('verification');
        self::assertResponseIsSuccessful();
    }

    public function testPreApprovalSendsTheRequiredChecklistWithoutChangingAccountStateAndHasADailyLimit(): void
    {
        $this->client->loginUser($this->admin);
        $this->send('pre_approval');
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('Three things before we approve your Wave profile', $message->getSubject());
        self::assertEmailHtmlBodyContains($message, 'Pending Creator');
        self::assertEmailHtmlBodyContains($message, 'Your photo or logo');
        self::assertEmailHtmlBodyContains($message, 'Your story, briefly');
        self::assertEmailHtmlBodyContains($message, 'Your social profiles');
        self::assertEmailTextBodyContains($message, 'We will not approve a profile without them.');
        self::assertEmailTextBodyContains($message, 'Aim for 100% completion.');
        self::assertEmailHtmlBodyContains($message, '/en/account');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_action_token'));
        self::assertFalse($this->pending->isApproved()); self::assertFalse($this->pending->isEmailVerified());
        $this->send('pre_approval'); self::assertResponseStatusCodeSame(429);
        $this->pending = $this->em->find(User::class, $this->pending->getId());
        $this->pending->setApproved(true); $this->em->flush();
        $this->send('pre_approval'); self::assertResponseStatusCodeSame(400);
    }

    public function testPreApprovalUsesThePendingCompanyOwnersLanguageAndPreservesEditedTemplates(): void
    {
        $company = new User('pre-approval-company@example.test', 'ROLE_COMPANY');
        $company->setPassword('unused'); $company->setPreferredLocale('bs'); $company->setApproved(false);
        $company->setCompany(new Company('pre-approval-company', 'Nova kompanija', 'Beauty'));
        $this->em->persist($company);
        $this->em->persist(new EmailTemplate('pre_approval', 'bs', 'Pripremi {{recipientName}}', '<html><body>{{recipientName}} <h1>{{heading}}</h1><p>{{imageText}}</p><a href="{{actionUrl}}">{{buttonLabel}}</a></body></html>'));
        $this->em->flush(); $this->client->loginUser($this->admin);
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = json_decode((string) $this->client->getResponse()->getContent(), true)['csrfToken'];
        $this->client->jsonRequest('POST', '/api/admin/registrations/'.$company->getId().'/reminder?locale=en', ['kind' => 'pre_approval'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        $message = self::getMailerMessage(); self::assertInstanceOf(Email::class, $message);
        self::assertSame('Pripremi Nova kompanija', $message->getSubject());
        self::assertSame($company->getEmail(), $message->getTo()[0]->getAddress());
        self::assertEmailHtmlBodyContains($message, 'Pokaži nam šta te izdvaja.');
        self::assertEmailHtmlBodyContains($message, '/racun');
        self::assertFalse($company->isApproved());
    }

    public function testBothDefaultRemindersRenderInEverySupportedLanguage(): void
    {
        $sender = self::getContainer()->get(AccountEmailSender::class);
        $renderer = self::getContainer()->get(EmailTemplateRenderer::class);
        foreach (['bs', 'hr', 'sr', 'sl', 'en', 'cnr', 'de'] as $locale) {
            foreach (['verification_reminder', 'profile_tip', 'pre_approval', 'registered'] as $template) {
                $variables = $sender->previewVariables($template, $locale);
                $html = $renderer->render($template, $variables);
                self::assertNotSame('', $sender->defaultSubject($template, $locale));
                self::assertStringContainsString('wave-logo-v1.png', $html);
                self::assertStringNotContainsString('{{', $html);
                self::assertStringContainsString('Amina Kovač', $html);
                if ($template === 'verification_reminder') {
                    self::assertStringNotContainsString('24', $variables['expiration']);
                    self::assertSame($sender->previewVariables('verify', $locale)['expiration'], $variables['expiration']);
                }
            }
        }
    }

    private function url(): string
    {
        return '/api/admin/registrations/'.$this->pending->getId().'/reminder?locale=bs';
    }

    private function send(string $kind): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['csrfToken'];
        $this->client->jsonRequest('POST', $this->url(), ['kind' => $kind], ['HTTP_X_CSRF_TOKEN' => $csrf]);
    }
}
