<?php

namespace App\Tests\Controller;

use App\Account\CampaignAlertDelivery;
use App\Admin\OverviewStatistics;
use App\Entity\{Application, Campaign, CampaignAlertPreference, Company, Creator, MarketplaceCategory, User};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

final class AccountActivationTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;
    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em); $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
    }
    public function testShortSignupKeepsBothAccountTypesHiddenAndIncomplete(): void
    {
        foreach (['creator', 'company'] as $type) {
            $this->client->request('GET', '/api/auth/csrf'); $csrf = $this->payload()['csrfToken'];
            $this->client->jsonRequest('POST', '/api/auth/register', ['email' => $type.'@example.test', 'password' => 'seven77', 'accountType' => $type, 'termsAccepted' => true, 'termsVersion' => \App\Legal\LegalPolicies::VERSION, 'name' => 'Amina Studio'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(400);
            $this->client->jsonRequest('POST', '/api/auth/register', ['email' => $type.'@example.test', 'password' => 'eight888', 'accountType' => $type, 'termsAccepted' => true, 'termsVersion' => \App\Legal\LegalPolicies::VERSION, 'name' => 'Amina Studio'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(201);
            $data = $this->payload()['data'];
            self::assertFalse($data['profileComplete']); self::assertFalse($data['approved']); self::assertFalse($data['emailVerified']); self::assertSame(1, $data['hide_my_account']);
            self::assertSame('Amina Studio', $data['profile'][$type === 'creator' ? 'displayName' : 'name']);
            $this->client->request('GET', '/api/me/dashboard'); self::assertResponseIsSuccessful(); self::assertFalse($this->payload()['data']['ready']);
            $this->client->jsonRequest('PUT', '/api/me/account-visibility', ['hide_my_account' => 0], ['HTTP_X_CSRF_TOKEN' => $this->csrf()]); self::assertResponseStatusCodeSame(403);
        }
    }
    public function testAlertPreferencesArePrivateOptInAndStrictlyValidated(): void
    {
        $this->client->request('GET', '/api/me/campaign-alerts'); self::assertResponseStatusCodeSame(401);
        $user = $this->creator(); $this->client->loginUser($user);
        $this->client->request('GET', '/api/me/campaign-alerts'); self::assertResponseIsSuccessful(); self::assertFalse($this->payload()['data']['enabled']); self::assertSame($user->getId(), $this->payload()['userId']);
        $valid = ['enabled' => true, 'categories' => ['Travel'], 'countries' => ['BA'], 'frequency' => 'weekly'];
        $this->client->jsonRequest('PUT', '/api/me/campaign-alerts', $valid); self::assertResponseStatusCodeSame(403);
        $csrf = $this->csrf();
        foreach (['enabled' => 1, 'frequency' => 'hourly', 'countries' => ['XX'], 'categories' => ['unknown']] as $field => $value) {
            $this->client->jsonRequest('PUT', '/api/me/campaign-alerts', [...$valid, $field => $value], ['HTTP_X_CSRF_TOKEN' => $csrf]); self::assertResponseStatusCodeSame(400);
        }
        $this->client->jsonRequest('PUT', '/api/me/campaign-alerts', $valid, ['HTTP_X_CSRF_TOKEN' => $csrf]); self::assertResponseIsSuccessful(); self::assertSame($valid, $this->payload()['data']);
        $other = $this->creator('other'); $this->client->loginUser($other);
        $this->client->request('GET', '/api/me/campaign-alerts'); self::assertFalse($this->payload()['data']['enabled']);
        $company = $this->company(); $this->client->loginUser($company);
        $this->client->request('GET', '/api/me/campaign-alerts'); self::assertResponseStatusCodeSame(403);
    }
    public function testDigestMatchesNewVisibleCampaignsAndRechecksOptOutAndBlocks(): void
    {
        $creator = $this->creator(); $company = $this->company();
        $campaign = $this->campaign($company, 'matching', 'BA'); $this->campaign($company, 'other-country', 'HR');
        $db = $this->em->getConnection();
        $preference = new CampaignAlertPreference($creator); $preference->configure(true, ['Travel'], ['BA'], 'daily'); $this->em->persist($preference); $this->em->flush();
        $since = gmdate('Y-m-d H:i:s', time() - 86401);
        $db->update('campaign_alert_preference', ['checked_at' => $since], ['user_id' => $creator->getId()]);
        $alerts = self::getContainer()->get(CampaignAlertDelivery::class);
        $rows = $alerts->matches($creator->getId(), $since, gmdate('Y-m-d H:i:s'), ['Travel'], ['BA']); self::assertCount(1, $rows); self::assertSame('matching', $rows[0]['slug']);
        $db->executeStatement('UPDATE wave_user SET hide_my_account = TRUE WHERE id = ?', [$company->getId()]); self::assertSame([], $alerts->matches($creator->getId(), $since, gmdate('Y-m-d H:i:s'), [], []));
        $db->executeStatement('UPDATE wave_user SET hide_my_account = FALSE WHERE id = ?', [$company->getId()]);
        $db->insert('user_block', ['id' => str_repeat('a', 32), 'blocker_id' => $company->getId(), 'blocked_id' => $creator->getId(), 'created_at' => gmdate('Y-m-d H:i:s')]);
        self::assertSame([], $alerts->matches($creator->getId(), $since, gmdate('Y-m-d H:i:s'), [], [])); $db->executeStatement('DELETE FROM user_block');
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('send')->with(self::callback(static function ($message): bool {
            self::assertInstanceOf(Email::class, $message); self::assertSame('creator@example.test', $message->getTo()[0]->getAddress()); self::assertStringContainsString('Amina', $message->getTextBody()); self::assertStringContainsString('/kampanje/matching', $message->getHtmlBody()); self::assertStringContainsString('/racun/e-mailovi?u=', $message->getTextBody()); self::assertStringContainsString('/api/email/unsubscribe?u=', $message->getHeaders()->get('List-Unsubscribe')?->getBodyAsString() ?? ''); self::assertSame('List-Unsubscribe=One-Click', $message->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString()); return true;
        }));
        $delivery = new CampaignAlertDelivery($db, self::getContainer()->get(\App\Background\JobDispatcher::class), $transport, self::getContainer()->get(\App\Account\EmailTemplateRenderer::class), 'no-reply@wave.ba', 'Wave', 'https://wave.ba', self::getContainer()->get(\App\Account\EmailPreferences::class));
        $db->executeStatement('UPDATE campaign_alert_preference SET enabled = FALSE WHERE user_id = ?', [$creator->getId()]); $delivery->deliver($creator->getId(), $since);
        $db->executeStatement('UPDATE campaign_alert_preference SET enabled = TRUE WHERE user_id = ?', [$creator->getId()]);
        $delivery->deliver($creator->getId(), $since); $delivery->deliver($creator->getId(), $since);
        $application = new Application($campaign, $creator->getCreator(), 'Test'); $this->em->persist($application); $this->em->flush();
        self::assertSame([], $delivery->matches($creator->getId(), $since, gmdate('Y-m-d H:i:s'), ['Travel'], ['BA']));
    }
    public function testMilestonesAreIdempotentAndFunnelUsesRegistrationCohorts(): void
    {
        $creator = $this->creator(); $company = $this->company();
        $campaign = $this->campaign($company, 'new-campaign', 'BA');
        $creator->setPhone(''); $this->em->flush(); // A later incomplete state does not erase a reached milestone.
        $snapshot = self::getContainer()->get(OverviewStatistics::class)->snapshot(7);
        self::assertSame(['registered' => 2, 'verified' => 2, 'completed' => 2, 'approved' => 2, 'first_action' => 1], $snapshot['activation']['counts']);
        $creator->setPhone('+38761123456'); $this->em->persist(new Application($campaign, $creator->getCreator(), 'Hello')); $this->em->flush();
        $snapshot = self::getContainer()->get(OverviewStatistics::class)->snapshot(7, 'creator');
        self::assertSame(1, $snapshot['activation']['counts']['first_action']);
        self::assertSame(5, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM account_milestone WHERE user_id = ?', [$creator->getId()]));
    }
    public function testOptionalProfileCountsDescribeSavedContentAndExcludeDemoStaffAndDeletedAccounts(): void
    {
        $statistics = self::getContainer()->get(OverviewStatistics::class);
        self::assertSame(['total' => 0, 'networks' => 0, 'works' => 0, 'packages' => 0, 'complete' => 0], $statistics->snapshot(7)['profileCompletion']);
        $all = null;
        foreach (['empty', 'networks', 'works', 'packages', 'all', 'demo', 'admin', 'moderator', 'deleted'] as $key) {
            $user = $this->user('completion-'.$key, 'ROLE_CREATOR');
            $filled = in_array($key, ['all', 'demo', 'admin', 'moderator', 'deleted'], true);
            $profile = new Creator('completion-'.$key, 'Creator', 'Travel', 'Sarajevo', 'Bio',
                $filled || $key === 'networks' ? [['platform' => 'Instagram', 'url' => 'https://instagram.com/example']] : [],
                portfolio: $filled || $key === 'works' ? [['type' => 'image', 'title' => 'Sample', 'publicPermission' => false]] : [],
                packages: $filled || $key === 'packages' ? [['title' => 'UGC video', 'priceMode' => 'agreement']] : [],
                createdAt: new \DateTimeImmutable('-2 years'),
            );
            $user->setCreator($profile);
            if ($key === 'demo') $user->markPresentationDemo('completion-test');
            if ($key === 'admin') $user->setAdmin(true);
            if ($key === 'moderator') $user->setModerator(true);
            if ($key === 'deleted') $user->anonymize();
            if ($key === 'all') {
                $all = $profile;
                $user->setApproved(false);
                $user->setHideMyAccount(true);
            }
            $this->em->persist($user);
        }
        $this->company();
        $this->em->persist(new Creator('ownerless-completion', 'Imported', 'Travel', '', '', [['platform' => 'Instagram']]));
        $this->em->flush();
        $expected = ['total' => 5, 'networks' => 2, 'works' => 2, 'packages' => 2, 'complete' => 1];
        self::assertSame($expected, $statistics->snapshot(7)['profileCompletion']);
        self::assertSame($expected, $statistics->snapshot(90, 'company')['profileCompletion']);
        self::assertInstanceOf(Creator::class, $all);
        $all->setPortfolio([]);
        $this->em->flush();
        self::assertSame(['total' => 5, 'networks' => 2, 'works' => 1, 'packages' => 2, 'complete' => 0], $statistics->snapshot(30)['profileCompletion']);
    }

    public function testAgencyRegistrationAndFirstActionCountOnceAcrossBothRoles(): void
    {
        $this->creator();
        $brand = $this->company();
        $agency = $this->agency('agency');
        $provider = \App\Account\AgencyProfiles::ensure($agency, $this->em);
        $campaign = $this->campaign($brand, 'agency-opportunity', 'BA');
        $this->em->persist(new Application($campaign, $provider, 'Agency proposal'));
        $this->em->flush();
        $this->campaign($agency, 'agency-client-campaign', 'BA');
        $statistics = self::getContainer()->get(OverviewStatistics::class);
        $snapshot = $statistics->snapshot(7);
        self::assertSame(['registered' => 3, 'verified' => 3, 'completed' => 3, 'approved' => 3, 'first_action' => 2], $snapshot['activation']['counts']);
        self::assertSame(['creator' => 1, 'company' => 2, 'brand' => 1, 'agency' => 1], $snapshot['activation']['breakdown']);
        self::assertSame(1, $snapshot['totals']['creators']);
        self::assertSame(1, $snapshot['profileCompletion']['total']);
        foreach (['creator' => 1, 'company' => 2, 'brand' => 1, 'agency' => 1] as $type => $expected) {
            self::assertSame($expected, $statistics->snapshot(7, $type)['activation']['counts']['registered']);
        }
        self::assertSame(1, $statistics->snapshot(7, 'agency')['activation']['counts']['first_action']);
        self::assertSame(5, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM account_milestone WHERE user_id = ?', [$agency->getId()]));
        $this->em->getConnection()->executeStatement("UPDATE account_milestone SET occurred_at = ? WHERE user_id = ? AND stage = 'registered'", [gmdate('Y-m-d H:i:s', time() - 45 * 86400), $brand->getId()]);
        self::assertSame(0, $statistics->snapshot(7, 'brand')['activation']['counts']['registered']);
        self::assertSame(1, $statistics->snapshot(7, 'company')['activation']['counts']['registered']);
        self::assertSame(2, $statistics->snapshot(90, 'company')['activation']['counts']['registered']);
        $agency->getCompany()->setCompanyType('brand');
        $this->em->flush();
        self::assertSame(['creator' => 1, 'company' => 1, 'brand' => 1, 'agency' => 0], $statistics->snapshot(7)['activation']['breakdown']);
        self::assertSame(1, $statistics->snapshot(7, 'brand')['activation']['counts']['first_action']);
        self::assertSame(1, $statistics->snapshot(7)['profileCompletion']['total']);
    }

    public function testAgencyCompletionIncludesEmptyStudiosAndExcludesBrandsAndNonRealAccounts(): void
    {
        $statistics = self::getContainer()->get(OverviewStatistics::class);
        self::assertSame(['total' => 0, 'services' => 0, 'works' => 0, 'packages' => 0, 'complete' => 0], $statistics->snapshot(7)['agencyProfileCompletion']);
        $all = null;
        $completeAgency = null;
        foreach (['empty', 'studio', 'services', 'works', 'packages', 'all', 'demo', 'admin', 'moderator', 'deleted', 'brand'] as $key) {
            $agency = $this->agency('completion-agency-'.$key);
            if ($key === 'empty') continue; // No internal creator row yet.
            $studio = \App\Account\AgencyProfiles::ensure($agency, $this->em);
            $filled = in_array($key, ['all', 'demo', 'admin', 'moderator', 'deleted', 'brand'], true);
            $agency->getCompany()->setServices(\App\Account\AgencyServices::parse($filled || $key === 'services' ? ['Campaign strategy'] : []));
            $studio->updateProfile($studio->getDisplayName(), 'Travel', 'Sarajevo', 'Bio', [],
                $filled || $key === 'services' ? ['Campaign strategy'] : [],
                avatarUrl: null, tagline: '',
                portfolio: $filled || $key === 'works' ? [['title' => 'Private work', 'publicPermission' => false]] : [],
                packages: $filled || $key === 'packages' ? [['title' => 'Content package', 'priceMode' => 'agreement']] : [],
                categories: ['Travel'], faqs: [],
            );
            if ($key === 'demo') $agency->markPresentationDemo('agency-completion-test');
            if ($key === 'admin') $agency->setAdmin(true);
            if ($key === 'moderator') $agency->setModerator(true);
            if ($key === 'deleted') $agency->anonymize();
            if ($key === 'brand') $agency->getCompany()->setCompanyType('brand');
            if ($key === 'all') {
                $all = $studio;
                $completeAgency = $agency;
                $agency->setApproved(false);
                $agency->setHideMyAccount(true);
                // Optional completeness also includes accounts predating milestone measurement.
                $this->em->getConnection()->delete('account_milestone', ['user_id' => $agency->getId()]);
            }
            $this->em->flush();
        }
        $this->creator();
        $expected = ['total' => 6, 'services' => 2, 'works' => 2, 'packages' => 2, 'complete' => 1];
        foreach (['all', 'creator', 'company', 'brand', 'agency'] as $type) {
            self::assertSame($expected, $statistics->snapshot(7, $type)['agencyProfileCompletion']);
        }
        self::assertSame($expected, $statistics->snapshot(90)['agencyProfileCompletion']);
        self::assertSame(1, $statistics->snapshot(7)['profileCompletion']['total']);
        self::assertSame(['creator' => 1, 'company' => 6, 'brand' => 1, 'agency' => 5], $statistics->snapshot(7)['activation']['breakdown']);
        self::assertInstanceOf(Creator::class, $all);
        $all->setPortfolio([]);
        $this->em->flush();
        self::assertSame(['total' => 6, 'services' => 2, 'works' => 1, 'packages' => 2, 'complete' => 0], $statistics->snapshot(7)['agencyProfileCompletion']);
        self::assertInstanceOf(User::class, $completeAgency);
        $completeAgency->getCompany()->setCompanyType('brand');
        $this->em->flush();
        self::assertSame(['total' => 5, 'services' => 1, 'works' => 1, 'packages' => 1, 'complete' => 0], $statistics->snapshot(7)['agencyProfileCompletion']);
    }

    public function testAgencyStatisticsFiltersRemainAdminOnlyAndNonCacheable(): void
    {
        $this->client->disableReboot();
        $this->client->request('GET', '/api/admin/overview?accountType=agency');
        self::assertResponseStatusCodeSame(401);
        $agency = $this->agency('api-agency');
        $this->client->loginUser($agency);
        $this->client->request('GET', '/api/admin/overview?accountType=agency');
        self::assertResponseStatusCodeSame(403);
        $admin = $this->user('statistics-admin', 'ROLE_COMPANY');
        $admin->setAdmin(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin);
        foreach (['brand' => 0, 'agency' => 1, 'company' => 1] as $type => $expected) {
            $this->client->request('GET', '/api/admin/overview?days=7&accountType='.$type);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
            self::assertSame($type, $this->payload()['data']['activation']['accountType']);
            self::assertSame($expected, $this->payload()['data']['activation']['counts']['registered']);
            self::assertSame(1, $this->payload()['data']['agencyProfileCompletion']['total']);
        }
        $this->client->request('GET', '/api/admin/overview?accountType=unknown');
        self::assertResponseStatusCodeSame(400);
    }

    private function agency(string $key): User
    {
        $user = $this->user($key, 'ROLE_COMPANY');
        $company = new Company($key, 'Agency', 'Travel');
        $company->setCompanyType('agency');
        $user->setCompany($company);
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
    private function creator(string $key = 'creator'): User
    {
        $user = $this->user($key, 'ROLE_CREATOR'); $user->setCreator(new Creator($key, 'Amina', 'Travel', 'Sarajevo', 'Bio', [])); $this->em->persist($user);
        if (!$this->em->getRepository(MarketplaceCategory::class)->findOneBy(['value' => 'Travel'])) $this->em->persist(new MarketplaceCategory('Travel', ['bs' => 'Putovanja'], 1));
        $this->em->flush(); return $user;
    }
    private function company(): User
    {
        $user = $this->user('company', 'ROLE_COMPANY'); $user->setCompany(new Company('company', 'Company', 'Travel')); $this->em->persist($user); $this->em->flush(); return $user;
    }
    private function user(string $key, string $role): User
    {
        $user = new User($key.'@example.test', $role); $user->setPassword('unused'); $user->setPhone('+38761123456'); $user->setCity('Sarajevo'); $user->setCountryCode('BA'); return $user;
    }
    private function campaign(User $owner, string $slug, string $country): Campaign
    {
        $campaign = new Campaign($slug, 'New campaign', 'Summary', 'Description', 'Travel', ['Instagram'], ['Video'], 100, 200, 'Sarajevo', 2, new \DateTimeImmutable('+1 month'), new \DateTimeImmutable('-1 hour'), $owner->getCompany(), countryCode: $country);
        $this->em->persist($campaign); $this->em->flush(); return $campaign;
    }
    private function csrf(): string { $this->client->request('GET', '/api/auth/csrf'); return $this->payload()['csrfToken']; }
    private function payload(): array { return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }
}
