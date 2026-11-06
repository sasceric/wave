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
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('ambassadorTiers')]
    public function testApprovedReferralsReachEachTierAndAnAdminActivatesTheRewards(int $tier, string $minimumPeriod): void
    {
        for ($i = 1; $i < $tier; ++$i) {
            $user = $this->creator("invited{$i}@example.test", "invited-{$i}", "Invited Person{$i}", true);
            $this->em->persist(new CreatorReferral($this->amar, $user, 'AMAR', null));
        }
        $last = $this->creator('last@example.test', 'last-one', 'Last Person', false);
        $this->em->persist(new CreatorReferral($this->amar, $last, 'AMAR', null));
        $this->em->flush();

        $this->client->loginUser($this->admin, 'main');
        $this->json('POST', '/api/admin/registrations/'.$last->getId().'/approve', []);
        self::assertResponseIsSuccessful();
        $reward = $this->em->getRepository(ReferralReward::class)->findOneBy(['user' => $this->amar, 'tier' => $tier]);
        self::assertInstanceOf(ReferralReward::class, $reward);
        self::assertSame($tier, $reward->getTier());
        self::assertFalse($reward->isApproved());
        $tierEmail = null;
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && $message->getTo()[0]->getAddress() === 'amar@example.test') $tierEmail = $message;
        }
        self::assertInstanceOf(Email::class, $tierEmail);
        self::assertEmailHtmlBodyContains($tierEmail, \App\Referral\ReferralEmailCopy::tierName($tier, 'en'));
        self::assertNotNull($this->em->getRepository(Notification::class)->findOneBy(['recipient' => $this->admin, 'type' => 'referral_tier_reached']));

        $this->client->request('GET', '/api/admin/referrals?locale=en');
        $row = $this->payload()['data']['ambassadors'][0];
        self::assertSame($tier, $row['counted']);
        self::assertNull($row['rewards'][0]['approvedAt']);

        $this->json('POST', '/api/admin/referral-rewards/'.$reward->getId().'/approve', []);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        $creator = $this->em->find(User::class, $this->amar->getId())->getCreator();
        self::assertSame($tier, $creator->getAmbassadorTier());
        self::assertTrue($creator->isFeatured());
        self::assertGreaterThan(new \DateTimeImmutable($minimumPeriod), $creator->getFeaturedUntil());
        self::assertNotNull($this->em->getRepository(Notification::class)->findOneBy(['type' => 'referral_reward_active']));

        $this->client->request('GET', '/api/creators/amar-hodzic?locale=en');
        self::assertSame($tier, $this->payload()['data']['ambassadorTier']);
        $until = $creator->getFeaturedUntil();
        $notices = $this->em->getRepository(Notification::class)->count(['type' => 'referral_reward_active']);
        $this->json('POST', '/api/admin/referral-rewards/'.$reward->getId().'/approve', []);
        self::assertResponseIsSuccessful();
        $creator = $this->em->find(User::class, $this->amar->getId())->getCreator();
        self::assertEquals($until, $creator->getFeaturedUntil(), 'Repeated approval must not extend the reward twice.');
        self::assertSame($notices, $this->em->getRepository(Notification::class)->count(['type' => 'referral_reward_active']));
    }

    public static function ambassadorTiers(): iterable
    {
        yield 'bronze' => [20, '+13 days'];
        yield 'silver' => [35, '+27 days'];
        yield 'gold' => [50, '+85 days'];
    }

    public function testPendingRewardCannotBeActivatedAfterAReferralIsRejected(): void
    {
        $program = self::getContainer()->get(ReferralProgram::class);
        for ($i = 1; $i <= 20; ++$i) {
            $user = $this->creator("reviewed{$i}@example.test", "reviewed-{$i}", "Reviewed Person{$i}", true);
            $referral = new CreatorReferral($this->amar, $user, 'AMAR', null);
            $this->em->persist($referral);
        }
        $this->em->flush();
        $program->sync($this->amar);
        $reward = $this->em->getRepository(ReferralReward::class)->findOneBy(['user' => $this->amar, 'tier' => 20]);
        self::assertInstanceOf(ReferralReward::class, $reward);
        $this->client->loginUser($this->admin, 'main');
        $this->json('POST', '/api/admin/referrals/'.$referral->getId().'/decision', ['decision' => 'reject']);
        self::assertResponseIsSuccessful();
        $this->json('POST', '/api/admin/referral-rewards/'.$reward->getId().'/approve', []);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('referral_reward_unavailable', $this->payload()['code']);
        $reward = $this->em->find(ReferralReward::class, $reward->getId());
        self::assertFalse($reward->isApproved());
        self::assertSame(0, $this->amar->getCreator()->getAmbassadorTier());
    }

    #[DataProvider('socialProviders')]
    public function testSocialRegistrationKeepsItsCreatorReferral(string $provider): void
    {
        self::getContainer()->get(ReferralProgram::class)->codeFor($this->amar);
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $csrf = $this->payload()['csrfToken'];
        $session = $this->client->getRequest()->getSession();
        $session->set('wave_oauth_registration', [
            'provider' => $provider, 'subject' => 'referred-'.$provider, 'email' => $provider.'@example.test',
            'accountType' => 'creator', 'createdAt' => time(),
        ]);
        $session->save();
        $this->client->jsonRequest('POST', '/api/auth/oauth/complete?locale=en', $this->registration($provider.'@example.test', 'creator') + ['referralCode' => 'AMAR'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        $referral = $this->em->getRepository(CreatorReferral::class)->findOneBy(['referred' => $this->payload()['data']['id']]);
        self::assertInstanceOf(CreatorReferral::class, $referral);
        self::assertSame($this->amar->getId(), $referral->getReferrer()->getId());
        self::assertTrue($referral->getReferred()->isEmailVerified());
        self::assertFalse($referral->getReferred()->isApproved());
        self::assertNotSame('counted', ReferralProgram::status($referral));
    }

    public static function socialProviders(): iterable
    {
        yield 'Google' => ['google'];
        yield 'Apple' => ['apple'];
    }

    public function testEarlyAccessOnlyNotifiesEligibleAmbassadorsAboutPublicCampaigns(): void
    {
        $pending = $this->creator('pending-ambassador@example.test', 'pending-ambassador', 'Pending Ambassador', false);
        foreach ([[$this->amar, 20], [$this->amar, 35], [$pending, 20]] as [$user, $tier]) {
            $reward = new ReferralReward($user, $tier);
            $reward->approve();
            $this->em->persist($reward);
        }
        $company = new \App\Entity\Company('referral-campaign-brand', 'Campaign Brand', 'Travel');
        $this->admin->setCompany($company);
        $campaign = new \App\Entity\Campaign('ambassador-campaign', 'Ambassador campaign', 'Public campaign summary', 'Campaign brief', 'Travel', ['Instagram'], ['1 video'], 100, 200, 'Sarajevo', 3, new \DateTimeImmutable('+7 days'), new \DateTimeImmutable(), $company);
        $this->em->persist($company);
        $this->em->persist($campaign);
        $this->em->flush();
        $program = self::getContainer()->get(ReferralProgram::class);
        $program->notifyEarlyAccess($campaign, $this->admin);
        $notices = $this->em->getRepository(Notification::class)->findBy(['type' => 'campaign_early_access']);
        self::assertCount(1, $notices, 'Multiple approved tiers must still produce one notification per ambassador.');
        self::assertSame($this->amar->getId(), $notices[0]->getRecipient()->getId());
        $this->admin->setHideMyAccount(true);
        $this->em->flush();
        $program->notifyEarlyAccess($campaign, $this->admin);
        self::assertSame(1, $this->em->getRepository(Notification::class)->count(['type' => 'campaign_early_access']), 'Hidden companies must not broadcast campaign details.');
    }

    public function testAReferredAccountThatBecomesACompanyNoLongerCountsAsACreator(): void
    {
        $invited = $this->creator('converted-creator@example.test', 'converted-creator', 'Converted Creator', true);
        $referral = new CreatorReferral($this->amar, $invited, 'AMAR', null);
        self::assertSame('counted', ReferralProgram::status($referral));
        $invited->switchAccountRole('ROLE_COMPANY');
        self::assertSame('rejected', ReferralProgram::status($referral));
    }

    public function testReferralPagesAndRewardWritesRequireTheRightAccountAndCsrf(): void
    {
        $this->client->request('GET', '/api/me/referrals?locale=en');
        self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($this->amar, 'main');
        $this->client->request('GET', '/api/admin/referrals?locale=en');
        self::assertResponseStatusCodeSame(403);
        $this->json('POST', '/api/admin/referral-rewards/1/approve', []);
        self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->admin, 'main');
        $this->client->jsonRequest('POST', '/api/admin/referral-rewards/1/approve?locale=en', []);
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', '/api/admin/referrals/1/decision?locale=en', ['decision' => 'accept']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/me/referrals?locale=en');
        self::assertResponseStatusCodeSame(403);
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
