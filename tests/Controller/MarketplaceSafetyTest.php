<?php

namespace App\Tests\Controller;

use App\Entity\Application;
use App\Entity\Campaign;
use App\Entity\CampaignReview;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\Offer;
use App\Entity\User;
use App\Entity\UserActionToken;
use App\Reviews\CollaborationFeedback;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MarketplaceSafetyTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $creatorUser;
    private User $companyUser;
    private User $stranger;
    private Application $application;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->creatorUser = $this->user('creator@example.test', 'ROLE_CREATOR');
        $this->companyUser = $this->user('company@example.test', 'ROLE_COMPANY');
        $this->stranger = $this->user('stranger@example.test', 'ROLE_CREATOR');
        $creator = new Creator('review-creator', 'Amina Kovač', 'Travel', 'Sarajevo', 'Creator biography', [], []);
        $other = new Creator('other-creator', 'Other Creator', 'Travel', 'Sarajevo', 'Creator biography', [], []);
        $company = new Company('review-company', 'DN kozmetik', 'Beauty');
        $this->creatorUser->setCreator($creator);
        $this->stranger->setCreator($other);
        $this->companyUser->setCompany($company);
        $campaign = $this->campaign($company, 'finished');
        $this->application = new Application($campaign, $creator, 'Application with accepted work');
        $offer = new Offer($this->application, 350, 'Agreed campaign amount');
        $offer->respond('accepted');
        foreach ([$creator, $other, $company, $campaign, $this->application, $offer] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
    }

    public function testPersonalOverviewIsPrivateScopedAndCountsOnlyActionableWork(): void
    {
        $this->client->request('GET', '/api/me/dashboard');
        self::assertResponseStatusCodeSame(401);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([$this->creatorUser, $this->companyUser, $this->stranger] as $user) {
            $user->setPhone('+38761123456'); $user->setCity('Sarajevo'); $user->setCountryCode('BA');
        }
        $open = $this->campaign($this->companyUser->getCompany(), 'open');
        $app = new Application($open, $this->creatorUser->getCreator(), 'Pending work');
        $offer = new Offer($app, 700, 'Negotiated amount');
        $invite = new \App\Entity\CampaignInvitation($open, $this->creatorUser->getCreator(), $this->companyUser, 'Invitation');
        foreach ([$open, $app, $offer, $invite] as $entity) $em->persist($entity);
        $em->flush();
        $this->client->loginUser($this->creatorUser);
        $this->client->request('GET', '/api/me/dashboard');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $this->client->getResponse()->headers->get('Cache-Control'));
        $snapshot = $this->payload();
        self::assertSame($this->creatorUser->getId(), $snapshot['userId']);
        self::assertTrue($snapshot['data']['ready']);
        self::assertSame(['invitations' => 1, 'offers' => 1, 'active' => 0, 'applications' => 2], $snapshot['data']['counts']);
        self::assertCount(2, $snapshot['data']['recent']); // Invitation/application pair appears once.
        self::assertSame([['currency' => 'BAM', 'amount' => 350]], $snapshot['data']['financial']['totals']);
        $this->client->loginUser($this->companyUser);
        $this->client->request('GET', '/api/me/dashboard');
        self::assertSame(['campaigns' => 1, 'applications' => 1, 'offers' => 1, 'active' => 0], $this->payload()['data']['counts']);
        $this->client->loginUser($this->stranger);
        $this->client->request('GET', '/api/me/dashboard');
        self::assertSame([], $this->payload()['data']['recent']);
        self::assertSame([], $this->payload()['data']['financial']['totals']);
        $this->client->loginUser($this->creatorUser);
        $this->write('POST', '/api/me/safety/company/review-company/block', []);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/me/dashboard');
        self::assertSame([], $this->payload()['data']['recent']);
        self::assertSame(0, $this->payload()['data']['counts']['offers']);
    }

    public function testPersonalOverviewDoesNotExposeActivityBeforeEligibility(): void
    {
        $this->client->loginUser($this->creatorUser);
        $this->client->request('GET', '/api/me/dashboard');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['data']['ready']);
        self::assertSame([], $this->payload()['data']['recent']);
        self::assertNull($this->payload()['data']['financial']);
    }

    public function testCompanyPasswordRegistrationDefaultsToBrandAndAcceptsAgency(): void
    {
        $body = ['accountType' => 'company', 'name' => 'Demo company', 'password' => 'a-long-test-passphrase', 'industries' => ['Beauty'], 'country' => 'BA', 'city' => 'Sarajevo', 'phone' => '+38761123456'];
        foreach (['brand', 'agency'] as $type) {
            $this->write('POST', '/api/auth/register', $body + ['email' => $type.'@example.test'] + ($type === 'agency' ? ['companyType' => $type] : []));
            self::assertResponseStatusCodeSame(201);
            self::assertSame($type, $this->payload()['data']['profile']['companyType']);
        }
        $this->write('POST', '/api/auth/register', $body + ['email' => 'invalid@example.test', 'companyType' => 'invalid']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testCompanyTypesPersistAndDirectoryFiltersAreScoped(): void
    {
        self::assertSame('brand', $this->companyUser->getCompany()->getCompanyType());
        $this->client->loginUser($this->companyUser);
        $body = ['name' => 'DN kozmetik', 'industry' => 'Beauty', 'logoUrl' => null];
        $this->write('PUT', '/api/me/profile', $body + ['companyType' => 'agency']);
        self::assertResponseIsSuccessful();
        self::assertSame('agency', $this->payload()['data']['profile']['companyType']);
        $this->write('PUT', '/api/me/profile', $body);
        self::assertSame('agency', $this->payload()['data']['profile']['companyType']);
        foreach (['creator', null, ['brand']] as $invalid) {
            $this->write('PUT', '/api/me/profile', $body + ['companyType' => $invalid]);
            self::assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', '/api/companies?companyTypes=%5B%22agency%22%5D&view=card');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['meta']['total']);
        self::assertSame('agency', $this->payload()['data'][0]['companyType']);
        $this->client->request('GET', '/api/companies?companyTypes=%5B%22brand%22%5D');
        self::assertSame(0, $this->payload()['meta']['total']);
        $this->client->request('GET', '/api/companies?companyTypes=%5B%22brand%22,%22agency%22%5D');
        self::assertSame(1, $this->payload()['meta']['total']);
        $this->client->request('GET', '/api/companies?companyTypes=%5B%22bad%22%5D');
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/companies/filters');
        self::assertSame([['value' => 'brand', 'count' => 0], ['value' => 'agency', 'count' => 1]], $this->payload()['data']['companyTypes']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $agency = new Company('another-agency', 'Another agency', 'Beauty');
        $agency->setCompanyType('agency');
        $em->persist($agency); $em->flush();
        $params = ['companyTypes' => '["agency"]', 'pagination' => 'cursor', 'sort' => 'name', 'limit' => 1];
        $this->client->request('GET', '/api/companies?'.http_build_query($params));
        self::assertTrue($this->payload()['meta']['hasMore']);
        $cursor = $this->payload()['meta']['nextCursor'];
        $this->client->request('GET', '/api/companies?'.http_build_query($params + ['cursor' => $cursor]));
        self::assertResponseIsSuccessful();
        self::assertSame('review-company', $this->payload()['data'][0]['slug']);
        $this->client->request('GET', '/api/companies?'.http_build_query(array_replace($params, ['companyTypes' => '["brand"]', 'cursor' => $cursor])));
        self::assertResponseStatusCodeSame(400);
        $this->client->loginUser($this->creatorUser);
        $this->write('POST', '/api/me/safety/company/review-company/block', []);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/companies?companyTypes=%5B%22agency%22%5D');
        self::assertSame(['another-agency'], array_column($this->payload()['data'], 'slug'));
        $this->client->request('GET', '/api/companies/filters');
        self::assertSame([['value' => 'brand', 'count' => 0], ['value' => 'agency', 'count' => 1]], $this->payload()['data']['companyTypes']);
    }

    public function testAdminNavigationCountsExcludeHandledWorkAndRequireAdmin(): void
    {
        $this->client->request('GET', '/api/admin/navigation-counts');
        self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($this->creatorUser);
        $this->client->request('GET', '/api/admin/navigation-counts');
        self::assertResponseStatusCodeSame(403);
        $this->write('POST', '/api/me/safety/company/review-company/report', ['category' => 'spam', 'reason' => 'messages']);
        self::assertResponseIsSuccessful();
        $reportId = $this->payload()['data']['id'];
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach (['open', 'in_progress', 'on_hold', 'resolved'] as $status) {
            $ticket = new \App\Entity\SupportTicket(['name' => 'Demo reporter', 'email' => 'demo@example.test', 'phone' => '', 'kind' => 'problem', 'category' => 'technical', 'title' => 'Example issue', 'description' => 'Example support ticket for count verification.'], 'bs', bin2hex(random_bytes(16)));
            $ticket->setStatus($status);
            $em->persist($ticket);
        }
        $pending = $this->user('pending@example.test', 'ROLE_CREATOR');
        $pending->setApproved(false);
        $deleted = $this->user('deleted@example.test', 'ROLE_CREATOR');
        $deleted->anonymize();
        $em->flush();
        $this->admin();
        $this->client->request('GET', '/api/admin/navigation-counts');
        self::assertResponseIsSuccessful();
        self::assertSame(['approvals' => 1, 'reports' => 1, 'tickets' => 3], $this->payload()['data']);
        self::assertSame($this->stranger->getId(), $this->payload()['userId']);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $this->write('PUT', '/api/admin/safety/reports/'.$reportId, ['status' => 'processing', 'version' => 1, 'response' => '']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/admin/navigation-counts');
        self::assertSame(1, $this->payload()['data']['reports']);
        $this->write('PUT', '/api/admin/safety/reports/'.$reportId, ['status' => 'dismissed', 'version' => 2, 'response' => 'We reviewed this report and found no violation.']);
        self::assertResponseIsSuccessful();
        $this->db()->executeStatement('UPDATE wave_user SET approved = TRUE WHERE id = ?', [$pending->getId()]);
        $this->db()->executeStatement("UPDATE wave_support_ticket SET status = 'resolved'");
        $this->client->request('GET', '/api/admin/navigation-counts');
        self::assertSame(['approvals' => 0, 'reports' => 0, 'tickets' => 0], $this->payload()['data']);
    }

    public function testAdminOverviewUsesRealAggregatesWithBoundedPeriodsAndPrivateAccess(): void
    {
        $this->client->request('GET', '/api/admin/overview?days=7');
        self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($this->creatorUser);
        $this->client->request('GET', '/api/admin/overview?days=7');
        self::assertResponseStatusCodeSame(403);
        $this->admin();
        $this->client->request('GET', '/api/admin/overview?days=7');
        self::assertResponseIsSuccessful();
        $data = $this->payload()['data'];
        self::assertSame($this->stranger->getId(), $this->payload()['userId']);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(7, $data['series']);
        self::assertSame(2, $data['totals']['creators']);
        self::assertSame(1, $data['totals']['campaigns']);
        self::assertSame(1, $data['totals']['applications']);
        self::assertSame(1, $data['totals']['hires']);
        self::assertSame(1, $data['totals']['completed']);
        self::assertSame(['open' => 0, 'closed' => 0, 'finished' => 1, 'cancelled' => 0], $data['campaignStatuses']);
        self::assertSame(1, array_sum(array_column($data['series'], 'hires')));
        self::assertCount(1, $data['recentCampaigns']);
        self::assertSame(0, $data['attention']['tickets']);
        $this->db()->executeStatement("UPDATE campaign_offer SET status = 'cancelled'");
        $this->db()->executeStatement("UPDATE campaign SET status = 'cancelled'");
        $this->client->request('GET', '/api/admin/overview?days=90');
        self::assertCount(90, $this->payload()['data']['series']);
        self::assertSame(0, $this->payload()['data']['totals']['hires']);
        self::assertSame(0, $this->payload()['data']['totals']['completed']);
        self::assertSame(1, $this->payload()['data']['campaignStatuses']['cancelled']);
        $this->client->request('GET', '/api/admin/overview?days=999999');
        self::assertResponseStatusCodeSame(400);
    }

    public function testInvitedCreatorsAppearInCampaignAndConfirmNegotiatedHireAmounts(): void
    {
        foreach ([50, 1500] as $amount) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $company = $em->find(User::class, $this->companyUser->getId())->getCompany();
            $campaign = $this->campaign($company, 'open');
            $em->persist($campaign); $em->flush();
            $slug = $campaign->getSlug();
            $this->client->loginUser($this->companyUser);
            $this->write('POST', '/api/company/campaigns/'.$slug.'/invitations', ['creatorId' => $this->creatorUser->getCreator()->getId(), 'message' => 'We would like to discuss this campaign with you.']);
            self::assertResponseStatusCodeSame(201);
            $invitationId = $this->payload()['data']['id'];
            $this->client->request('GET', '/api/company/campaigns/'.$slug.'/applications');
            self::assertCount(1, $this->payload()['data']);
            self::assertSame('invited', $this->payload()['data'][0]['status']);
            self::assertNull($this->payload()['data'][0]['conversationId']);
            $this->client->loginUser($this->creatorUser);
            $this->write('POST', '/api/campaigns/'.$slug.'/applications', ['message' => 'An invited creator must not submit a second application.']);
            self::assertResponseStatusCodeSame(409);
            $this->write('POST', '/api/me/invitations/'.$invitationId.'/respond', ['decision' => 'accept']);
            self::assertResponseIsSuccessful();
            $conversationId = $this->payload()['conversation']['id'];
            $this->client->loginUser($this->companyUser);
            $this->client->request('GET', '/api/me/conversations/'.$conversationId.'/messages');
            self::assertTrue($this->payload()['conversation']['engagement']['canHire']);
            $this->client->jsonRequest('POST', '/api/company/conversations/'.$conversationId.'/hire', ['amount' => $amount, 'message' => 'The negotiated amount is confirmed.']);
            self::assertResponseStatusCodeSame(403);
            $this->write('POST', '/api/company/conversations/'.$conversationId.'/hire', ['amount' => $amount, 'message' => 'The negotiated amount is confirmed.']);
            self::assertResponseStatusCodeSame(201);
            $offer = $this->payload()['data'];
            self::assertSame($amount, $offer['amount']);
            self::assertSame('pending', $offer['status']);
            self::assertSame(0, $offer['campaign']['hiredCount']);
            $this->write('POST', '/api/company/conversations/'.$conversationId.'/hire', ['amount' => $amount, 'message' => 'Duplicate offers are not permitted.']);
            self::assertResponseStatusCodeSame(409);
            $this->client->request('GET', '/api/company/campaigns/'.$slug.'/applications');
            self::assertCount(1, $this->payload()['data']);
            self::assertSame('offered', $this->payload()['data'][0]['status']);
            self::assertSame($conversationId, $this->payload()['data'][0]['conversationId']);
            $this->client->loginUser($this->creatorUser);
            $this->client->request('GET', '/api/me/conversations/'.$conversationId.'/messages');
            $messages = $this->payload()['data'];
            self::assertSame('offer_sent', end($messages)['event']['type']);
            self::assertSame($amount, end($messages)['event']['amount']);
            $this->write('POST', '/api/me/offers/'.$offer['id'].'/respond', ['decision' => 'accept']);
            self::assertResponseIsSuccessful();
            self::assertSame(1, $this->payload()['data']['campaign']['hiredCount']);
            $this->client->request('GET', '/api/me/conversations/'.$conversationId.'/messages');
            $messages = $this->payload()['data'];
            self::assertSame('hired', end($messages)['event']['type']);
            self::assertSame($slug, end($messages)['event']['campaignSlug']);
            $this->write('POST', '/api/me/conversations/'.$conversationId.'/messages', ['body' => 'A normal message', 'event' => ['type' => 'hired', 'amount' => 999999]]);
            self::assertResponseStatusCodeSame(201);
            self::assertNull($this->payload()['data']['event']);
        }
    }

    public function testEitherParticipantCanCancelActiveHireWithoutChangingCompletedEarnings(): void
    {
        foreach ([$this->creatorUser, $this->companyUser] as $actor) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $creator = $em->find(User::class, $this->creatorUser->getId())->getCreator();
            $company = $em->find(User::class, $this->companyUser->getId())->getCompany();
            $campaign = $this->campaign($company, 'open');
            $application = new Application($campaign, $creator, 'Agreed collaboration for cancellation test');
            $offer = new Offer($application, 900, 'Negotiated collaboration amount');
            $offer->respond('accepted');
            foreach ([$campaign, $application, $offer] as $entity) $em->persist($entity);
            $em->flush();
            $id = $offer->getId(); $campaignId = $campaign->getId(); $applicationId = $application->getId();
            $this->client->loginUser($this->stranger);
            $this->write('POST', '/api/me/offers/'.$id.'/cancel', []);
            self::assertResponseStatusCodeSame(404);
            $this->client->loginUser($actor);
            $this->client->jsonRequest('POST', '/api/me/offers/'.$id.'/cancel', []);
            self::assertResponseStatusCodeSame(403);
            $this->write('POST', '/api/me/offers/'.$id.'/cancel', []);
            self::assertResponseIsSuccessful();
            self::assertSame('cancelled', $this->payload()['data']['status']);
            self::assertSame(900, $this->payload()['data']['amount']);
            self::assertSame(0, $this->payload()['data']['campaign']['hiredCount']);
            self::assertSame('open', $this->payload()['data']['campaign']['status']);
            self::assertTrue($this->payload()['data']['readOnly']);
            $conversationId = (int) $this->db()->fetchOne('SELECT id FROM campaign_conversation WHERE campaign_id = ?', [$campaignId]);
            $this->write('POST', '/api/me/offers/'.$id.'/cancel', []);
            self::assertResponseIsSuccessful();
            self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM campaign_message WHERE conversation_id = ?', [$conversationId]));
            foreach ([$this->creatorUser, $this->companyUser] as $participant) {
                $this->client->loginUser($participant);
                $this->client->request('GET', '/api/me/conversations/'.$conversationId.'/messages');
                self::assertResponseIsSuccessful();
                self::assertTrue($this->payload()['conversation']['readOnly']);
                self::assertFalse($this->payload()['conversation']['engagement']['canHire']);
                self::assertSame('cancelled', $this->payload()['data'][0]['event']['type']);
                $this->write('POST', '/api/me/conversations/'.$conversationId.'/messages', ['body' => 'Not allowed after cancellation.']);
                self::assertResponseStatusCodeSame(409);
                $this->client->request('GET', '/api/me/inbox');
                self::assertResponseIsSuccessful();
                $row = array_values(array_filter($this->payload()['data'], fn(array $row): bool => $row['id'] === $conversationId))[0];
                self::assertTrue($row['readOnly']);
                self::assertTrue($row['cancelled']);
            }
            $this->client->loginUser($this->companyUser);
            $this->write('PUT', '/api/company/campaigns/'.$campaignId.'/status', ['status' => 'finished']);
            self::assertResponseIsSuccessful();
            self::assertSame('cancelled', $this->db()->fetchOne('SELECT status FROM campaign_application WHERE id = ?', [$applicationId]));
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $feedback = static::getContainer()->get(CollaborationFeedback::class);
            self::assertSame([['currency' => 'BAM', 'amount' => 350]], $feedback->earnings($em->find(User::class, $this->creatorUser->getId()))['totals']);
            self::assertSame([['currency' => 'BAM', 'amount' => 350]], $feedback->earnings($em->find(User::class, $this->companyUser->getId()))['totals']);
            $this->client->loginUser($this->creatorUser);
            $this->write('POST', '/api/me/applications/'.$applicationId.'/review', ['rating' => 5, 'comment' => 'Canceled work should not allow reviews.']);
            self::assertResponseStatusCodeSame(409);
        }
    }

    public function testCampaignCancellationCancelsOffersAndInvitationsAndRemainsTerminal(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $creator = $em->find(User::class, $this->creatorUser->getId())->getCreator();
        $other = $em->find(User::class, $this->stranger->getId())->getCreator();
        $company = $em->find(User::class, $this->companyUser->getId())->getCompany();
        $campaign = $this->campaign($company, 'open');
        $application = new Application($campaign, $creator, 'Active collaboration before campaign cancellation');
        $offer = new Offer($application, 900, 'Agreed amount before cancellation');
        $offer->respond('accepted');
        $pendingApplication = new Application($campaign, $other, 'A pending offer before campaign cancellation');
        $pendingOffer = new Offer($pendingApplication, 250, 'An offer still awaiting acceptance');
        $invitation = new \App\Entity\CampaignInvitation($campaign, $other, $em->find(User::class, $this->companyUser->getId()), 'An invitation awaiting a reply');
        foreach ([$campaign, $application, $offer, $pendingApplication, $pendingOffer, $invitation] as $entity) $em->persist($entity);
        $em->flush();
        $campaignId = $campaign->getId(); $slug = $campaign->getSlug(); $pendingId = $pendingOffer->getId();
        $this->client->loginUser($this->creatorUser);
        $this->write('PUT', '/api/company/campaigns/'.$campaignId.'/status', ['status' => 'cancelled']);
        self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->companyUser);
        $this->write('PUT', '/api/company/campaigns/'.$campaignId.'/status', ['status' => 'cancelled']);
        self::assertResponseIsSuccessful();
        self::assertSame('cancelled', $this->payload()['data']['status']);
        self::assertSame(0, $this->payload()['data']['hiredCount']);
        self::assertSame(['cancelled', 'cancelled'], $this->db()->fetchFirstColumn('SELECT status FROM campaign_offer WHERE application_id IN (?, ?) ORDER BY id', [$application->getId(), $pendingApplication->getId()]));
        self::assertSame('cancelled', $this->db()->fetchOne('SELECT status FROM campaign_invitation WHERE id = ?', [$invitation->getId()]));
        $messageCount = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM campaign_message');
        $this->write('PUT', '/api/company/campaigns/'.$campaignId.'/status', ['status' => 'cancelled']);
        self::assertResponseIsSuccessful();
        self::assertSame($messageCount, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM campaign_message'));
        $this->write('PUT', '/api/company/campaigns/'.$campaignId.'/status', ['status' => 'finished']);
        self::assertResponseStatusCodeSame(409);
        $this->client->loginUser($this->stranger);
        $this->write('POST', '/api/me/offers/'.$pendingId.'/respond', ['decision' => 'accept']);
        self::assertResponseStatusCodeSame(409);
        $this->write('POST', '/api/me/invitations/'.$invitation->getId().'/respond', ['decision' => 'accept']);
        self::assertResponseStatusCodeSame(409);
        $this->write('POST', '/api/campaigns/'.$slug.'/applications', ['message' => 'New applications cannot enter canceled campaigns.']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testFinishedCampaignsCannotBeCanceledAndPendingOffersAreDeclinedInstead(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $campaign = $this->campaign($em->find(User::class, $this->companyUser->getId())->getCompany(), 'open');
        $application = new Application($campaign, $em->find(User::class, $this->creatorUser->getId())->getCreator(), 'Pending offer application');
        $offer = new Offer($application, 200, 'Pending offer awaiting a response');
        foreach ([$campaign, $application, $offer] as $entity) $em->persist($entity);
        $em->flush();
        $this->client->loginUser($this->companyUser);
        $this->write('PUT', '/api/company/campaigns/'.$this->application->getCampaign()->getId().'/status', ['status' => 'cancelled']);
        self::assertResponseStatusCodeSame(409);
        $this->client->loginUser($this->creatorUser);
        $this->write('POST', '/api/me/offers/'.$this->application->getOffer()->getId().'/cancel', []);
        self::assertResponseStatusCodeSame(409);
        $this->write('POST', '/api/me/offers/'.$offer->getId().'/cancel', []);
        self::assertResponseStatusCodeSame(409);
    }

    public function testReportsRequireValidReasonsAndAdminResolutionCreatesPrivateModalNotices(): void
    {
        $this->client->loginUser($this->creatorUser);
        $path = '/api/me/safety/company/review-company/report';
        $this->write('POST', $path, ['category' => 'security', 'reason' => 'identity']);
        self::assertResponseStatusCodeSame(400);
        $this->write('POST', $path, ['category' => 'other', 'reason' => 'other', 'description' => 'short']);
        self::assertResponseStatusCodeSame(400);
        $this->write('POST', $path, ['category' => 'behavior', 'reason' => 'harassment', 'description' => 'Repeated abusive messages.']);
        self::assertResponseIsSuccessful();
        $id = $this->payload()['data']['id'];
        $this->write('POST', $path, ['category' => 'behavior', 'reason' => 'harassment']);
        self::assertResponseStatusCodeSame(409);
        $this->client->request('GET', '/api/admin/safety/reports');
        self::assertResponseStatusCodeSame(403);
        $this->write('PUT', '/api/admin/safety/reports/'.$id, ['status' => 'resolved', 'version' => 1, 'response' => 'We reviewed the submitted report.']);
        self::assertResponseStatusCodeSame(403);
        $this->admin();
        $this->client->request('GET', '/api/admin/safety/reports?sort=status&direction=asc');
        self::assertResponseIsSuccessful();
        self::assertSame('Repeated abusive messages.', $this->payload()['data'][0]['description']);
        $this->write('PUT', '/api/admin/safety/reports/'.$id, ['status' => 'processing', 'version' => 1, 'response' => '']);
        self::assertResponseIsSuccessful();
        $this->write('PUT', '/api/admin/safety/reports/'.$id, ['status' => 'resolved', 'version' => 1, 'response' => 'We reviewed the submitted report.']);
        self::assertResponseStatusCodeSame(409);
        $this->write('PUT', '/api/admin/safety/reports/'.$id, ['status' => 'resolved', 'version' => 2, 'response' => 'We reviewed the submitted report.']);
        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM wave_notification'));
        $this->client->loginUser($this->companyUser);
        $this->client->request('GET', '/api/me/safety/notices');
        self::assertSame([], $this->payload()['data']);
        $this->client->loginUser($this->creatorUser);
        $this->client->request('GET', '/api/me/safety/notices');
        self::assertSame($this->creatorUser->getId(), $this->payload()['userId']);
        self::assertCount(3, $this->payload()['data']);
        self::assertSame(['received', 'processing', 'resolved'], array_column($this->payload()['data'], 'status'));
        $notice = $this->payload()['data'][0]['id'];
        $this->client->loginUser($this->stranger);
        $this->write('POST', '/api/me/safety/notices/'.$notice.'/read', []);
        self::assertNull($this->db()->fetchOne('SELECT read_at FROM report_notice WHERE id = ?', [$notice]));
        $this->client->loginUser($this->creatorUser);
        $this->write('POST', '/api/me/safety/notices/'.$notice.'/read', []);
        self::assertNotNull($this->db()->fetchOne('SELECT read_at FROM report_notice WHERE id = ?', [$notice]));
    }

    public function testPersonalBlockHidesBothProfilesAndCampaignsAndPreventsDirectWrites(): void
    {
        $slug = $this->application->getCampaign()->getSlug();
        $this->db()->update('campaign', ['status' => 'open', 'finished_at' => null], ['id' => $this->application->getCampaign()->getId()]);
        $this->client->loginUser($this->creatorUser);
        $this->write('POST', '/api/me/safety/company/review-company/block', []);
        self::assertResponseIsSuccessful();
        $block = $this->payload()['data']['id'];
        foreach (['/api/companies/review-company', '/api/companies/review-company/reviews', '/api/campaigns/'.$slug] as $path) {
            $this->client->request('GET', $path); self::assertResponseStatusCodeSame(404);
        }
        $this->client->request('GET', '/api/companies');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']);
        self::assertSame(0, $this->payload()['meta']['total']);
        $this->client->request('GET', '/api/campaigns');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']);
        $this->write('POST', '/api/campaigns/'.$slug.'/applications', ['message' => 'Cannot apply through the direct URL after blocking.']);
        self::assertResponseStatusCodeSame(409);
        $this->client->loginUser($this->companyUser);
        $this->client->request('GET', '/api/creators/review-creator');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/creators?pagination=cursor&limit=24');
        self::assertResponseIsSuccessful();
        self::assertNotContains('review-creator', array_column($this->payload()['data'], 'slug'));
        $this->write('POST', '/api/company/campaigns/'.$slug.'/invitations', ['creatorId' => $this->application->getCreator()->getId(), 'message' => 'This invitation must not bypass the block.']);
        self::assertResponseStatusCodeSame(409);
        $this->write('POST', '/api/company/applications/'.$this->application->getId().'/shortlist', []);
        self::assertResponseStatusCodeSame(409);
        $this->write('DELETE', '/api/me/safety/blocks/'.$block, []);
        self::assertResponseStatusCodeSame(404);
        $this->admin();
        $this->client->request('GET', '/api/me/safety/blocks');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']);
        $this->write('DELETE', '/api/me/safety/blocks/'.$block, []);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/admin/safety/blocks');
        self::assertResponseStatusCodeSame(404);
        $this->client->loginUser($this->creatorUser);
        $this->write('DELETE', '/api/me/safety/blocks/'.$block, []);
        self::assertResponseIsSuccessful();
        $this->client->loginUser($this->companyUser);
        $this->client->request('GET', '/api/creators/review-creator');
        self::assertResponseIsSuccessful();
    }

    public function testBlockedApplicationsRemainAnonymousAndReadOnlyWithoutChangingRecords(): void
    {
        $this->client->loginUser($this->companyUser);
        $this->write('POST', '/api/me/safety/creator/review-creator/block', []);
        self::assertResponseIsSuccessful();
        $this->client->loginUser($this->creatorUser);
        $this->client->request('GET', '/api/me/applications');
        self::assertResponseIsSuccessful();
        $application = $this->payload()['data'][0];
        self::assertTrue($application['readOnly']);
        self::assertTrue($application['blocked']);
        self::assertNull($application['campaign']['slug']);
        self::assertStringNotContainsString('DN kozmetik', json_encode($this->payload()));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM campaign_application'));
        self::assertSame('accepted', $this->db()->fetchOne('SELECT status FROM campaign_offer'));
        $this->write('POST', '/api/me/applications/'.$this->application->getId().'/review', ['rating' => 5, 'comment' => 'Blocked accounts cannot start new reviews.']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testSafetyWritesEnforceCsrfAndCannotBlockOrReportOwnAccount(): void
    {
        $this->client->request('GET', '/api/me/safety/notices');
        self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($this->creatorUser);
        $this->client->jsonRequest('POST', '/api/me/safety/company/review-company/block', []);
        self::assertResponseStatusCodeSame(403);
        $this->write('POST', '/api/me/safety/creator/review-creator/block', []);
        self::assertResponseStatusCodeSame(404);
        $this->write('POST', '/api/me/safety/creator/review-creator/report', ['category' => 'spam', 'reason' => 'messages']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/admin/safety/reports');
        self::assertResponseStatusCodeSame(403);
    }

    public function testBlockedChatRemainsReadableWithNoUnreadCountsOrQueuedDelivery(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $conversation = new \App\Entity\CampaignConversation($this->application->getCampaign(), $this->application->getCreator(), $this->companyUser);
        $message = new \App\Entity\CampaignMessage($conversation, $this->companyUser, 'Historical message remains available.');
        $em->persist($conversation); $em->persist($message); $em->flush();
        $id = $conversation->getId();
        $this->client->loginUser($this->creatorUser);
        $this->write('POST', '/api/me/safety/company/review-company/block', []);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/me/inbox');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['data'][0]['readOnly']);
        self::assertSame(0, $this->payload()['data'][0]['unreadCount']);
        self::assertStringNotContainsString('DN kozmetik', json_encode($this->payload()));
        $this->client->request('GET', '/api/me/inbox/campaign/'.$id);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['data']['blocked']);
        $this->client->request('GET', '/api/me/conversations/'.$id.'/messages');
        self::assertResponseIsSuccessful();
        self::assertSame('Historical message remains available.', $this->payload()['data'][0]['body']);
        self::assertNull($this->payload()['data'][0]['senderId']);
        $this->client->jsonRequest('POST', '/api/me/conversations/'.$id.'/messages', ['body' => 'A block does not replace the CSRF check.']);
        self::assertResponseStatusCodeSame(403);
        $this->write('POST', '/api/me/conversations/'.$id.'/messages', ['body' => 'New message must be blocked.']);
        self::assertResponseStatusCodeSame(409);
        $policy = static::getContainer()->get(\App\Safety\BlockPolicy::class);
        self::assertFalse($policy->eventAllowed($this->creatorUser->getId(), ['url' => '/messages?conversation='.$id]));
        self::assertFalse($policy->eventAllowed($this->creatorUser->getId(), ['message' => ['senderId' => $this->companyUser->getId()]]));
        $this->client->request('GET', '/api/me/inbox/unread');
        self::assertSame(0, $this->payload()['unreadCount']);
        $this->db()->update('campaign_message', ['created_at' => gmdate('Y-m-d H:i:s', time() - 7200)], ['id' => $message->getId()]);
        self::assertSame(0, static::getContainer()->get(\App\Background\ReminderBatch::class)->run(false));
        $this->client->loginUser($this->companyUser);
        $this->client->request('GET', '/api/company/campaigns/'.$this->application->getCampaign()->getSlug().'/applications');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['data'][0]['readOnly']);
        self::assertStringNotContainsString('Amina Kovač', json_encode($this->payload()));
    }

    public function testReviewPagesAndPersonalPairsPreserveAccountOwnership(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        for ($i = 0; $i < 13; ++$i) {
            $campaign = $this->campaign($this->companyUser->getCompany(), 'finished');
            $application = new Application($campaign, $this->creatorUser->getCreator(), 'Completed collaboration for pagination.');
            $offer = new Offer($application, 300, 'Accepted collaboration amount'); $offer->respond('accepted');
            $first = new CampaignReview($application, $this->creatorUser, $this->companyUser, 4, 'A thoughtful collaboration with clear expectations.');
            $second = new CampaignReview($application, $this->companyUser, $this->creatorUser, 5, 'Excellent creative work and clear communication.');
            foreach ([$campaign, $application, $offer, $first, $second] as $entity) { $em->persist($entity); }
        }
        $em->flush();
        $this->client->request('GET', '/api/creators/review-creator/reviews?page=1');
        self::assertResponseIsSuccessful(); self::assertCount(10, $this->payload()['data']);
        self::assertSame(13, $this->payload()['meta']['total']);
        $firstIds = array_column($this->payload()['data'], 'id');
        $this->client->request('GET', '/api/creators/review-creator/reviews?page=2');
        self::assertCount(3, $this->payload()['data']);
        self::assertSame([], array_intersect($firstIds, array_column($this->payload()['data'], 'id')));
        $this->client->request('GET', '/api/creators/review-creator/reviews?page=999');
        self::assertSame(2, $this->payload()['meta']['page']);
        $this->client->loginUser($this->creatorUser);
        $this->client->request('GET', '/api/me/safety/reviews?limit=25&sort=received&direction=desc');
        self::assertResponseIsSuccessful(); self::assertCount(13, $this->payload()['data']);
        self::assertSame(13, $this->payload()['meta']['total']);
        $pair = $this->payload()['data'][0];
        self::assertSame(5, $pair['received']['rating']);
        self::assertSame(4, $pair['given']['rating']);
        self::assertSame('DN kozmetik', $pair['name']);
        $this->client->loginUser($this->companyUser);
        $this->client->request('GET', '/api/me/safety/reviews');
        self::assertResponseIsSuccessful();
        self::assertSame(4, $this->payload()['data'][0]['received']['rating']);
        self::assertSame(5, $this->payload()['data'][0]['given']['rating']);
        $this->client->request('GET', '/api/me/safety/reviews?q=no-matching-collaboration');
        self::assertSame(0, $this->payload()['meta']['total']);
        $this->admin();
        $this->client->request('GET', '/api/me/safety/reviews');
        self::assertResponseIsSuccessful(); self::assertSame([], $this->payload()['data']);
        $this->client->request('GET', '/api/admin/safety/reviews');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPersonalReceivedReviewsRemainBlindAndBlockedHistoryAnonymous(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $review = new CampaignReview($this->application, $this->companyUser, $this->creatorUser, 2, 'A private review before the creator responds.');
        $em->persist($review); $em->flush();
        $this->client->loginUser($this->creatorUser);
        $this->client->request('GET', '/api/me/safety/reviews');
        self::assertResponseIsSuccessful();
        $row = $this->payload()['data'][0];
        self::assertNull($row['given']);
        self::assertNull($row['received']['rating']);
        self::assertNull($row['received']['comment']);
        self::assertSame('pending', $row['received']['status']);
        $this->db()->executeStatement('UPDATE campaign SET finished_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 15 * 86400), $this->application->getCampaign()->getId()]);
        $this->client->request('GET', '/api/me/safety/reviews');
        self::assertSame(2, $this->payload()['data'][0]['received']['rating']);
        self::assertSame('published', $this->payload()['data'][0]['received']['status']);
        $this->write('POST', '/api/me/safety/company/review-company/block', []);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/me/safety/reviews');
        self::assertResponseIsSuccessful();
        self::assertNull($this->payload()['data'][0]['email']);
        self::assertStringNotContainsString('DN kozmetik', json_encode($this->payload()));
        $this->client->request('GET', '/api/me/safety/reviews?q=DN%20kozmetik');
        self::assertSame(0, $this->payload()['meta']['total']);
    }

    public function testReportScreenshotsAreCompressedPrivateAndProtectedFromOtherAccounts(): void
    {
        $this->client->loginUser($this->creatorUser);
        $this->client->request('GET', '/api/auth/csrf'); $csrf = $this->payload()['csrfToken'];
        $path = tempnam(sys_get_temp_dir(), 'wave-report-test-');
        $image = imagecreatetruecolor(1800, 1000); imagepng($image, $path);
        try {
            $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'screenshot.png', 'image/png', test: true);
            $this->client->request('POST', '/api/media?folder=report-evidence', [], ['file' => $file], ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(201);
            $media = $this->payload()['data'];
            self::assertSame('image/webp', $media['mimeType']);
            self::assertSame(1600, $media['width']);
            $id = $media['id'];
            $this->client->loginUser($this->companyUser);
            $this->client->request('GET', '/api/media/'.$id.'/file');
            self::assertResponseStatusCodeSame(404);
            $this->write('POST', '/api/me/safety/creator/review-creator/report', ['category' => 'spam', 'reason' => 'messages', 'attachmentIds' => [$id]]);
            self::assertResponseStatusCodeSame(400);
            $this->client->loginUser($this->creatorUser);
            $this->write('POST', '/api/me/safety/company/review-company/report', ['category' => 'spam', 'reason' => 'messages', 'attachmentIds' => [$id]]);
            self::assertResponseIsSuccessful();
            $this->write('DELETE', '/api/media/'.$id, []);
            self::assertResponseStatusCodeSame(409);
            $this->admin();
            $this->client->request('GET', '/api/admin/safety/reports');
            self::assertSame($id, $this->payload()['data'][0]['attachments'][0]['id']);
            $this->write('POST', '/api/me/safety/creator/review-creator/block', []);
            self::assertResponseIsSuccessful();
            $this->client->request('GET', '/api/media/'.$id.'/file');
            self::assertResponseIsSuccessful();
        } finally { if (is_file($path)) { unlink($path); } }
    }

    private function admin(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $this->stranger->getId());
        $user->setAdmin(true); $em->flush(); $this->client->loginUser($user);
    }
    private function db(): \Doctrine\DBAL\Connection { return static::getContainer()->get(EntityManagerInterface::class)->getConnection(); }
    private function write(string $method, string $path, array $body): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = $this->payload()['csrfToken'];
        $this->client->jsonRequest($method, $path, $body, ['HTTP_X_CSRF_TOKEN' => $csrf]);
    }

    private function user(string $email, string $role): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User($email, $role, true);
        $user->setEmailVerified(true);
        $user->setHideMyAccount(false);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'old-long-password'));
        $em->persist($user);
        return $user;
    }

    private function campaign(Company $company, string $status, string $currency = 'BAM'): Campaign
    {
        return new Campaign('campaign-'.bin2hex(random_bytes(5)), 'Campaign review test', 'Campaign summary', 'Campaign description', 'Travel', ['Instagram'], ['Post'], 100, 500, 'Sarajevo', 1, new DateTimeImmutable('+1 month'), new DateTimeImmutable(), $company, status: $status, currency: $currency);
    }

    private function requestReview(array $data): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = $this->payload()['csrfToken'];
        $this->client->jsonRequest('POST', '/api/me/applications/'.$this->application->getId().'/review', $data, ['HTTP_X_CSRF_TOKEN' => $csrf]);
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
