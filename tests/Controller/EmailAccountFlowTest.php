<?php

namespace App\Tests\Controller;

use App\Account\UserActionTokenManager;
use App\Account\EmailTemplateRenderer;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class EmailAccountFlowTest extends WebTestCase
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

    public function testRegistrationSendsSingleUseEmailVerificationLink(): void
    {
        $csrf = $this->csrfToken();
        $this->jsonRequest('POST', '/api/auth/register', [
            'accountType' => 'creator',
            'email' => 'verify@example.test',
            'password' => 'a-long-passphrase-for-wave',
            'firstName' => 'Verify',
            'lastName' => 'Creator',
            'phone' => '+387 61 123 456',
            'category' => 'Travel',
            'city' => 'Sarajevo',
            'country' => 'BA',
            'location' => 'Sarajevo',
        ], $csrf);

        self::assertResponseStatusCodeSame(201);
        $registration = $this->payload();
        self::assertFalse($registration['data']['emailVerified']);
        self::assertFalse($registration['data']['approved']);
        self::assertEmailCount(1);
        $originalToken = $this->tokenFromMessage(0);
        $this->assertBrandedEmail(0, 'Registracija za Wave je zaprimljena', 'Potvrdi e-mail adresu', $originalToken);
        self::assertEmailHtmlBodyContains(self::getMailerMessage(), 'račun će čekati odobrenje');

        $this->jsonRequest('POST', '/api/auth/verification-email', [], $registration['csrfToken']);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        $token = $this->tokenFromMessage(0);
        self::assertNotSame($originalToken, $token);

        $this->jsonRequest('POST', '/api/auth/verify-email', ['token' => $originalToken], $registration['csrfToken']);
        self::assertResponseStatusCodeSame(400);
        $this->jsonRequest('POST', '/api/auth/verify-email', ['token' => $token], $registration['csrfToken']);
        self::assertResponseIsSuccessful();
        $verification = $this->payload();
        self::assertTrue($verification['data']['emailVerified']);
        self::assertStringContainsString('Tvoj račun sada čeka odobrenje administratora.', $verification['message']);
        $this->jsonRequest('POST', '/api/auth/verify-email', ['token' => $token], $registration['csrfToken']);
        self::assertResponseStatusCodeSame(400);
        $verifiedUser = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)->findOneBy(['email' => 'verify@example.test']);
        self::assertTrue($verifiedUser->isEmailVerified());
        self::assertFalse($verifiedUser->isApproved());
    }

    public function testPasswordResetResponseDoesNotRevealAccountAndChangesPassword(): void
    {
        $this->jsonRequest('POST', '/api/auth/register', [
            'accountType' => 'creator',
            'email' => 'reset@example.test',
            'password' => 'a-long-passphrase-for-wave',
            'firstName' => 'Reset',
            'lastName' => 'Creator',
            'phone' => '+387 61 123 456',
            'category' => 'Travel',
            'city' => 'Sarajevo',
            'country' => 'BA',
            'location' => 'Sarajevo',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(201);
        $csrf = $this->payload()['csrfToken'];
        self::assertEmailCount(1);

        $this->jsonRequest('POST', '/api/auth/password-reset-requests', ['email' => 'reset@example.test'], $csrf);
        self::assertResponseStatusCodeSame(202);
        $knownAccountResponse = $this->payload();
        self::assertEmailCount(1);
        $resetToken = $this->tokenFromMessage(0);
        $this->assertBrandedEmail(0, 'Promijeni lozinku za Wave', 'Postavi novu lozinku', $resetToken);

        $this->jsonRequest('POST', '/api/auth/password-reset-requests', ['email' => 'missing@example.test'], $csrf);
        self::assertResponseStatusCodeSame(202);
        self::assertSame($knownAccountResponse, $this->payload());
        self::assertEmailCount(0);

        $newPassword = 'another-long-passphrase-for-wave';
        $this->jsonRequest('POST', '/api/auth/password-resets', [
            'token' => $resetToken,
            'password' => $newPassword,
        ], $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame('Lozinka je promijenjena. Možeš se prijaviti novom lozinkom.', $this->payload()['message']);
        $this->jsonRequest('POST', '/api/auth/password-resets', [
            'token' => $resetToken,
            'password' => $newPassword,
        ], $csrf);
        self::assertResponseStatusCodeSame(400);

        $this->jsonRequest('POST', '/api/auth/login', [
            'email' => 'reset@example.test',
            'password' => 'a-long-passphrase-for-wave',
        ], $csrf);
        self::assertResponseStatusCodeSame(401);
        $this->jsonRequest('POST', '/api/auth/login', [
            'email' => 'reset@example.test',
            'password' => $newPassword,
        ], $csrf);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['data']['emailVerified']);
    }

    public function testExpiredPasswordResetTokenIsRejected(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('expired@example.test', 'ROLE_CREATOR');
        $user->setPassword('already-hashed-test-password');
        $user->setCreator(new Creator('expired-creator', 'Expired Creator', 'Travel', 'Sarajevo', '', [], []));
        $entityManager->persist($user);
        $entityManager->flush();
        $token = static::getContainer()->get(UserActionTokenManager::class)
            ->issue($user, 'reset_password', new DateTimeImmutable('-1 second'));

        $this->jsonRequest('POST', '/api/auth/password-resets', [
            'token' => $token,
            'password' => 'a-new-long-passphrase-for-wave',
        ], $this->csrfToken());

        self::assertResponseStatusCodeSame(400);
    }

    public function testUnverifiedCreatorCannotApplyToCampaign(): void
    {
        $user = new User('pending@example.test', 'ROLE_CREATOR');
        $user->setPassword('unused-test-hash');
        $user->setEmailVerified(false);
        $user->setCreator(new Creator('pending-creator', 'Pending Creator', 'Travel', 'Sarajevo', '', [], []));
        static::getContainer()->get(EntityManagerInterface::class)->persist($user);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($user, 'main');

        $this->jsonRequest('POST', '/api/campaigns/any-campaign/applications', [
            'message' => 'I would love to create this campaign with you.',
        ], $this->csrfToken());

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Potvrdi svoju e-mail adresu prije ove radnje.', $this->payload()['error']);
    }

    public function testUnverifiedCompanyCannotPublishCampaign(): void
    {
        $user = new User('pending-company@example.test', 'ROLE_COMPANY');
        $user->setPassword('unused-test-hash');
        $user->setEmailVerified(false);
        $user->setCompany(new Company('pending-company', 'Pending Company', 'Food'));
        static::getContainer()->get(EntityManagerInterface::class)->persist($user);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($user, 'main');

        $this->jsonRequest('POST', '/api/company/campaigns', [], $this->csrfToken());

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Potvrdi svoju e-mail adresu prije ove radnje.', $this->payload()['error']);
    }

    public function testEmailTemplateEscapesInjectedVariables(): void
    {
        $html = static::getContainer()->get(EmailTemplateRenderer::class)->render('account_action', [
            'locale' => 'en',
            'preheader' => '<script>alert(1)</script>',
            'eyebrow' => 'Test',
            'heading' => 'Test',
            'greeting' => 'Hello',
            'message' => 'A test message',
            'buttonLabel' => 'Continue',
            'actionUrl' => 'https://example.test/action?next=">&',
            'expiration' => 'Expires soon',
            'security' => 'Ignore if unexpected',
            'fallback' => 'Copy this link',
            'footer' => 'Wave',
        ]);

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('href="https://example.test/action?next=&quot;&gt;&amp;"', $html);
    }

    private function csrfToken(): string
    {
        $this->client->request('GET', '/api/auth/csrf?locale=bs');
        self::assertResponseIsSuccessful();

        return $this->payload()['csrfToken'];
    }

    private function jsonRequest(string $method, string $path, array $body, string $csrf): void
    {
        $this->client->request($method, $path.'?locale=bs', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function tokenFromMessage(int $index): string
    {
        $email = self::getMailerMessage($index);
        self::assertInstanceOf(Email::class, $email);
        self::assertIsString($email->getTextBody());
        self::assertSame(1, preg_match('/#([a-f0-9]{64})\b/', $email->getTextBody(), $matches));

        return $matches[1];
    }

    private function assertBrandedEmail(int $index, string $subject, string $buttonLabel, string $token): void
    {
        $email = self::getMailerMessage($index);
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailSubjectContains($email, $subject);
        self::assertSame('no-reply@wave.ba', $email->getFrom()[0]->getAddress());
        self::assertSame('Wave', $email->getFrom()[0]->getName());
        self::assertEmailHtmlBodyContains($email, 'width="600"');
        self::assertEmailHtmlBodyContains($email, $buttonLabel);
        self::assertEmailHtmlBodyContains($email, '#'.$token);
        self::assertEmailTextBodyContains($email, '#'.$token);
    }
}
