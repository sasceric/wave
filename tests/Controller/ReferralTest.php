<?php

namespace App\Tests\Controller;

use App\Entity\Creator;
use App\Entity\CreatorReferral;
use App\Entity\Notification;
use App\Entity\ReferralReward;
use App\Entity\User;
use App\Referral\ReferralProgram;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class ReferralTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private User $amar;

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
        $this->admin = new User('referral-admin@example.test', 'ROLE_COMPANY');
        $this->admin->setAdmin(true);
        $this->admin->setPassword('unused');
        $this->amar = $this->creator('amar@example.test', 'amar-hodzic', 'Amar Hodžić', true);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    public function testInvitedCreatorIsTrackedAndAFlaggedReferralCountsOnlyAfterReview(): void
    {
        $this->client->loginUser($this->amar, 'main');
        $this->client->request('GET', '/api/me/referrals?locale=en');
        self::assertResponseIsSuccessful();
        $mine = $this->payload()['data'];
        self::assertSame('AMAR', $mine['code']);
        self::assertStringEndsWith('/?ref=AMAR', $mine['link']);
        self::assertSame([20, 35, 50], array_column($mine['tiers'], 'at'));

        $this->json('POST', '/api/auth/logout', []);
        $this->client->restart();
        $this->client->request('GET', '/api/referrals/amar?locale=en');
        self::assertSame('Amar Hodžić', $this->payload()['data']['name']);
        $this->client->request('GET', '/api/referrals/NOBODY?locale=en');
        self::assertResponseStatusCodeSame(404);

        $this->json('POST', '/api/auth/register', $this->registration('lejla@example.test', 'creator') + ['referralCode' => 'amar']);
        self::assertResponseStatusCodeSame(201);
        $this->json('POST', '/api/auth/logout', []);
        $this->client->restart();
        // A company never counts toward a creator's referrals.
        $this->json('POST', '/api/auth/register', $this->registration('brand@example.test', 'company') + ['referralCode' => 'AMAR', 'name' => 'Brand Co', 'industries' => ['Fashion']]);
        self::assertResponseStatusCodeSame(201);

        $referrals = $this->em->getRepository(CreatorReferral::class)->findAll();
        self::assertCount(1, $referrals);
        // Same network as the inviter (both 127.0.0.1 here): an admin decides.
        self::assertSame('same_network', $referrals[0]->getFlag());
        self::assertSame('email', ReferralProgram::status($referrals[0]));
        $lejla = $referrals[0]->getReferred();
        $lejla->setEmailVerified(true);
        $lejla->setApproved(true);
        $this->em->flush();
        self::assertSame('review', ReferralProgram::status($referrals[0]));

        $this->client->loginUser($this->amar, 'main');
        $this->client->request('GET', '/api/me/referrals?locale=en');
        self::assertSame(0, $this->payload()['data']['counted']);
        self::assertSame(1, $this->payload()['data']['pending']);
        self::assertSame('review', $this->payload()['data']['referrals'][0]['status']);

        $this->client->loginUser($this->admin, 'main');
        $this->client->request('GET', '/api/admin/referrals?locale=en');
        self::assertSame('same_network', $this->payload()['data']['review'][0]['flag']);
        $this->json('POST', '/api/admin/referrals/'.$referrals[0]->getId().'/decision', ['decision' => 'accept']);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']['review']);
        self::assertSame(1, $this->payload()['data']['ambassadors'][0]['counted']);

        $this->client->loginUser($this->amar, 'main');
        $this->json('POST', '/api/admin/referrals/'.$referrals[0]->getId().'/decision', ['decision' => 'reject']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTwentyApprovedReferralsReachBronzeAndAnAdminActivatesTheRewards(): void
    {
        for ($i = 1; $i <= 19; ++$i) {
            $user = $this->creator("invited{$i}@example.test", "invited-{$i}", "Invited Person{$i}", true);
            $this->em->persist(new CreatorReferral($this->amar, $user, 'AMAR', null));
        }
        $last = $this->creator('last@example.test', 'last-one', 'Last Person', false);
        $this->em->persist(new CreatorReferral($this->amar, $last, 'AMAR', null));
        $this->em->flush();

        $this->client->loginUser($this->admin, 'main');
        $this->json('POST', '/api/admin/registrations/'.$last->getId().'/approve', []);
        self::assertResponseIsSuccessful();
        $reward = $this->em->getRepository(ReferralReward::class)->findOneBy(['user' => $this->amar]);
        self::assertInstanceOf(ReferralReward::class, $reward);
        self::assertSame(20, $reward->getTier());
        self::assertFalse($reward->isApproved());
        $tierEmail = null;
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && $message->getTo()[0]->getAddress() === 'amar@example.test') $tierEmail = $message;
        }
        self::assertInstanceOf(Email::class, $tierEmail);
        self::assertEmailHtmlBodyContains($tierEmail, 'Bronze ambassador');
        self::assertNotNull($this->em->getRepository(Notification::class)->findOneBy(['recipient' => $this->admin, 'type' => 'referral_tier_reached']));

        $this->client->request('GET', '/api/admin/referrals?locale=en');
        $row = $this->payload()['data']['ambassadors'][0];
        self::assertSame(20, $row['counted']);
        self::assertNull($row['rewards'][0]['approvedAt']);

        $this->json('POST', '/api/admin/referral-rewards/'.$reward->getId().'/approve', []);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        $creator = $this->em->find(User::class, $this->amar->getId())->getCreator();
        self::assertSame(20, $creator->getAmbassadorTier());
        self::assertTrue($creator->isFeatured());
        self::assertGreaterThan(new \DateTimeImmutable('+13 days'), $creator->getFeaturedUntil());
        self::assertNotNull($this->em->getRepository(Notification::class)->findOneBy(['type' => 'referral_reward_active']));

        $this->client->request('GET', '/api/creators/amar-hodzic?locale=en');
        self::assertSame(20, $this->payload()['data']['ambassadorTier']);
    }

    public function testLookAlikeEmailsAreFlagged(): void
    {
        self::assertTrue(ReferralProgram::similarEmails('amar.h@gmail.com', 'amarh2+wave@gmail.com'));
        self::assertFalse(ReferralProgram::similarEmails('amar@gmail.com', 'lejla@gmail.com'));
        self::assertFalse(ReferralProgram::similarEmails('ab@gmail.com', 'ab1@gmail.com'));
        self::assertSame('AMAR', ReferralProgram::normalizeCode(' amar '));
        self::assertNull(ReferralProgram::normalizeCode('a-b'));
    }

    private function creator(string $email, string $slug, string $name, bool $approved): User
    {
        $user = new User($email, 'ROLE_CREATOR', true);
        $user->setPassword('unused');
        $user->setEmailVerified(true);
        $user->setApproved($approved);
        $user->setHideMyAccount(false);
        $user->setPreferredLocale('en');
        $user->setCity('Sarajevo');
        $user->setCountryCode('BA');
        $creator = new Creator($slug, $name, 'Travel', 'Sarajevo', '<p>Bio.</p>', []);
        $user->setCreator($creator);
        $this->em->persist($user);
        $this->em->persist($creator);
        $this->em->flush();

        return $user;
    }

    private function registration(string $email, string $type): array
    {
        return [
            'accountType' => $type, 'termsAccepted' => true, 'termsVersion' => \App\Legal\LegalPolicies::VERSION,
            'email' => $email, 'password' => 'a-long-passphrase-for-wave', 'name' => 'Lejla Mujić',
            'phone' => '+387 61 123 456', 'category' => 'Travel', 'city' => 'Sarajevo', 'country' => 'BA',
        ];
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
