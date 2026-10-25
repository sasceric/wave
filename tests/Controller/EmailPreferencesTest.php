<?php

namespace App\Tests\Controller;

use App\Account\EmailPreferences;
use App\Background\ProfileReminderBatch;
use App\Entity\Creator;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class EmailPreferencesTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testSignedLinksShowAndChangeChoicesWithoutSigningIn(): void
    {
        $user = $this->creator();
        $preferences = static::getContainer()->get(EmailPreferences::class);
        $query = 'u='.$user->getId().'&s='.$preferences->signature((int) $user->getId());

        $this->client->request('GET', '/api/email/preferences?locale=en&'.$query);
        self::assertResponseIsSuccessful();
        self::assertSame(['email' => 'am•••@example.test', 'profileTips' => true, 'newsletter' => true, 'campaignAlerts' => null], $this->payload()['data']);

        $this->client->request('POST', '/api/email/unsubscribe?c=profile_tips&'.$query, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'List-Unsubscribe=One-Click');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->fresh($user)->isProfileTipsEnabled());

        $this->json('PUT', '/api/email/preferences?locale=en&'.$query, ['profileTips' => true, 'newsletter' => false], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['data']['profileTips']);
        self::assertFalse($this->payload()['data']['newsletter']);

        foreach (['u='.$user->getId().'&s=wrong', 'u=0&s=x', 's='.$preferences->signature((int) $user->getId())] as $bad) {
            $this->client->request('GET', '/api/email/preferences?locale=en&'.$bad);
            self::assertResponseStatusCodeSame(404);
        }
        $this->client->request('POST', '/api/email/unsubscribe?c=security&'.$query);
        self::assertResponseStatusCodeSame(400);
        $this->json('PUT', '/api/email/preferences?locale=en&'.$query, ['profileTips' => 'no'], $this->csrfToken());
        self::assertResponseStatusCodeSame(400);
    }

    public function testSignedInAccountsChangeTheirOwnChoices(): void
    {
        $user = $this->creator();
        $this->client->loginUser($user, 'main');
        $this->json('PUT', '/api/me/email-preferences?locale=en', ['newsletter' => false], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame(['profileTips' => true, 'newsletter' => false], $this->payload()['data']['emailPreferences']);
        $this->json('PUT', '/api/me/email-preferences?locale=en', ['campaignAlerts' => false], $this->csrfToken());
        self::assertResponseStatusCodeSame(400);
    }

    public function testProfileRemindersFollowTheScheduleAndStop(): void
    {
        $user = $this->creator();
        $batch = static::getContainer()->get(ProfileReminderBatch::class);
        $db = $this->em->getConnection();

        // First run only starts the series.
        self::assertSame(0, $batch->run(false));
        self::assertEmailCount(0);
        self::assertNotNull($db->fetchOne('SELECT profile_reminder_next_at FROM wave_user WHERE id = ?', [$user->getId()]));

        $db->executeStatement('UPDATE wave_user SET profile_reminder_next_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $user->getId()]);
        self::assertSame(1, $batch->run(false));
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('amina@example.test', $email->getTo()[0]->getAddress());
        self::assertEmailHtmlBodyContains($email, '%');
        self::assertStringContainsString('/api/email/unsubscribe?u='.$user->getId(), $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString() ?? '');
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        self::assertSame(1, (int) $db->fetchOne('SELECT profile_reminder_stage FROM wave_user WHERE id = ?', [$user->getId()]));

        // Not due yet: nothing more is sent.
        self::assertSame(0, $batch->run(false));
        self::assertEmailCount(1);

        // Switched off: due, but skipped.
        $db->executeStatement('UPDATE wave_user SET profile_reminder_next_at = ?, profile_tips_enabled = FALSE WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $user->getId()]);
        self::assertSame(0, $batch->run(false));
        self::assertEmailCount(1);
    }

    private function creator(): User
    {
        $user = new User('amina@example.test', 'ROLE_CREATOR');
        $user->setPassword('unused-test-hash');
        $user->setPhone('+38761123456');
        $user->setCity('Sarajevo');
        $user->setCountryCode('BA');
        $user->setPreferredLocale('en');
        $user->setCreator(new Creator('amina', 'Amina Kovač', 'Travel', 'Sarajevo', '', [], categories: ['Travel']));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function fresh(User $user): User
    {
        $this->em->clear();
        $fresh = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $fresh);

        return $fresh;
    }

    private function csrfToken(): string
    {
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        self::assertResponseIsSuccessful();

        return $this->payload()['csrfToken'];
    }

    /** @param array<string, mixed> $body */
    private function json(string $method, string $path, array $body, string $csrf): void
    {
        $this->client->request($method, $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
