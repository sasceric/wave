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

final class AccountEmailTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private User $other;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $this->owner = new User('owner@example.test', 'ROLE_CREATOR');
        $this->owner->setPassword($hasher->hashPassword($this->owner, 'a-long-passphrase-for-wave'));
        $this->owner->setPreferredLocale('en');
        $this->other = new User('other@example.test', 'ROLE_COMPANY');
        $this->other->setPassword($hasher->hashPassword($this->other, 'another-long-passphrase'));
        $this->em->persist($this->owner);
        $this->em->persist($this->other);
        $this->em->flush();
    }

    public function testExtraAddressIsConfirmedUsedForSignInMadeMainAndRemoved(): void
    {
        $this->client->loginUser($this->owner, 'main');
        $this->json('POST', '/api/me/emails', ['email' => 'Work@Example.test', 'password' => 'wrong-password']);
        self::assertResponseStatusCodeSame(400);
        $this->json('POST', '/api/me/emails', ['email' => 'work@example.test', 'password' => 'a-long-passphrase-for-wave']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([['id' => $this->payload()['data']['emails'][0]['id'], 'email' => 'work@example.test', 'verified' => false]], $this->payload()['data']['emails']);
        self::assertStringContainsString('work@example.test', $this->payload()['message']);
        $id = $this->payload()['data']['emails'][0]['id'];
        self::assertEmailCount(2);
        $verify = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $verify);
        self::assertSame('work@example.test', $verify->getTo()[0]->getAddress());
        self::assertEmailHtmlBodyContains($verify, '?extra=1#');
        $notice = self::getMailerMessage(1);
        self::assertSame('owner@example.test', $notice->getTo()[0]->getAddress());
        self::assertEmailHtmlBodyContains($notice, 'the email address work@example.test was added');

        // Not confirmed yet: no sign-in, no main address.
        $this->json('POST', '/api/me/emails/'.$id.'/primary', ['password' => 'a-long-passphrase-for-wave']);
        self::assertResponseStatusCodeSame(409);
        $this->json('POST', '/api/auth/logout', []);
        $this->client->restart();
        $this->json('POST', '/api/auth/login', ['email' => 'work@example.test', 'password' => 'a-long-passphrase-for-wave']);
        self::assertResponseStatusCodeSame(401);

        self::assertSame(1, preg_match('/#([a-f0-9]{64})/', (string) $verify->getHtmlBody(), $match));
        $this->json('POST', '/api/auth/verify-extra-email', ['token' => $match[1]]);
        self::assertResponseIsSuccessful();
        $this->json('POST', '/api/auth/verify-extra-email', ['token' => $match[1]]);
        self::assertResponseStatusCodeSame(400);

        $this->json('POST', '/api/auth/login', ['email' => 'work@example.test', 'password' => 'a-long-passphrase-for-wave']);
        self::assertResponseIsSuccessful();
        self::assertSame('owner@example.test', $this->payload()['data']['email']);

        $this->json('POST', '/api/me/emails/'.$id.'/primary', ['password' => 'a-long-passphrase-for-wave']);
        self::assertResponseIsSuccessful();
        self::assertSame('work@example.test', $this->payload()['data']['primary']);
        self::assertSame('owner@example.test', $this->payload()['data']['emails'][0]['email']);
        self::assertTrue($this->payload()['data']['emails'][0]['verified']);
        $this->client->request('GET', '/api/me/sign-in?locale=en');
        self::assertResponseIsSuccessful(); // the session survived the main address change

        $this->json('POST', '/api/auth/login', ['email' => 'owner@example.test', 'password' => 'a-long-passphrase-for-wave']);
        self::assertResponseIsSuccessful();
        $old = $this->payload()['data'];
        $this->client->request('GET', '/api/me/sign-in?locale=en');
        $oldId = $this->payload()['data']['emails'][0]['id'];
        $this->json('DELETE', '/api/me/emails/'.$oldId, []);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']['emails']);
        self::assertSame('work@example.test', $old['email']);
    }

    public function testAddressesOfOtherAccountsAreRefusedEverywhere(): void
    {
        $this->client->loginUser($this->owner, 'main');
        $this->json('POST', '/api/me/emails', ['email' => 'other@example.test', 'password' => 'a-long-passphrase-for-wave']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('email_belongs_to_other', $this->payload()['code']);
        self::assertSame('This email address already belongs to another Wave account.', $this->payload()['error']);
        $this->json('POST', '/api/me/emails', ['email' => 'owner@example.test', 'password' => 'a-long-passphrase-for-wave']);
        self::assertSame('email_already_yours', $this->payload()['code']);

        $this->json('POST', '/api/me/emails', ['email' => 'shared@example.test', 'password' => 'a-long-passphrase-for-wave']);
        self::assertResponseStatusCodeSame(201);
        $this->client->loginUser($this->other, 'main');
        $this->json('POST', '/api/me/emails', ['email' => 'shared@example.test', 'password' => 'another-long-passphrase']);
        self::assertSame('email_belongs_to_other', $this->payload()['code']);

        // Registration cannot reuse an extra address either.
        $this->json('POST', '/api/auth/logout', []);
        $this->client->restart();
        $this->json('POST', '/api/auth/register', [
            'accountType' => 'creator', 'termsAccepted' => true, 'termsVersion' => \App\Legal\LegalPolicies::VERSION,
            'email' => 'shared@example.test', 'password' => 'a-long-passphrase-for-wave', 'firstName' => 'Shared', 'lastName' => 'Address',
            'phone' => '+387 61 123 456', 'category' => 'Travel', 'city' => 'Sarajevo', 'country' => 'BA', 'location' => 'Sarajevo',
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testExtraAddressesAreLimitedAndPasswordResetGoesToTheTypedAddress(): void
    {
        $this->client->loginUser($this->owner, 'main');
        foreach (['a', 'b', 'c'] as $name) {
            $this->json('POST', '/api/me/emails', ['email' => $name.'@example.test', 'password' => 'a-long-passphrase-for-wave']);
            self::assertResponseStatusCodeSame(201);
        }
        $this->json('POST', '/api/me/emails', ['email' => 'd@example.test', 'password' => 'a-long-passphrase-for-wave']);
        self::assertResponseStatusCodeSame(422);
        $extra = $this->em->getRepository(\App\Entity\UserEmail::class)->findOneBy(['email' => 'a@example.test']);
        $extra->markVerified();
        $this->em->flush();

        $this->json('POST', '/api/auth/logout', []);
        $this->client->restart();
        $this->json('POST', '/api/auth/password-reset-requests', ['email' => 'a@example.test']);
        self::assertResponseStatusCodeSame(202);
        $reset = self::getMailerMessage();
        self::assertSame('a@example.test', $reset->getTo()[0]->getAddress());
    }

    public function testTheLastSignInMethodCannotBeRemoved(): void
    {
        $social = new User('social@example.test', 'ROLE_CREATOR');
        $social->setPassword('unused');
        $social->markPasswordUnknown();
        $this->em->persist($social);
        $this->em->persist(new OAuthIdentity($social, 'google', 'google-subject', 'social@gmail.example'));
        $this->em->persist(new OAuthIdentity($this->owner, 'apple', 'apple-subject', 'relay@privaterelay.appleid.com'));
        $this->em->flush();

        $this->client->loginUser($social, 'main');
        $this->client->request('GET', '/api/me/sign-in?locale=en');
        self::assertFalse($this->payload()['data']['passwordKnown']);
        self::assertSame('social@gmail.example', $this->payload()['data']['identities'][0]['email']);
        $this->json('DELETE', '/api/me/identities/google', []);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('oauth_last_method', $this->payload()['code']);
        // A Google-only account adds an address without a password it never set.
        $this->json('POST', '/api/me/emails', ['email' => 'social-extra@example.test']);
        self::assertResponseStatusCodeSame(201);

        $this->client->loginUser($this->owner, 'main');
        $this->json('DELETE', '/api/me/identities/apple', []);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']['identities']);
    }

    private function json(string $method, string $path, array $body): void
    {
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $csrf = json_decode((string) $this->client->getResponse()->getContent(), true)['csrfToken'];
        $this->client->request($method, $path.'?locale=en', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
