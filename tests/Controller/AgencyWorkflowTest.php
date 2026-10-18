<?php

namespace App\Tests\Controller;

use App\Entity\Application;
use App\Entity\CampaignReview;
use App\Entity\Offer;
use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AgencyWorkflowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    public function testAgencyAppliesChatsAcceptsOfferCompletesAndReviewsWithoutLosingCompanyCapabilities(): void
    {
        $agency = $this->company('agency', true);
        $brand = $this->company('brand');
        $campaign = $this->campaign($brand, 'brand-campaign');
        $own = $this->campaign($agency, 'agency-campaign');
        $this->client->loginUser($agency, 'main');
        $this->request('PUT', '/api/me/profile', $this->agencyBody());
        self::assertResponseIsSuccessful();
        $profile = $this->payload()['data'];
        self::assertSame('company', $profile['accountType']);
        self::assertTrue($profile['canOfferServices']);
        self::assertSame(['Campaign strategy', 'Content production'], array_column($profile['profile']['services'], 'title'));
        self::assertCount(1, $profile['profile']['portfolio']);
        $this->request('GET', '/api/me/campaigns');
        self::assertResponseIsSuccessful();
        self::assertSame($own->getId(), $this->payload()['data'][0]['id']);
        $this->request('POST', '/api/campaigns/agency-campaign/applications', ['message' => 'We would like to apply to our own campaign.']);
        self::assertResponseStatusCodeSame(403);
        $this->request('POST', '/api/campaigns/brand-campaign/applications', ['message' => 'Our agency can deliver the complete campaign.', 'proposal' => ['amount' => 500, 'scope' => 'Two videos and a report', 'timeline' => 'Within 10 days', 'team' => 'Two specialists']]);
        self::assertResponseStatusCodeSame(201);
        $application = $this->payload()['data'];
        self::assertTrue($application['creator']['isAgency']);
        self::assertSame('company-profile', $application['creator']['profileRoute']);
        self::assertSame('agency', $application['creator']['slug']);
        self::assertSame(500, $application['proposal']['amount']);
        $this->request('POST', '/api/campaigns/brand-campaign/applications', ['message' => 'This second application should be rejected.']);
        self::assertResponseStatusCodeSame(409);
        $this->request('GET', '/api/me/applications');
        self::assertCount(1, $this->payload()['data']);
        $this->request('GET', '/api/creators');
        self::assertSame([], $this->payload()['data']);
        $this->request('GET', '/api/creators/'.$this->em->find(User::class, $agency->getId())->getCreator()->getSlug());
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', '/api/companies/agency');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload()['data']['portfolio']);

        $this->client->loginUser($brand, 'main');
        $this->request('GET', '/api/company/campaigns/brand-campaign/applications');
        self::assertTrue($this->payload()['data'][0]['creator']['isAgency']);
        $this->request('POST', '/api/company/applications/'.$application['id'].'/shortlist');
        self::assertResponseIsSuccessful();
        $this->request('GET', '/api/me/conversations');
        $conversation = $this->payload()['data'][0]['id'];
        $this->request('POST', '/api/company/applications/'.$application['id'].'/offer', ['dueDate' => (new DateTimeImmutable('+14 days'))->format('Y-m-d'), 'amount' => 500, 'message' => 'Please deliver the two videos as discussed.']);
        self::assertResponseStatusCodeSame(201);
        $offer = $this->payload()['data']['id'];
        $this->client->loginUser($agency, 'main');
        $this->request('GET', '/api/me/offers');
        self::assertCount(1, $this->payload()['data']);
        $this->request('POST', '/api/me/offers/'.$offer.'/respond', ['decision' => 'reject']);
        self::assertResponseIsSuccessful();
        $this->request('POST', '/api/me/conversations/'.$conversation.'/messages', ['body' => 'Can we clarify the scope before confirming?']);
        self::assertResponseStatusCodeSame(201);
        $this->request('GET', '/api/me/dashboard');
        self::assertSame(1, $this->payload()['data']['serviceCounts']['applications']);
        self::assertSame(0, $this->payload()['data']['serviceCounts']['offers']);
        $this->client->loginUser($brand, 'main');
        $this->request('POST', '/api/company/conversations/'.$conversation.'/hire', ['dueDate' => (new DateTimeImmutable('+14 days'))->format('Y-m-d'), 'amount' => 500, 'message' => 'The revised scope includes two videos and one revision.']);
        self::assertResponseStatusCodeSame(201);
        $revisedOffer = $this->payload()['data']['id'];
        self::assertNotSame($offer, $revisedOffer);
        $offer = $revisedOffer;
        $this->client->loginUser($agency, 'main');
        $this->request('GET', '/api/me/dashboard');
        self::assertSame(1, $this->payload()['data']['serviceCounts']['applications']);
        self::assertSame(1, $this->payload()['data']['serviceCounts']['offers']);
        $this->request('POST', '/api/me/offers/'.$offer.'/respond', ['decision' => 'accept']);
        self::assertResponseIsSuccessful();
        $this->request('POST', '/api/me/conversations/'.$conversation.'/messages', ['body' => 'We have started the production.']);
        self::assertResponseStatusCodeSame(201);
        $this->request('GET', '/api/me/inbox');
        self::assertSame('provider', $this->payload()['data'][0]['viewerRole']);
        $this->request('GET', '/api/me/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['data']['counts']['campaigns']);
        self::assertSame(1, $this->payload()['data']['serviceCounts']['active']);
        $this->client->loginUser($brand, 'main');
        $this->request('POST', '/api/me/conversations/'.$conversation.'/messages', ['body' => 'Thank you for the update.']);
        self::assertResponseStatusCodeSame(201);
        $this->client->loginUser($agency, 'main');
        $this->request('POST', '/api/me/offers/'.$offer.'/work', ['action' => 'submit']);
        self::assertResponseIsSuccessful();
        $this->client->loginUser($brand, 'main');
        $this->request('POST', '/api/me/offers/'.$offer.'/work', ['action' => 'complete']);
        self::assertResponseIsSuccessful();
        $this->request('PUT', '/api/company/campaigns/'.$campaign->getId().'/status', ['status' => 'finished']);
        self::assertResponseIsSuccessful();
        $this->request('POST', '/api/me/applications/'.$application['id'].'/review', ['rating' => 5, 'comment' => 'Excellent agency delivery and communication.']);
        self::assertResponseStatusCodeSame(201);
        $this->client->loginUser($agency, 'main');
        $this->request('POST', '/api/me/applications/'.$application['id'].'/review', ['rating' => 4, 'comment' => 'Clear brief and a helpful company contact.']);
        self::assertResponseStatusCodeSame(201);
        $this->request('GET', '/api/companies/agency/reviews?role=provider');
        self::assertSame(1, $this->payload()['summary']['reviewCount']);
        self::assertSame(5.0, (float) $this->payload()['summary']['average']);
        $this->request('GET', '/api/companies/agency/reviews?role=client');
        self::assertSame(0, $this->payload()['summary']['reviewCount']);
        $individual = new User('review-creator@example.test', 'ROLE_CREATOR');
        $individual->setPassword('unused');
        $individual->setCreator(new Creator('review-creator', 'Reviewer', 'Travel', 'Sarajevo', '', []));
        $this->em->persist($individual);
        $ownCampaign = $this->em->find(Campaign::class, $own->getId());
        $ownCampaign->setStatus('finished');
        $hired = new Application($ownCampaign, $individual->getCreator(), 'A completed agency client collaboration.');
        $fee = new Offer($hired, 100, 'Agreed work for the agency.');
        $fee->respond('accepted'); $hired->setOffer($fee); $hired->setStatus('accepted');
        $this->em->persist($hired); $this->em->persist($fee);
        $agencyOwner = $this->em->find(User::class, $agency->getId());
        $this->em->persist(new CampaignReview($hired, $individual, $agencyOwner, 2, 'The brief could have been more detailed.'));
        $this->em->persist(new CampaignReview($hired, $agencyOwner, $individual, 5, 'Excellent work from this creator.'));
        $this->em->flush();
        $this->request('GET', '/api/companies/agency/reviews?role=client');
        self::assertSame(1, $this->payload()['summary']['reviewCount']);
        self::assertSame(2.0, (float) $this->payload()['summary']['average']);
        $this->request('GET', '/api/companies/agency/reviews?role=provider');
        self::assertSame(1, $this->payload()['summary']['reviewCount']);
        self::assertSame(5.0, (float) $this->payload()['summary']['average']);
        $this->request('GET', '/api/me/collaboration-summary');
        self::assertSame(500, $this->payload()['data']['serviceTotals'][0]['amount']);
        $this->request('GET', '/api/companies/agency/reviews?role=invalid');
        self::assertResponseStatusCodeSame(400);
    }

    public function testInvitationsInquiriesAndBothSidesOfInboxUseOneAgencyCompanyIdentity(): void
    {
        $agency = $this->company('agency', true);
        $client = $this->company('client');
        $this->campaign($client, 'client-campaign');
        // Existing agencies can be invited even before saving the new optional fields.
        $this->client->loginUser($client, 'main');
        $this->request('POST', '/api/company/campaigns/client-campaign/invitations', ['agencyId' => $agency->getCompany()->getId(), 'message' => 'Please join our campaign.']);
        self::assertResponseStatusCodeSame(201);
        $invitationId = $this->payload()['data']['id'];
        $this->client->loginUser($agency, 'main');
        $this->request('GET', '/api/me/invitations');
        self::assertCount(1, $this->payload()['data']);
        $this->request('POST', '/api/me/invitations/'.$invitationId.'/respond', ['decision' => 'accept']);
        self::assertResponseIsSuccessful();
        $this->client->loginUser($client, 'main');
        $this->request('POST', '/api/companies/agency/inquiries', ['servicePackage' => true, 'message' => 'We would like your content production service.']);
        self::assertResponseStatusCodeSame(201);
        $inquiryId = $this->payload()['data']['id'];
        $this->client->loginUser($agency, 'main');
        $this->request('GET', '/api/me/inquiries');
        self::assertSame('creator', $this->payload()['data'][0]['role']);
        $this->request('POST', '/api/me/inquiries/'.$inquiryId.'/decision', ['decision' => 'accept']);
        self::assertResponseIsSuccessful();
        $this->client->loginUser($client, 'main');
        $this->request('POST', '/api/company/inquiries/'.$inquiryId.'/hire', ['amount' => 600, 'currency' => 'BAM', 'dueDate' => (new DateTimeImmutable('+14 days'))->format('Y-m-d'), 'message' => 'Please produce the two videos as agreed in this conversation.']);
        self::assertResponseStatusCodeSame(201);
        $directOffer = $this->payload()['data']['id'];
        $this->client->loginUser($agency, 'main');
        $this->request('POST', '/api/me/inquiry-offers/'.$directOffer.'/respond', ['decision' => 'accept']);
        self::assertResponseIsSuccessful();
        self::assertSame('accepted', $this->payload()['data']['status']);
        // The same agency can be a client of an individual creator.
        $creatorUser = new User('creator@example.test', 'ROLE_CREATOR');
        $creatorUser->setPassword('unused');
        $creatorUser->setCreator(new Creator('individual', 'Individual creator', 'Travel', 'Sarajevo', '', []));
        $this->em->persist($creatorUser);
        $this->em->flush();
        $this->request('POST', '/api/creators/individual/inquiries', ['servicePackage' => true, 'message' => 'We would like to hire you for our own project.']);
        self::assertResponseStatusCodeSame(201);
        $outgoing = $this->payload()['data']['id'];
        $this->client->loginUser($creatorUser, 'main');
        $this->request('POST', '/api/me/inquiries/'.$outgoing.'/decision', ['decision' => 'accept']);
        self::assertResponseIsSuccessful();
        $this->client->loginUser($agency, 'main');
        $this->request('GET', '/api/me/inquiries');
        self::assertCount(2, $this->payload()['data']);
        $this->request('GET', '/api/me/inbox');
        self::assertCount(3, $this->payload()['data']);
        self::assertContains('provider', array_column($this->payload()['data'], 'viewerRole'));
        self::assertContains('client', array_column($this->payload()['data'], 'viewerRole'));
        $this->request('POST', '/api/companies/agency/inquiries', ['servicePackage' => true, 'message' => 'This must not create a self inquiry.']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTypeSwitchPreservesDataAndHistoryButBrandsCannotStartProviderWork(): void
    {
        $agency = $this->company('agency', true);
        $brand = $this->company('brand');
        $this->campaign($brand, 'opportunity');
        $this->client->loginUser($brand, 'main');
        $this->request('POST', '/api/campaigns/opportunity/applications', ['message' => 'A brand must not apply as a service provider.']);
        self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($agency, 'main');
        $this->request('PUT', '/api/me/profile', $this->agencyBody());
        self::assertResponseIsSuccessful();
        $this->request('PUT', '/api/me/profile', ['name' => 'Agency', 'industries' => ['Travel'], 'companyType' => 'brand']);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['data']['canOfferServices']);
        $this->request('POST', '/api/campaigns/opportunity/applications', ['message' => 'A former agency cannot start provider work.']);
        self::assertResponseStatusCodeSame(403);
        $this->request('PUT', '/api/me/profile', ['name' => 'Agency', 'industries' => ['Travel'], 'companyType' => 'agency']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload()['data']['profile']['portfolio']);
        self::assertCount(1, $this->payload()['data']['profile']['packages']);
        $this->request('POST', '/api/campaigns/opportunity/applications', ['message' => 'A proposal with a forged negative amount.', 'proposal' => ['amount' => -1]]);
        self::assertResponseStatusCodeSame(400);
        $this->request('PUT', '/api/me/profile', ['name' => 'Agency', 'industries' => [str_repeat('A', 100)], 'companyType' => 'agency']);
        self::assertResponseIsSuccessful();
        self::assertSame(str_repeat('A', 100), $this->payload()['data']['profile']['industries'][0]);
        $this->em->find(User::class, $agency->getId())->setApproved(false);
        $this->em->flush();
        $this->request('POST', '/api/campaigns/opportunity/applications', ['message' => 'An unapproved agency cannot apply.']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testVerificationAndBlocksStillProtectAgencyApplicationsInvitationsAndInquiries(): void
    {
        $agency = $this->company('agency', true);
        $brand = $this->company('brand');
        $this->campaign($brand, 'opportunity');
        $agency->setEmailVerified(false);
        $this->em->flush();
        $this->client->loginUser($agency, 'main');
        $this->request('POST', '/api/campaigns/opportunity/applications', ['message' => 'An unverified agency must not apply.']);
        self::assertResponseStatusCodeSame(403);
        $agency = $this->em->find(User::class, $agency->getId());
        $brand = $this->em->find(User::class, $brand->getId());
        $agency->setEmailVerified(true);
        $this->em->persist(new \App\Entity\UserBlock(bin2hex(random_bytes(16)), $agency, $brand, new \DateTimeImmutable(), null));
        $this->em->flush();
        $this->request('POST', '/api/campaigns/opportunity/applications', ['message' => 'A blocked company must not receive this application.']);
        self::assertResponseStatusCodeSame(409);
        $this->client->loginUser($brand, 'main');
        $this->request('POST', '/api/company/campaigns/opportunity/invitations', ['agencyId' => $agency->getCompany()->getId(), 'message' => 'An invitation to a blocked agency.']);
        self::assertResponseStatusCodeSame(409);
        $this->request('POST', '/api/companies/agency/inquiries', ['servicePackage' => true, 'message' => 'An inquiry to a blocked agency.']);
        self::assertResponseStatusCodeSame(409);
    }

    private function company(string $slug, bool $agency = false): User
    {
        $user = new User($slug.'@example.test', 'ROLE_COMPANY');
        $user->setPassword('unused');
        $user->setPhone('+38761123456'); $user->setCity('Sarajevo'); $user->setCountryCode('BA');
        $company = new Company($slug, ucfirst($slug), 'Travel');
        $company->setCompanyType($agency ? 'agency' : 'brand');
        $user->setCompany($company);
        $this->em->persist($user); $this->em->flush();
        return $user;
    }

    private function campaign(User $owner, string $slug): Campaign
    {
        $campaign = new Campaign($slug, 'Campaign '.$slug, 'A clear brief for a collaboration.', 'Create helpful content for our company.', 'Travel', ['Instagram'], ['1 video'], 100, 1000, 'Sarajevo', 3, new \DateTimeImmutable('+30 days'), new \DateTimeImmutable(), $owner->getCompany());
        $this->em->persist($campaign); $this->em->flush();
        return $campaign;
    }

    private function agencyBody(): array
    {
        return ['name' => 'Agency', 'industries' => ['Travel'], 'companyType' => 'agency', 'services' => ['Campaign strategy', 'Content production'],
            'presentation' => ['languages' => ['bs', 'en'], 'availability' => 'from', 'availableFrom' => '2026-12-01'],
            'portfolio' => [['type' => 'video', 'title' => 'Our project', 'url' => 'https://youtu.be/abcdefghijk', 'source' => 'sample', 'publicPermission' => true]],
            'packages' => [['title' => 'Content production', 'description' => 'Two videos and a campaign report.', 'platform' => 'All', 'price' => 500, 'currency' => 'BAM']],
            'faqs' => [['question' => 'Do you work remotely?', 'answer' => 'Yes, our team works with companies remotely.']]];
    }

    private function request(string $method, string $url, array $body = []): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = $this->payload()['csrfToken'];
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: $method === 'GET' ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function payload(): array { return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }
}
