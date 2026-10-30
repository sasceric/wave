<?php

namespace App\Tests\Controller;

use App\Entity\{Application, Campaign, Company, Creator, CreatorInquiry, InquiryOffer, Offer, User};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCollaborationsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private Creator $creator;
    private Creator $studio;
    private Company $brand;
    private Company $agency;
    private Campaign $campaign;
    private CreatorInquiry $completed;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata); $schema->createSchema($metadata);
        $this->admin = $this->user('admin@example.test', 'ROLE_CREATOR'); $this->admin->setAdmin(true);
        $creatorUser = $this->user('creator@example.test', 'ROLE_CREATOR');
        $this->creator = new Creator('service-creator', 'Service Creator', 'Travel', 'Sarajevo', '', []);
        $creatorUser->setCreator($this->creator);
        $brandUser = $this->user('brand@example.test', 'ROLE_COMPANY');
        $this->brand = new Company('service-brand', 'Service Brand', 'Travel'); $brandUser->setCompany($this->brand);
        $agencyUser = $this->user('agency@example.test', 'ROLE_COMPANY');
        $this->agency = new Company('service-agency', 'Service Agency', 'Travel'); $this->agency->setCompanyType('agency'); $agencyUser->setCompany($this->agency);
        $this->studio = new Creator('private-studio', 'Private studio name', 'Travel', 'Sarajevo', '', []); $this->studio->markAgencyProfile(); $agencyUser->setCreator($this->studio);
        foreach ([$this->creator, $this->studio, $this->brand, $this->agency] as $entity) $this->em->persist($entity);
        $this->inquiry('pending'); $this->inquiry('discussing'); $this->inquiry('offered');
        $this->completed = $this->inquiry('completed');
        $this->inquiry('booked'); $this->inquiry('rejected'); $this->inquiry('cancelled');
        $agencyRequest = $this->inquiry('booked', $this->studio); $agencyRequest->getOffer()->submitWork();
        $this->campaign = $this->campaign($this->brand, 'open-campaign');
        $this->application($this->campaign, $this->creator, true);
        $this->application($this->campaign, $this->studio, false);
        $this->campaign($this->brand, 'empty-campaign');
        $agencyCampaign = $this->campaign($this->agency, 'agency-campaign');
        $this->em->persist(new Application($agencyCampaign, $this->creator, 'Private application body should not be exposed.'));
        $this->em->flush();
    }

    public function testOnlyVerifiedAdminsCanReadCollaborationHistoryAndPrivateResponsesAreNeverCached(): void
    {
        $moderator = $this->user('moderator@example.test', 'ROLE_CREATOR'); $moderator->setModerator(true);
        $unverified = $this->user('unverified-admin@example.test', 'ROLE_CREATOR'); $unverified->setAdmin(true); $unverified->setEmailVerified(false);
        $this->em->flush();
        $this->client->request('GET', '/api/admin/collaborations'); self::assertResponseStatusCodeSame(401);
        foreach ([$this->creator->getOwner(), $this->brand->getOwner(), $moderator, $unverified] as $user) {
            $this->client->loginUser($user); $this->client->request('GET', '/api/admin/collaborations'); self::assertResponseStatusCodeSame(403);
        }
        $this->client->loginUser($this->admin);
        $payload = $this->get('/api/admin/collaborations');
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString('Private request body', json_encode($payload));
        self::assertStringNotContainsString('creator@example.test', json_encode($payload));
    }

    public function testPackagesCountEngagementsNotItemsOrOfferRevisionsAndOnlyAcceptedOffersAreBooked(): void
    {
        $this->client->loginUser($this->admin);
        $scope = '&providerType=creator&providerId='.$this->creator->getId();
        $payload = $this->get('/api/admin/collaborations?kind=packages'.$scope);
        self::assertSame(['requests' => 3, 'engaged' => 1, 'completed' => 1], $payload['summary']);
        self::assertSame(7, $payload['meta']['total']);
        self::assertEqualsCanonicalizing(['pending', 'discussing', 'offered', 'booked', 'completed', 'rejected', 'cancelled'], array_column($payload['data'], 'status'));
        $completed = array_values(array_filter($payload['data'], fn (array $row): bool => $row['status'] === 'completed'))[0];
        self::assertSame(['Video', 'Photo'], $completed['packages']);
        self::assertSame(250, $completed['amount']); self::assertSame('EUR', $completed['currency']);
        self::assertNotNull($completed['acceptedAt']); self::assertNotNull($completed['completedAt']);
        $offered = array_values(array_filter($payload['data'], fn (array $row): bool => $row['status'] === 'offered'))[0];
        self::assertNull($offered['amount']); self::assertNull($offered['acceptedAt']);
        $payload = $this->get('/api/admin/collaborations?kind=packages&status=engaged'.$scope);
        self::assertSame(1, $payload['meta']['total']); self::assertSame(3, $payload['summary']['requests']);
        self::assertSame(['booked'], array_column($payload['data'], 'status'));
        $payload = $this->get('/api/admin/collaborations?kind=packages&status=requests'.$scope);
        self::assertSame(3, $payload['meta']['total']);
        self::assertEqualsCanonicalizing(['pending', 'discussing', 'offered'], array_column($payload['data'], 'status'));
        $payload = $this->get('/api/admin/collaborations?kind=packages&status=completed'.$scope);
        self::assertCount(1, $payload['data']);
        $payload = $this->get('/api/admin/collaborations?kind=packages&q=Photo'.$scope);
        self::assertCount(1, $payload['data']);
    }

    public function testOpenCampaignsShowCompletedEngagementsAndAgencyProviderAndClientRolesRemainSeparate(): void
    {
        $this->client->loginUser($this->admin);
        $payload = $this->get('/api/admin/collaborations?kind=campaigns&campaignId='.$this->campaign->getId());
        self::assertSame(['requests' => 0, 'engaged' => 1, 'completed' => 1], $payload['summary']);
        $completed = array_values(array_filter($payload['data'], fn (array $row): bool => $row['status'] === 'completed'))[0];
        self::assertSame('open', $completed['campaignStatus']);
        $payload = $this->get('/api/admin/collaborations?kind=packages&providerType=agency&providerId='.$this->agency->getId());
        self::assertSame(1, $payload['meta']['total']); self::assertSame('Service Agency', $payload['data'][0]['provider']['name']);
        $companies = $this->get('/api/admin/catalog/companies')['data'];
        $agency = array_values(array_filter($companies, fn (array $row): bool => $row['id'] === $this->agency->getId()))[0];
        $brand = array_values(array_filter($companies, fn (array $row): bool => $row['id'] === $this->brand->getId()))[0];
        self::assertSame(['requests' => 0, 'engaged' => 1, 'completed' => 0], $agency['activity']['packages']);
        self::assertSame(['applications' => 1, 'engaged' => 1, 'completed' => 0], $agency['activity']['providerCampaigns']);
        self::assertSame(1, $agency['activity']['campaigns']['applications']);
        self::assertSame(0, $agency['activity']['campaigns']['engaged']);
        self::assertSame(['campaigns' => 2, 'applications' => 2, 'engaged' => 1, 'completed' => 1], $brand['activity']['campaigns']);
        self::assertSame(0, $brand['activity']['packages']['requests']);
        $campaigns = $this->get('/api/admin/catalog/campaigns?companyId='.$this->brand->getId());
        self::assertSame(2, $campaigns['meta']['total']);
        $creator = $this->get('/api/admin/catalog/creators')['data'][0];
        self::assertSame(['requests' => 3, 'engaged' => 1, 'completed' => 1], $creator['activity']['packages']);
    }

    public function testCampaignBucketsExcludeEachOtherIncludingLegacyFinishedAndCancelledCampaigns(): void
    {
        $this->client->loginUser($this->admin);
        $scope = '&campaignId='.$this->campaign->getId();
        $active = $this->get('/api/admin/collaborations?kind=campaigns&status=engaged'.$scope);
        self::assertSame(['booked'], array_column($active['data'], 'status'));
        self::assertSame('Service Agency', $active['data'][0]['provider']['name']);
        $open = $this->get('/api/admin/collaborations?kind=campaigns&status=requests'.$scope);
        self::assertSame([], $open['data']);
        $finished = $this->get('/api/admin/collaborations?kind=campaigns&status=completed'.$scope);
        self::assertSame(['completed'], array_column($finished['data'], 'status'));
        self::assertSame([], array_intersect(array_column($active['data'], 'id'), array_column($finished['data'], 'id')));
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $db->update('campaign_offer', ['work_status' => 'submitted'], ['application_id' => $active['data'][0]['id']]);
        $submitted = $this->get('/api/admin/collaborations?kind=campaigns&status=engaged'.$scope);
        self::assertSame(1, $submitted['meta']['total']);
        self::assertSame('submitted', $submitted['data'][0]['workStatus']);
        $db->update('campaign', ['status' => 'finished', 'finished_at' => '2026-10-30 12:00:00'], ['id' => $this->campaign->getId()]);
        $legacy = $this->get('/api/admin/collaborations?kind=campaigns&status=engaged'.$scope);
        self::assertSame([], $legacy['data']);
        self::assertSame(['requests' => 0, 'engaged' => 0, 'completed' => 2], $legacy['summary']);
        $db->update('campaign', ['status' => 'cancelled', 'finished_at' => null], ['id' => $this->campaign->getId()]);
        $cancelled = $this->get('/api/admin/collaborations?kind=campaigns&status=engaged'.$scope);
        self::assertSame([], $cancelled['data']);
        self::assertSame(['requests' => 0, 'engaged' => 0, 'completed' => 1], $cancelled['summary']);
    }

    public function testInvitedHiresDoNotInflateApplicationCountsAndPublicResourcesDoNotExposeActivity(): void
    {
        $invitation = new \App\Entity\CampaignInvitation($this->campaign, $this->studio, $this->brand->getOwner(), 'Invitation to collaborate');
        $invitation->respond('accepted'); $this->em->persist($invitation); $this->em->flush();
        $this->client->loginUser($this->admin);
        $payload = $this->get('/api/admin/collaborations?kind=campaigns&origin=invitation&campaignId='.$this->campaign->getId());
        self::assertSame(1, $payload['meta']['total']); self::assertSame('invitation', $payload['data'][0]['origin']);
        $payload = $this->get('/api/admin/collaborations?kind=campaigns&origin=application&campaignId='.$this->campaign->getId());
        self::assertSame(1, $payload['meta']['total']); self::assertSame('creator', $payload['data'][0]['provider']['type']);
        $rows = $this->get('/api/admin/catalog/campaigns')['data'];
        $campaign = array_values(array_filter($rows, fn (array $row): bool => $row['id'] === $this->campaign->getId()))[0];
        self::assertSame(1, $campaign['activity']['campaigns']['applications']);
        self::assertSame(1, $campaign['activity']['campaigns']['engaged']);
        foreach ($this->get('/api/creators?view=card')['data'] as $row) self::assertArrayNotHasKey('activity', $row);
        foreach ($this->get('/api/companies?view=card')['data'] as $row) self::assertArrayNotHasKey('activity', $row);
    }

    public function testPaginationReturnsDisjointBatchesInStableOrder(): void
    {
        for ($i = 0; $i < 30; ++$i) $this->inquiry('pending');
        $this->em->flush(); $this->client->loginUser($this->admin);
        $one = $this->get('/api/admin/collaborations?limit=25&page=1');
        $two = $this->get('/api/admin/collaborations?limit=25&page=2');
        self::assertCount(25, $one['data']); self::assertCount(13, $two['data']);
        self::assertSame(38, $one['meta']['total']);
        self::assertSame([], array_values(array_intersect(array_column($one['data'], 'id'), array_column($two['data'], 'id'))));
    }

    public function testDateFiltersValidationDeletedHistoryAndPaginationAreSafe(): void
    {
        $this->client->loginUser($this->admin);
        $db = $this->em->getConnection();
        $db->update('creator_inquiry', ['created_at' => '2026-10-05 12:00:00'], ['id' => $this->completed->getId()]);
        $payload = $this->get('/api/admin/collaborations?kind=packages&from=2026-10-05&to=2026-10-05');
        self::assertSame(1, $payload['meta']['total']);
        foreach (['kind=bad', 'status=accepted', 'providerType=brand', 'providerId=-1', 'clientId=1%20OR%201=1', 'from=2026-02-30', 'from=0000-01-01', 'from=2026-10-06&to=2026-10-01', 'limit=100000', 'sort=created_at%20DESC', 'direction=DROP'] as $invalid) {
            $this->client->request('GET', '/api/admin/collaborations?'.$invalid); self::assertResponseStatusCodeSame(400);
        }
        $payload = $this->get('/api/admin/collaborations?kind=packages&page=100&limit=25'); self::assertSame(1, $payload['meta']['page']);
        $db->update('wave_user', ['deleted_at' => gmdate('Y-m-d H:i:s')], ['id' => $this->creator->getOwner()->getId()]);
        $payload = $this->get('/api/admin/collaborations?kind=packages&providerType=creator&providerId='.$this->creator->getId().'&locale=en');
        self::assertSame('Deleted account', $payload['data'][0]['provider']['name']);
    }

    private function inquiry(string $status, ?Creator $creator = null): CreatorInquiry
    {
        $q = new CreatorInquiry($creator ?? $this->creator, $this->brand, 'video', 'Video', 250, 200, 'Private request body should not be exposed.', selectedPackages: $status === 'completed' ? [['title' => 'Video'], ['title' => 'Photo']] : []);
        if ($status !== 'pending') $q->respond($status === 'rejected' ? 'rejected' : 'accepted');
        if (in_array($status, ['offered', 'booked', 'completed', 'cancelled'], true)) {
            if ($status === 'completed') { $old = new InquiryOffer($q, 100, 'BAM', new \DateTimeImmutable('+1 month'), 'Previous offer'); $old->respond('rejected'); $this->em->persist($old); }
            $offer = new InquiryOffer($q, 250, 'EUR', new \DateTimeImmutable('+1 month'), 'Offer');
            if ($status !== 'offered') $offer->respond('accepted');
            if ($status === 'completed') $offer->completeWork();
            if ($status === 'cancelled') $offer->cancel('Cancelled request');
            $this->em->persist($offer);
        }
        $this->em->persist($q); return $q;
    }

    private function campaign(Company $company, string $slug): Campaign
    {
        $campaign = new Campaign($slug, $slug, 'Summary', 'Description', 'Travel', [], [], 100, 500, 'Sarajevo', 3, new \DateTimeImmutable('+1 month'), new \DateTimeImmutable(), $company);
        $this->em->persist($campaign); return $campaign;
    }

    private function application(Campaign $campaign, Creator $creator, bool $completed): void
    {
        $application = new Application($campaign, $creator, 'Private application body should not be exposed.');
        $offer = new Offer($application, 300, 'Offer'); $offer->respond('accepted');
        if ($completed) $offer->completeWork();
        $this->em->persist($application); $this->em->persist($offer);
    }

    private function user(string $email, string $role): User
    {
        $user = new User($email, $role, true); $user->setEmailVerified(true); $user->setHideMyAccount(false); $user->setPassword('unused'); $this->em->persist($user); return $user;
    }

    private function get(string $path): array
    {
        $this->client->request('GET', $path); self::assertResponseIsSuccessful();
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
