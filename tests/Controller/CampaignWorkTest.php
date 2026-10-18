<?php

namespace App\Tests\Controller;

use App\Entity\{Application, Campaign, CampaignConversation, Company, Creator, Offer, User};
use App\Account\AgencyProfiles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CampaignWorkTest extends WebTestCase
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

    public function testClosedCampaignKeepsHiredCreatorAndAgencyWorkingUntilEachIsCompleted(): void
    {
        $brand = $this->user('brand', 'ROLE_COMPANY');
        $brand->setCompany(new Company('work-brand', 'Work Brand', 'Travel'));
        $creator = $this->user('creator', 'ROLE_CREATOR');
        $creator->setCreator(new Creator('work-creator', 'Work Creator', 'Travel', 'Sarajevo', 'Complete creator biography.', [], []));
        $agency = $this->user('agency', 'ROLE_COMPANY');
        $agencyCompany = new Company('work-agency', 'Work Agency', 'Travel');
        $agencyCompany->setCompanyType('agency');
        $agency->setCompany($agencyCompany);
        $this->em->flush();
        $agencyProvider = AgencyProfiles::ensure($agency, $this->em);
        $stranger = $this->user('stranger', 'ROLE_CREATOR');
        $stranger->setCreator(new Creator('work-stranger', 'Stranger', 'Travel', 'Sarajevo', 'Complete creator biography.', [], []));
        $campaign = new Campaign('work-campaign', 'Work campaign', 'Test collaboration completion', '<p>Deliver the agreed video and photographs.</p>', 'Travel', ['Instagram'], ['Video'], 100, 800, 'Sarajevo', 3, new DateTimeImmutable('+20 days'), new DateTimeImmutable(), $brand->getCompany());
        $this->em->persist($campaign);
        $offers = []; $conversations = [];
        foreach ([$creator, $agency] as $provider) {
            $application = new Application($campaign, $provider->getCreator(), 'We can deliver the agreed content.');
            $offer = new Offer($application, 300, 'Agreed work scope and deadline.', new DateTimeImmutable('+14 days'));
            $offer->respond('accepted');
            $conversation = new CampaignConversation($campaign, $provider->getCreator(), $brand);
            foreach ([$application, $offer, $conversation] as $entity) $this->em->persist($entity);
            $offers[] = $offer; $conversations[] = $conversation;
        }
        $unhired = new Application($campaign, $stranger->getCreator(), 'Not yet hired.');
        $unhired->setStatus('shortlisted');
        $unhiredChat = new CampaignConversation($campaign, $stranger->getCreator(), $brand);
        $this->em->persist($unhired); $this->em->persist($unhiredChat);
        $this->em->flush();
        self::assertTrue($agencyProvider->isAgencyProfile());

        $this->client->loginUser($brand);
        $this->write('PUT', '/api/company/campaigns/'.$campaign->getId().'/status', ['status' => 'closed']);
        self::assertResponseIsSuccessful();
        $this->write('PUT', '/api/company/campaigns/'.$campaign->getId().'/status', ['status' => 'finished']);
        self::assertResponseStatusCodeSame(409);
        $this->write('PUT', '/api/company/campaigns/'.$campaign->getId(), [
            'title' => 'Work campaign', 'summary' => 'Test collaboration completion', 'description' => '<p>Deliver the agreed video and photographs.</p>',
            'categories' => ['Travel'], 'channels' => ['Instagram'], 'deliverables' => ['Video'], 'budgetMin' => 100, 'budgetMax' => 800,
            'currency' => 'BAM', 'city' => 'Sarajevo', 'countryCode' => 'BA', 'creatorCount' => 3, 'closesAt' => (new DateTimeImmutable('+20 days'))->format('Y-m-d'), 'status' => 'finished',
        ]);
        self::assertResponseStatusCodeSame(409); // Editing cannot bypass the completion gate.
        $this->client->loginUser($stranger);
        $this->write('POST', '/api/me/conversations/'.$unhiredChat->getId().'/messages', ['body' => 'An unhired applicant cannot keep chatting.']);
        self::assertResponseStatusCodeSame(409);
        $this->write('POST', '/api/me/offers/'.$offers[0]->getId().'/work', ['action' => 'submit']);
        self::assertResponseStatusCodeSame(404);

        foreach ([$creator, $agency] as $index => $provider) {
            $offer = $offers[$index]; $conversation = $conversations[$index];
            foreach ([$provider, $brand] as $participant) {
                $this->client->loginUser($participant);
                $this->write('POST', '/api/me/conversations/'.$conversation->getId().'/messages', ['body' => 'Hired participants can continue on a closed campaign.']);
                self::assertResponseStatusCodeSame(201);
                $this->client->request('GET', '/api/me/conversations/'.$conversation->getId().'/messages');
                self::assertTrue($this->payload()['conversation']['chatOpen']);
            }
            $this->write('POST', '/api/me/offers/'.$offer->getId().'/work', ['action' => 'submit']);
            self::assertResponseStatusCodeSame(403); // Company cannot submit on the provider's behalf.
            $this->client->loginUser($provider);
            $this->write('POST', '/api/me/offers/'.$offer->getId().'/work', ['action' => 'complete']);
            self::assertResponseStatusCodeSame(403);
            $this->write('POST', '/api/me/offers/'.$offer->getId().'/work', ['action' => 'submit']);
            self::assertResponseIsSuccessful();
            self::assertSame('submitted', $this->payload()['data']['workStatus']);
            self::assertSame(2, $this->payload()['data']['campaign']['hiredCount']);
            $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM campaign_message');
            $this->write('POST', '/api/me/offers/'.$offer->getId().'/work', ['action' => 'submit']);
            self::assertSame($count, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM campaign_message'));
            $this->client->loginUser($brand);
            $this->write('POST', '/api/me/offers/'.$offer->getId().'/work', ['action' => 'changes', 'reason' => '']);
            self::assertResponseStatusCodeSame(400);
            $reason = 'Please correct the captions and include the agreed closing shot.';
            $this->write('POST', '/api/me/offers/'.$offer->getId().'/work', ['action' => 'changes', 'reason' => $reason]);
            self::assertResponseIsSuccessful();
            self::assertSame('changes_requested', $this->payload()['data']['workStatus']);
            self::assertSame($reason, $this->payload()['data']['workChangesReason']);
            self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM wave_notification WHERE type = 'work_changes_requested' AND recipient_id = ? AND conversation_id = ?", [$provider->getId(), $conversation->getId()]));
            $this->client->loginUser($provider);
            $this->write('POST', '/api/me/conversations/'.$conversation->getId().'/messages', ['body' => 'I will fix these items now.']);
            self::assertResponseStatusCodeSame(201);
            $this->write('POST', '/api/me/offers/'.$offer->getId().'/work', ['action' => 'submit']);
            self::assertResponseIsSuccessful();
            $this->client->loginUser($brand);
            $this->write('POST', '/api/me/offers/'.$offer->getId().'/work', ['action' => 'complete']);
            self::assertResponseIsSuccessful();
            self::assertSame('completed', $this->payload()['data']['workStatus']);
            $completed = $this->payload()['data']['workCompletedAt'];
            $this->write('POST', '/api/me/offers/'.$offer->getId().'/work', ['action' => 'complete']);
            self::assertSame($completed, $this->payload()['data']['workCompletedAt']);
            $this->write('POST', '/api/me/offers/'.$offer->getId().'/cancel', ['reason' => 'Completed work must not lose its review or totals.']);
            self::assertResponseStatusCodeSame(409);
            $this->write('POST', '/api/me/applications/'.$offer->getApplication()->getId().'/review', ['rating' => 5, 'comment' => 'Excellent work, all requested changes were delivered.']);
            self::assertResponseStatusCodeSame(201); // Review unlocked before the overall campaign finishes.
            $this->client->request('GET', '/api/me/conversations/'.$conversation->getId().'/messages');
            $events = array_values(array_filter($this->payload()['data'], static fn(array $message): bool => isset($message['event']['workChangesReason'])));
            self::assertSame($reason, $events[0]['event']['workChangesReason']);
            $this->client->loginUser($provider);
            $this->write('POST', '/api/me/offers/'.$offer->getId().'/work', ['action' => 'submit']);
            self::assertResponseStatusCodeSame(409);
            $this->write('POST', '/api/me/applications/'.$offer->getApplication()->getId().'/review', ['rating' => 4, 'comment' => 'Clear feedback and a helpful client throughout.']);
            self::assertResponseStatusCodeSame(201);
        }
        $this->client->loginUser($brand);
        $this->write('PUT', '/api/company/campaigns/'.$campaign->getId().'/status', ['status' => 'finished']);
        self::assertResponseIsSuccessful();
        self::assertSame('finished', $this->payload()['data']['status']);
        self::assertSame(2, $this->payload()['data']['hiredCount']);
    }

    public function testDirectCreatorAndAgencyReportWorkBeforeClientConfirmsOrRequestsChanges(): void
    {
        $brand = $this->user('direct-brand', 'ROLE_COMPANY');
        $brand->setCompany(new Company('direct-work-brand', 'Direct Brand', 'Travel'));
        $stranger = $this->user('direct-stranger', 'ROLE_CREATOR');
        $stranger->setCreator(new Creator('direct-stranger', 'Stranger', 'Travel', 'Sarajevo', 'Complete profile.', [], []));
        $this->em->flush();
        foreach (['creator', 'agency'] as $kind) {
            $brand = $this->em->find(User::class, $brand->getId());
            $provider = $this->user('direct-'.$kind, $kind === 'agency' ? 'ROLE_COMPANY' : 'ROLE_CREATOR');
            if ($kind === 'agency') {
                $company = new Company('direct-work-agency', 'Direct Agency', 'Travel');
                $company->setCompanyType('agency');
                $provider->setCompany($company);
                $this->em->flush();
                AgencyProfiles::ensure($provider, $this->em);
            } else {
                $provider->setCreator(new Creator('direct-work-creator', 'Direct Creator', 'Travel', 'Sarajevo', 'Complete profile.', [], []));
            }
            $inquiry = new \App\Entity\CreatorInquiry($provider->getCreator(), $brand->getCompany(), null, null, null, null, 'Let us discuss a direct collaboration.');
            $inquiry->respond('accepted');
            $old = new \App\Entity\InquiryOffer($inquiry, 300, 'BAM', new DateTimeImmutable('+14 days'), 'An older declined set of terms.');
            $old->respond('rejected');
            $offer = new \App\Entity\InquiryOffer($inquiry, 450, 'BAM', new DateTimeImmutable('+14 days'), 'The confirmed direct collaboration scope.');
            $offer->respond('accepted');
            foreach ([$inquiry, $old, $offer] as $entity) $this->em->persist($entity);
            $this->em->flush();
            $this->em->getConnection()->update('creator_inquiry', ['created_at' => '2026-01-01 12:00:00'], ['id' => $inquiry->getId()]);
            $url = '/api/me/inquiry-offers/'.$offer->getId().'/work';
            $this->client->loginUser($stranger);
            $this->write('POST', $url, ['action' => 'submit']);
            self::assertResponseStatusCodeSame(404);
            $this->client->loginUser($brand);
            $this->write('POST', $url, ['action' => 'submit']);
            self::assertResponseStatusCodeSame(403);
            $this->write('POST', $url, ['action' => 'complete']);
            self::assertResponseStatusCodeSame(409);
            $this->client->loginUser($provider);
            $this->client->jsonRequest('POST', $url, ['action' => 'submit']);
            self::assertResponseStatusCodeSame(403); // CSRF remains mandatory.
            $this->write('POST', '/api/me/inquiry-offers/'.$old->getId().'/work', ['action' => 'submit']);
            self::assertResponseStatusCodeSame(409);
            $this->write('POST', $url, ['action' => 'complete']);
            self::assertResponseStatusCodeSame(403);
            $this->write('POST', $url, ['action' => 'submit']);
            self::assertResponseIsSuccessful();
            self::assertSame('submitted', $this->payload()['data']['workStatus']);
            $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inquiry_message WHERE inquiry_id = ?', [$inquiry->getId()]);
            $this->write('POST', $url, ['action' => 'submit']);
            self::assertSame($count, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inquiry_message WHERE inquiry_id = ?', [$inquiry->getId()]));
            $this->client->loginUser($brand);
            $this->write('POST', $url, ['action' => 'changes', 'reason' => '']);
            self::assertResponseStatusCodeSame(400);
            $reason = 'Please correct the subtitles and include the final frame.';
            $this->write('POST', $url, ['action' => 'changes', 'reason' => $reason]);
            self::assertResponseIsSuccessful();
            self::assertSame('changes_requested', $this->payload()['data']['workStatus']);
            self::assertSame($reason, $this->payload()['data']['workChangesReason']);
            $this->write('POST', $url, ['action' => 'complete']);
            self::assertResponseStatusCodeSame(409); // Wait for a revised submission.
            $this->client->loginUser($provider);
            $this->write('POST', '/api/me/inquiries/'.$inquiry->getId().'/messages', ['body' => 'The corrected version is now ready.']);
            self::assertResponseStatusCodeSame(201);
            $this->write('POST', $url, ['action' => 'submit']);
            self::assertResponseIsSuccessful();
            self::assertNull($this->payload()['data']['workChangesReason']);
            $this->client->loginUser($brand);
            $this->write('POST', $url, ['action' => 'complete']);
            self::assertResponseIsSuccessful();
            self::assertSame('completed', $this->payload()['data']['workStatus']);
            self::assertSame(450, $this->payload()['data']['amount']);
            $completed = $this->payload()['data']['workCompletedAt'];
            $this->write('POST', $url, ['action' => 'complete']);
            self::assertSame($completed, $this->payload()['data']['workCompletedAt']);
            $this->write('POST', '/api/me/inquiry-offers/'.$offer->getId().'/cancel', ['reason' => 'A completed engagement cannot be cancelled.']);
            self::assertResponseStatusCodeSame(409);
            foreach ([$provider, $brand] as $participant) {
                $this->client->loginUser($participant);
                $this->client->request('GET', '/api/me/inquiries/'.$inquiry->getId().'/messages');
                self::assertResponseIsSuccessful();
                $payload = $this->payload();
                self::assertSame('completed', $payload['inquiry']['engagement']['offer']['workStatus']);
                $last = $payload['data'][array_key_last($payload['data'])];
                self::assertSame($last['body'], $payload['inquiry']['lastMessage']);
                self::assertSame($last['createdAt'], $payload['inquiry']['lastMessageAt']);
                self::assertSame('work_completed', $last['event']['type']);
                $events = array_values(array_filter(array_column($payload['data'], 'event')));
                self::assertSame(['work_submitted', 'work_changes_requested', 'work_submitted', 'work_completed'], array_column($events, 'type'));
                self::assertSame($reason, $events[1]['workChangesReason']);
                $this->client->request('GET', '/api/me/inbox');
                self::assertSame($inquiry->getId(), $this->payload()['data'][0]['id']);
                self::assertSame('inquiry', $this->payload()['data'][0]['threadType']);
                $this->client->request('GET', '/api/me/dashboard');
                self::assertResponseIsSuccessful();
                self::assertSame(0, $this->payload()['data']['counts']['active']);
            }
            $this->client->loginUser($provider);
            $this->write('POST', $url, ['action' => 'submit']);
            self::assertResponseStatusCodeSame(409);
        }
    }

    public function testCancelledCampaignDiscussionRequiresConsentAndANewHireForCreatorAndAgency(): void
    {
        $brand = $this->user('reopen-brand', 'ROLE_COMPANY');
        $brand->setCompany(new Company('reopen-brand', 'Reopen brand', 'Travel'));
        $stranger = $this->user('reopen-stranger', 'ROLE_CREATOR');
        $stranger->setCreator(new Creator('reopen-stranger', 'Stranger', 'Travel', 'Sarajevo', 'Complete creator biography.', [], []));
        $this->em->flush();
        foreach (['creator', 'agency'] as $type) {
            $brand = $this->em->find(User::class, $brand->getId());
            $provider = $this->user('reopen-'.$type, $type === 'creator' ? 'ROLE_CREATOR' : 'ROLE_COMPANY');
            if ($type === 'creator') $provider->setCreator(new Creator('reopen-creator', 'Reopen creator', 'Travel', 'Sarajevo', 'Complete creator biography.', [], []));
            else {
                $company = new Company('reopen-agency', 'Reopen agency', 'Travel');
                $company->setCompanyType('agency'); $provider->setCompany($company);
                $this->em->flush(); AgencyProfiles::ensure($provider, $this->em);
            }
            $campaign = new Campaign('reopen-'.$type, 'Reopen campaign', 'Discuss work again', '<p>New work discussion.</p>', 'Travel', ['Instagram'], ['Video'], 100, 800, 'Sarajevo', 3, new DateTimeImmutable('+20 days'), new DateTimeImmutable(), $brand->getCompany());
            $application = new Application($campaign, $provider->getCreator(), 'We can deliver the agreed content.');
            $offer = new Offer($application, 300, 'Original hire terms and deadline.', new DateTimeImmutable('+10 days'));
            $offer->respond('accepted'); $offer->cancel('Original engagement cancelled for this regression test.');
            $conversation = new CampaignConversation($campaign, $provider->getCreator(), $brand);
            foreach ([$campaign, $application, $offer, $conversation] as $entity) $this->em->persist($entity);
            $this->em->flush();
            $url = '/api/me/conversations/'.$conversation->getId().'/reopen';
            $messages = '/api/me/conversations/'.$conversation->getId().'/messages';
            $this->client->loginUser($stranger);
            $this->write('POST', $url, ['action' => 'request', 'message' => 'Let us discuss this collaboration again.']);
            self::assertResponseStatusCodeSame(404);
            $this->client->loginUser($provider);
            $this->write('POST', $url, ['action' => 'request', 'message' => 'Let us discuss this collaboration again.']);
            self::assertResponseStatusCodeSame(403);
            $this->client->loginUser($brand);
            $this->client->jsonRequest('POST', $url, ['action' => 'request']);
            self::assertResponseStatusCodeSame(403);
            $this->write('POST', $url, ['action' => 'request', 'message' => 'short']);
            self::assertResponseStatusCodeSame(400);
            $this->write('POST', $url, ['action' => 'request', 'message' => 'Let us discuss this collaboration again.']);
            self::assertResponseIsSuccessful(); self::assertSame('pending', $this->payload()['data']['status']);
            $this->write('POST', $url, ['action' => 'request', 'message' => 'Duplicate request must not send a notification.']);
            self::assertResponseStatusCodeSame(409);
            $this->write('POST', $url, ['action' => 'accept', 'version' => 1]);
            self::assertResponseStatusCodeSame(403);
            $this->client->loginUser($provider);
            $this->write('POST', $messages, ['body' => 'Chat is still closed until I accept.']);
            self::assertResponseStatusCodeSame(409);
            $this->write('POST', $url, ['action' => 'reject', 'version' => 1]);
            self::assertResponseIsSuccessful(); self::assertSame('rejected', $this->payload()['data']['status']);
            $this->write('POST', $messages, ['body' => 'Declining must leave this read only.']);
            self::assertResponseStatusCodeSame(409);
            $this->client->loginUser($brand);
            $this->write('POST', $url, ['action' => 'request', 'message' => 'A second request with revised expectations.']);
            self::assertResponseIsSuccessful(); self::assertSame(2, $this->payload()['data']['version']);
            $this->client->loginUser($provider);
            $this->write('POST', $url, ['action' => 'accept', 'version' => 1]);
            self::assertResponseStatusCodeSame(409);
            $this->write('POST', $url, ['action' => 'accept', 'version' => 2]);
            self::assertResponseIsSuccessful();
            $this->write('POST', $url, ['action' => 'accept', 'version' => 2]);
            self::assertResponseStatusCodeSame(409);
            $this->client->request('GET', $messages);
            self::assertResponseIsSuccessful();
            self::assertFalse($this->payload()['conversation']['readOnly']);
            self::assertNull($this->payload()['conversation']['engagement']['offer']);
            self::assertSame(0, $this->payload()['conversation']['campaign']['hiredCount']);
            $this->write('POST', $messages, ['body' => 'We can now discuss fresh terms together.']);
            self::assertResponseStatusCodeSame(201);
            $this->write('POST', '/api/me/offers/'.$offer->getId().'/respond', ['decision' => 'accept']);
            self::assertResponseStatusCodeSame(409);
            $this->client->loginUser($brand);
            $this->client->request('GET', '/api/me/inbox');
            $thread = array_values(array_filter($this->payload()['data'], fn ($row) => $row['threadType'] === 'campaign' && $row['id'] === $conversation->getId()))[0];
            self::assertFalse($thread['readOnly']); self::assertFalse($thread['cancelled']);
            $this->client->request('GET', $messages);
            self::assertTrue($this->payload()['conversation']['engagement']['canHire']);
            $this->write('POST', '/api/company/conversations/'.$conversation->getId().'/hire', ['amount' => 450, 'dueDate' => (new DateTimeImmutable('+12 days'))->format('Y-m-d'), 'message' => 'Fresh terms after reopening the discussion.']);
            self::assertResponseStatusCodeSame(201);
            $fresh = $this->payload()['data'];
            self::assertNotSame($offer->getId(), $fresh['id']); self::assertSame('pending', $fresh['status']);
            $this->client->loginUser($provider);
            $this->write('POST', '/api/me/offers/'.$fresh['id'].'/respond', ['decision' => 'accept']);
            self::assertResponseIsSuccessful(); self::assertSame('accepted', $this->payload()['data']['status']);
            $old = $this->em->find(Offer::class, $offer->getId()); $this->em->refresh($old);
            self::assertSame('cancelled', $old->getStatus()); self::assertSame(300, $old->getAmount());
            self::assertSame('Original engagement cancelled for this regression test.', $old->getCancellationReason());
            $this->client->loginUser($brand);
            $this->write('POST', '/api/me/offers/'.$fresh['id'].'/cancel', ['reason' => 'Cancel again to check closed campaign guard.']);
            self::assertResponseIsSuccessful();
            $this->write('PUT', '/api/company/campaigns/'.$campaign->getId().'/status', ['status' => 'closed']);
            self::assertResponseIsSuccessful();
            $this->write('POST', $url, ['action' => 'request', 'message' => 'Closed campaign must not reopen discussion.']);
            self::assertResponseStatusCodeSame(409);
            $this->client->loginUser($provider);
            $this->client->request('GET', '/api/me/notifications');
            self::assertResponseIsSuccessful();
            self::assertNotEmpty(array_filter($this->payload()['data'], fn ($row) => $row['type'] === 'discussion_reopening_requested' && $row['conversationId'] === $conversation->getId()));
        }
    }

    private function user(string $name, string $role): User
    {
        $user = new User($name.'@example.test', $role, true);
        $user->setPassword('unused'); $user->setEmailVerified(true); $user->setHideMyAccount(false);
        $user->setPhone('+38761123456'); $user->setCity('Sarajevo'); $user->setCountryCode('BA');
        $this->em->persist($user);
        return $user;
    }

    private function write(string $method, string $url, array $body): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $token = $this->payload()['csrfToken'];
        $this->client->jsonRequest($method, $url, $body, ['HTTP_X_CSRF_TOKEN' => $token]);
    }

    private function payload(): array { return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }
}
