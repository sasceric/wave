<?php

namespace App\Tests\Controller;

use App\Account\AgencyProfiles;
use App\Entity\{Application, Campaign, CampaignInvitation, Company, Creator, User};
use App\Search\SearchDocuments;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VoluntaryCampaignTest extends WebTestCase
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

    public function testExplicitTermsValidationCompatibilityAndLocking(): void
    {
        $brand = $this->user('brand', 'ROLE_COMPANY');
        $brand->setCompany(new Company('voluntary-brand', 'Wave', 'Travel'));
        $creator = $this->user('creator', 'ROLE_CREATOR');
        $creator->setCreator(new Creator('voluntary-creator', 'Creator', 'Travel', 'Sarajevo', 'Complete profile biography.', [], []));
        $this->em->flush();
        $this->client->loginUser($brand);
        foreach ([['compensationType' => 'paid'], ['compensationType' => 'other'], ['budgetMax' => 100]] as $invalid) {
            $this->write('POST', '/api/company/campaigns', array_replace($this->fields(), $invalid));
            self::assertResponseStatusCodeSame(400);
        }
        $this->write('POST', '/api/company/campaigns', $this->fields());
        self::assertResponseStatusCodeSame(201);
        $row = $this->payload()['data'];
        self::assertSame('voluntary', $row['compensationType']);
        self::assertSame(0, $row['budgetMin']);
        $url = '/api/company/campaigns/'.$row['id'];
        $oldClient = $this->fields(); unset($oldClient['compensationType']);
        $this->write('PUT', $url, $oldClient);
        self::assertResponseIsSuccessful();
        self::assertSame('voluntary', $this->payload()['data']['compensationType']);
        $campaign = $this->em->find(Campaign::class, $row['id']);
        $documents = self::getContainer()->get(SearchDocuments::class);
        self::assertSame('voluntary', $documents->build('campaign', $row['id'], $documents->labels('bs'), 'bs')[0]['compensationType']);
        $brand = $this->em->find(User::class, $brand->getId());
        $creator = $this->em->find(User::class, $creator->getId());
        $invitation = new CampaignInvitation($campaign, $creator->getCreator(), $brand, 'Please join our community campaign.');
        $this->em->persist($invitation); $this->em->flush();
        $this->write('PUT', $url, array_replace($this->fields(), ['compensationType' => 'paid', 'budgetMin' => 100, 'budgetMax' => 200]));
        self::assertResponseStatusCodeSame(409);
        $paid = $this->fields(); unset($paid['compensationType']);
        $paid['title'] = 'Ordinary paid campaign'; $paid['budgetMin'] = 100; $paid['budgetMax'] = 200;
        $this->write('POST', '/api/company/campaigns', $paid);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('paid', $this->payload()['data']['compensationType']);
    }

    public function testCreatorAndAgencyCompleteVoluntaryWorkWithConsentAndRevisions(): void
    {
        $brand = $this->user('brand', 'ROLE_COMPANY');
        $brand->setCompany(new Company('voluntary-brand', 'Wave', 'Travel'));
        $creator = $this->user('creator', 'ROLE_CREATOR');
        $creator->setCreator(new Creator('voluntary-creator', 'Creator', 'Travel', 'Sarajevo', 'Complete profile biography.', [], []));
        $agency = $this->user('agency', 'ROLE_COMPANY');
        $company = new Company('voluntary-agency', 'Agency', 'Travel'); $company->setCompanyType('agency'); $agency->setCompany($company);
        $this->em->flush(); AgencyProfiles::ensure($agency, $this->em); $this->em->flush();
        $this->client->loginUser($brand);
        $this->write('POST', '/api/company/campaigns', $this->fields());
        self::assertResponseStatusCodeSame(201);
        $campaign = $this->payload()['data'];
        foreach ([$creator, $agency] as $provider) {
            $this->client->loginUser($provider);
            $application = ['message' => 'I would like to make a video presenting Wave.'];
            if ($provider === $agency) {
                $this->write('POST', '/api/campaigns/'.$campaign['slug'].'/applications', $application + ['proposal' => ['amount' => 100]]);
                self::assertResponseStatusCodeSame(400);
                $application['proposal'] = ['scope' => 'One presentation video', 'amount' => null];
            }
            $this->write('POST', '/api/campaigns/'.$campaign['slug'].'/applications', $application);
            self::assertResponseStatusCodeSame(201);
            $applicationId = $this->payload()['data']['id'];
            $this->client->loginUser($brand);
            $this->write('POST', '/api/company/applications/'.$applicationId.'/shortlist', []);
            self::assertResponseIsSuccessful();
            $conversationId = $this->payload()['conversationId'];
            $hire = '/api/company/conversations/'.$conversationId.'/hire';
            $terms = ['amount' => 0, 'dueDate' => (new DateTimeImmutable('+7 days'))->format('Y-m-d'), 'message' => 'Voluntary collaboration without a monetary fee.'];
            $this->write('POST', $hire, array_replace($terms, ['amount' => 300]));
            self::assertResponseStatusCodeSame(400);
            $this->write('POST', $hire, $terms);
            self::assertResponseStatusCodeSame(201);
            $offer = $this->payload()['data'];
            self::assertSame(0, $offer['amount']); self::assertSame('pending', $offer['status']); self::assertSame('voluntary', $offer['compensationType']);
            $this->client->loginUser($provider);
            $this->write('POST', '/api/me/offers/'.$offer['id'].'/respond', ['decision' => 'reject']);
            self::assertResponseIsSuccessful();
            $this->write('POST', '/api/me/conversations/'.$conversationId.'/messages', ['body' => 'Could we agree on a different deadline?']);
            self::assertResponseStatusCodeSame(201);
            $this->client->loginUser($brand); $this->write('POST', $hire, $terms);
            self::assertResponseStatusCodeSame(201); $offer = $this->payload()['data'];
            $this->client->loginUser($provider);
            $this->write('POST', '/api/me/offers/'.$offer['id'].'/respond', ['decision' => 'accept']);
            self::assertResponseIsSuccessful();
            $work = '/api/me/offers/'.$offer['id'].'/work';
            $this->write('POST', $work, ['action' => 'submit']); self::assertResponseIsSuccessful();
            $this->client->loginUser($brand);
            $this->write('POST', $work, ['action' => 'changes', 'reason' => '']); self::assertResponseStatusCodeSame(400);
            $this->write('POST', $work, ['action' => 'changes', 'reason' => 'Please include the application and chat screens.']); self::assertResponseIsSuccessful();
            $this->client->loginUser($provider);
            $this->write('POST', $work, ['action' => 'submit']); self::assertResponseIsSuccessful();
            $this->client->loginUser($brand);
            $this->write('POST', $work, ['action' => 'complete']); self::assertResponseIsSuccessful();
            self::assertSame('completed', $this->payload()['data']['workStatus']);
            $this->write('POST', '/api/me/applications/'.$applicationId.'/review', ['rating' => 5, 'comment' => 'Thank you for helping build the creator community.']); self::assertResponseStatusCodeSame(201);
            $this->client->request('GET', '/api/me/conversations/'.$conversationId.'/messages');
            $events = array_filter($this->payload()['data'], fn ($message) => isset($message['event']['compensationType']));
            self::assertNotEmpty($events);
            foreach ($events as $message) {
                self::assertSame('voluntary', $message['event']['compensationType']);
                self::assertStringNotContainsString('0 KM', $message['body']);
            }
        }
        $this->write('PUT', '/api/company/campaigns/'.$campaign['id'], array_replace($this->fields(), ['compensationType' => 'paid', 'budgetMin' => 100, 'budgetMax' => 200]));
        self::assertResponseStatusCodeSame(409);
        $this->write('PUT', '/api/company/campaigns/'.$campaign['id'].'/status', ['status' => 'finished']);
        self::assertResponseIsSuccessful(); self::assertSame(2, $this->payload()['data']['hiredCount']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT SUM(amount) FROM campaign_offer'));
    }

    public function testPaidCampaignStillRejectsZeroHire(): void
    {
        $brand = $this->user('brand', 'ROLE_COMPANY'); $brand->setCompany(new Company('paid-brand', 'Brand', 'Travel'));
        $creator = $this->user('creator', 'ROLE_CREATOR'); $creator->setCreator(new Creator('paid-creator', 'Creator', 'Travel', 'Sarajevo', 'Complete biography.', [], []));
        $campaign = new Campaign('paid', 'Paid campaign', 'Ordinary paid collaboration', 'Make a video about our application.', 'Travel', ['Instagram'], ['Video'], 100, 200, 'Sarajevo', 2, new DateTimeImmutable('+10 days'), new DateTimeImmutable(), $brand->getCompany());
        $application = new Application($campaign, $creator->getCreator(), 'I would like to make a video.'); $application->setStatus('shortlisted');
        $this->em->persist($campaign); $this->em->persist($application); $this->em->flush();
        $this->client->loginUser($brand);
        $this->write('POST', '/api/company/applications/'.$application->getId().'/offer', ['amount' => 0, 'dueDate' => (new DateTimeImmutable('+7 days'))->format('Y-m-d'), 'message' => 'A paid campaign cannot have a zero offer.']);
        self::assertResponseStatusCodeSame(400);
    }

    private function fields(): array
    {
        return ['title' => 'Present Wave together', 'summary' => 'Help creators and brands discover Wave.', 'description' => '<p>Create a short presentation video about Wave.</p>', 'categories' => ['Travel'], 'channels' => ['Instagram', 'TikTok', 'Facebook'], 'deliverables' => ['One video'], 'budgetMin' => 0, 'budgetMax' => 0, 'compensationType' => 'voluntary', 'currency' => 'BAM', 'city' => 'Sarajevo', 'countryCode' => 'BA', 'creatorCount' => 3, 'closesAt' => (new DateTimeImmutable('+20 days'))->format('Y-m-d'), 'status' => 'open'];
    }

    private function user(string $name, string $role): User
    {
        $user = new User($name.'@example.test', $role, true);
        $user->setPassword('unused'); $user->setEmailVerified(true); $user->setHideMyAccount(false);
        $user->setPhone('+38761123456'); $user->setCity('Sarajevo'); $user->setCountryCode('BA');
        $this->em->persist($user); return $user;
    }

    private function write(string $method, string $url, array $body): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $this->client->jsonRequest($method, $url, $body, ['HTTP_X_CSRF_TOKEN' => $this->payload()['csrfToken']]);
    }

    private function payload(): array { return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }
}
