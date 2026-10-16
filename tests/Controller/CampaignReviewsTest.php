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

final class CampaignReviewsTest extends WebTestCase
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

    public function testPackagesUseServiceOffersWithTruthfulPricesAndPublicFaqs(): void
    {
        $creator = new Creator('schema-packages', 'Creator', 'Beauty', 'Sarajevo', '<p>Plain <strong>biography</strong>.</p>', [],
            avatarUrl: 'https://images.example.test/creator.webp',
            portfolio: [
                ['type' => 'image', 'url' => 'https://images.example.test/public.webp', 'title' => 'A public work', 'publicPermission' => true],
                ['type' => 'image', 'url' => 'https://images.example.test/private.webp', 'publicPermission' => false],
            ],
            packages: [
                ['id' => 'fixed', 'title' => 'UGC video', 'description' => '<p>One video.</p>', 'price' => 250, 'priceMode' => 'fixed', 'currency' => 'BAM'],
                ['id' => 'from', 'title' => 'Photo set', 'price' => 180, 'priceMode' => 'from', 'currency' => 'EUR'],
                ['id' => 'agreement', 'title' => 'Custom campaign', 'price' => 999, 'priceMode' => 'agreement'],
            ], faqs: [['question' => 'How long does delivery take?', 'answer' => 'Five working days.']]);
        $person = static::getContainer()->get(\App\Service\ProfileStructuredData::class)->creator($creator, 'de');
        self::assertSame('Plain biography.', $person['description']);
        self::assertSame('https://images.example.test/creator.webp', $person['image']);
        $offers = $person['hasOfferCatalog']['itemListElement'];
        self::assertSame('Offer', $offers[0]['@type']);
        self::assertSame('Service', $offers[0]['itemOffered']['@type']);
        self::assertSame(250.0, $offers[0]['price']);
        self::assertSame('BAM', $offers[0]['priceCurrency']);
        self::assertSame('One video.', $offers[0]['itemOffered']['description']);
        self::assertSame(180.0, $offers[1]['priceSpecification']['minPrice']);
        self::assertArrayNotHasKey('price', $offers[1]);
        self::assertArrayNotHasKey('price', $offers[2]);
        self::assertArrayNotHasKey('priceCurrency', $offers[2]);
        self::assertSame('FAQPage', $person['subjectOf'][0]['@type']);
        self::assertSame('Answer', $person['subjectOf'][0]['mainEntity'][0]['acceptedAnswer']['@type']);
        self::assertSame('ImageGallery', $person['subjectOf'][1]['@type']);
        self::assertCount(1, $person['subjectOf'][1]['hasPart']);
        self::assertSame('https://images.example.test/public.webp', $person['subjectOf'][1]['hasPart'][0]['contentUrl']);
        self::assertStringNotContainsString('private.webp', json_encode($person, JSON_THROW_ON_ERROR));
    }

    public function testProfileSchemaMatchesTheApiAndNeverLeaksBlindOrFictionalReviews(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $companyReview = new CampaignReview($this->application, $this->companyUser, $this->creatorUser, 4, 'Excellent work with a clear brief.');
        $em->persist($companyReview);
        $em->flush();
        $factory = static::getContainer()->get(\App\Service\ProfileStructuredData::class);
        $person = $factory->creator($this->creatorUser->getCreator(), 'de');
        self::assertArrayNotHasKey('aggregateRating', $person);
        self::assertArrayNotHasKey('review', $person['makesOffer']['itemOffered']);
        $em->persist(new CampaignReview($this->application, $this->creatorUser, $this->companyUser, 5, 'A thoughtful and professional company.'));
        $em->flush();
        $this->client->request('GET', '/api/creators/review-creator?locale=de');
        self::assertResponseIsSuccessful();
        $person = $this->payload()['data']['structuredData'];
        self::assertSame('Person', $person['@type']);
        self::assertArrayNotHasKey('aggregateRating', $person);
        self::assertArrayNotHasKey('review', $person);
        $service = $person['makesOffer']['itemOffered'];
        self::assertSame('Service', $service['@type']);
        self::assertSame(4.0, (float) $service['aggregateRating']['ratingValue']);
        self::assertSame(1, $service['aggregateRating']['reviewCount']);
        self::assertSame('Organization', $service['review'][0]['author']['@type']);
        self::assertSame($person['@id'], $service['provider']['@id']);
        $crawler = $this->client->request('GET', '/kreatori/review-creator');
        self::assertResponseIsSuccessful();
        $graph = json_decode($crawler->filter('#wave-schema')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($person, $graph[2]['mainEntity']);
        self::assertSame('BreadcrumbList', $graph[3]['@type']);
        $this->client->request('GET', '/api/companies/review-company?locale=de');
        $company = $this->payload()['data']['structuredData'];
        self::assertSame('Organization', $company['@type']);
        self::assertArrayNotHasKey('industry', $company);
        self::assertSame('Beauty', $company['knowsAbout']);
        self::assertSame(5.0, (float) $company['aggregateRating']['ratingValue']);
        self::assertSame('Person', $company['review'][0]['author']['@type']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $creatorOwner = $em->find(User::class, $this->creatorUser->getId());
        $creatorOwner->markPresentationDemo('fictional-schema-test');
        $em->flush();
        $this->client->request('GET', '/api/creators/review-creator');
        $service = $this->payload()['data']['structuredData']['makesOffer']['itemOffered'];
        self::assertArrayNotHasKey('aggregateRating', $service);
        self::assertArrayNotHasKey('review', $service);
        $this->client->request('GET', '/api/companies/review-company');
        $company = $this->payload()['data']['structuredData'];
        self::assertArrayNotHasKey('aggregateRating', $company);
        self::assertArrayNotHasKey('review', $company);
    }

    public function testTwoWayFeedbackIsBlindUntilBothSubmitAndDuplicateIsRejected(): void
    {
        $this->client->loginUser($this->creatorUser);
        $this->requestReview(['rating' => 5, 'comment' => 'Clear brief and excellent communication.']);
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->payload()['data']['received']);
        self::assertSame(5, $this->payload()['data']['own']['rating']);
        $this->client->request('GET', '/api/companies/review-company/reviews');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']);
        self::assertNull($this->payload()['summary']['average']);
        self::assertSame(0, $this->payload()['summary']['reviewCount']);
        $this->client->loginUser($this->companyUser);
        $this->client->request('GET', '/api/me/applications/'.$this->application->getId().'/review');
        self::assertNull($this->payload()['data']['received']);
        $this->requestReview(['rating' => 4, 'comment' => 'Excellent creative work delivered on time.']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(5, $this->payload()['data']['received']['rating']);
        $this->client->request('GET', '/api/creators/review-creator/reviews');
        self::assertSame(4, $this->payload()['data'][0]['rating']);
        self::assertEquals(4.0, $this->payload()['summary']['average']);
        self::assertSame(1, $this->payload()['summary']['completedCampaigns']);
        $this->requestReview(['rating' => 1, 'comment' => 'Duplicate review must never be allowed.']);
        self::assertResponseStatusCodeSame(409);
        $this->client->request('GET', '/api/companies/review-company');
        self::assertSame(1, $this->payload()['data']['rating']['reviewCount']);
        self::assertArrayNotHasKey('financialTotals', $this->payload()['data']);
    }

    public function testFeedbackIsPublishedAfterDeadlineAndLateSubmissionIsRejected(): void
    {
        $this->client->loginUser($this->creatorUser);
        $this->requestReview(['rating' => 3, 'comment' => 'Good collaboration, room for improvement.']);
        self::assertResponseStatusCodeSame(201);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->update('campaign', ['finished_at' => (new DateTimeImmutable('-15 days'))->format('Y-m-d H:i:s')], ['id' => $this->application->getCampaign()->getId()]);
        $this->client->request('GET', '/api/companies/review-company/reviews');
        self::assertSame(1, $this->payload()['summary']['reviewCount']);
        $this->client->loginUser($this->companyUser);
        $this->requestReview(['rating' => 2, 'comment' => 'Too late to submit a review now.']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testOwnershipCsrfApprovalAndAcceptedWorkAreRequired(): void
    {
        $this->client->loginUser($this->stranger);
        $this->requestReview(['rating' => 5, 'comment' => 'Unrelated participant cannot review this.']);
        self::assertResponseStatusCodeSame(404);
        $this->client->loginUser($this->creatorUser);
        $this->client->jsonRequest('POST', '/api/me/applications/'.$this->application->getId().'/review', ['rating' => 5, 'comment' => 'Missing CSRF must be rejected.']);
        self::assertResponseStatusCodeSame(403);
        $this->requestReview(['rating' => 6, 'comment' => 'Invalid score must be rejected.']);
        self::assertResponseStatusCodeSame(400);
        $this->requestReview(['rating' => 4.5, 'comment' => 'Only whole-star ratings can be submitted.']);
        self::assertResponseStatusCodeSame(400);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->update('campaign_offer', ['status' => 'rejected'], ['application_id' => $this->application->getId()]);
        $this->requestReview(['rating' => 5, 'comment' => 'Unhired applicant cannot review this.']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testPrivateTotalsSeparateCurrenciesAndExcludeUnfinishedWork(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $creator = $em->find(Creator::class, $this->application->getCreator()->getId());
        $company = $em->find(Company::class, $this->application->getCampaign()->getCompany()->getId());
        foreach ([['finished', 'EUR', 120], ['open', 'BAM', 999], ['closed', 'BAM', 777]] as [$status, $currency, $amount]) {
            $campaign = $this->campaign($company, $status, $currency);
            $application = new Application($campaign, $creator, 'Another collaboration application');
            $offer = new Offer($application, $amount, 'Agreed amount for collaboration');
            $offer->respond('accepted');
            foreach ([$campaign, $application, $offer] as $entity) { $em->persist($entity); }
        }
        $em->flush();
        foreach ([$this->creatorUser, $this->companyUser] as $user) {
            $this->client->loginUser($user);
            $this->client->request('GET', '/api/me/collaboration-summary');
            self::assertResponseIsSuccessful();
            self::assertSame([['currency' => 'BAM', 'amount' => 350], ['currency' => 'EUR', 'amount' => 120]], $this->payload()['data']['totals']);
            self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        }
        $this->client->loginUser($this->stranger);
        $this->client->request('GET', '/api/me/collaboration-summary');
        self::assertSame([], $this->payload()['data']['totals']);
        $this->client->request('GET', '/api/admin/catalog/creators');
        self::assertResponseStatusCodeSame(403);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->find(User::class, $this->stranger->getId());
        $admin->setAdmin(true);
        $em->flush();
        $this->client->loginUser($admin);
        foreach (['creators', 'companies'] as $kind) {
            $this->client->request('GET', '/api/admin/catalog/'.$kind);
            self::assertResponseIsSuccessful();
            $rows = $this->payload()['data'];
            $row = array_values(array_filter($rows, static fn (array $row): bool => $row['slug'] === ($kind === 'creators' ? 'review-creator' : 'review-company')))[0];
            self::assertSame(2, $row['rating']['completedCampaigns']);
            self::assertCount(2, $row['financialTotals']);
        }
        $this->client->request('GET', '/api/creators?view=card');
        self::assertResponseIsSuccessful();
        foreach ($this->payload()['data'] as $row) { self::assertArrayNotHasKey('financialTotals', $row); }
    }

    public function testPasswordChangeRequiresCurrentPasswordConfirmationAndKeepsSession(): void
    {
        $this->client->loginUser($this->creatorUser);
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = $this->payload()['csrfToken'];
        $otherBrowser = new KernelBrowser(static::$kernel);
        $otherBrowser->loginUser($this->creatorUser);
        $otherBrowser->request('GET', '/api/auth/me');
        self::assertSame(200, $otherBrowser->getResponse()->getStatusCode());
        $url = '/api/me/password';
        $valid = ['currentPassword' => 'old-long-password', 'newPassword' => 'eight888', 'confirmPassword' => 'eight888'];
        $this->client->jsonRequest('PUT', $url, $valid);
        self::assertResponseStatusCodeSame(403);
        foreach ([['currentPassword' => 'wrong-password'], ['confirmPassword' => 'not-the-same-password'], ['newPassword' => 'short', 'confirmPassword' => 'short']] as $invalid) {
            $this->client->jsonRequest('PUT', $url, array_replace($valid, $invalid), ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(400);
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $this->creatorUser->getId());
        $token = new UserActionToken($user, 'reset_password', hash('sha256', 'old-reset-token'), new DateTimeImmutable('+1 hour'));
        $em->persist($token);
        $em->flush();
        $this->client->jsonRequest('PUT', $url, $valid, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('csrfToken', $this->payload());
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $em->getRepository(UserActionToken::class)->count(['purpose' => 'reset_password']));
        $user = $em->find(User::class, $this->creatorUser->getId());
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, 'eight888'));
        self::assertFalse($hasher->isPasswordValid($user, 'old-long-password'));
        $otherBrowser->request('GET', '/api/auth/me');
        self::assertSame(401, $otherBrowser->getResponse()->getStatusCode());
    }

    public function testPublishedAverageAndCursorPaginationDoNotCountHiddenReviews(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $company = $this->application->getCampaign()->getCompany();
        $creator = $this->application->getCreator();
        for ($i = 0; $i < 12; ++$i) {
            $campaign = $this->campaign($company, 'finished');
            $application = new Application($campaign, $creator, 'Completed collaboration for feedback');
            $offer = new Offer($application, 100, 'Accepted collaboration offer');
            $offer->respond('accepted');
            $em->persist($campaign);
            $em->persist($application);
            $em->persist(new CampaignReview($application, $this->companyUser, $this->creatorUser, $i % 2 === 0 ? 5 : 4, 'Feedback for the completed work.'));
            $em->persist(new CampaignReview($application, $this->creatorUser, $this->companyUser, 5, 'Feedback for the completed campaign.'));
        }
        // This review is still blind; it must affect neither average nor public count.
        $em->persist(new CampaignReview($this->application, $this->companyUser, $this->creatorUser, 1, 'Still-hidden feedback must not leak.'));
        $em->flush();
        $this->client->request('GET', '/api/creators/review-creator/reviews');
        self::assertResponseIsSuccessful();
        $first = $this->payload();
        self::assertCount(10, $first['data']);
        self::assertSame(4.5, $first['summary']['average']);
        self::assertSame(12, $first['summary']['reviewCount']);
        self::assertSame(13, $first['summary']['completedCampaigns']);
        self::assertTrue($first['meta']['hasMore']);
        $this->client->request('GET', '/api/creators/review-creator/reviews?before='.$first['meta']['nextCursor']);
        $second = $this->payload();
        self::assertCount(2, $second['data']);
        self::assertFalse($second['meta']['hasMore']);
        self::assertCount(12, array_unique(array_column([...$first['data'], ...$second['data']], 'id')));
    }

    public function testReviewVisibilityAndCompletionCannotBeBypassed(): void
    {
        $this->client->loginUser($this->creatorUser);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $campaign = $this->application->getCampaign();
        $db->update('campaign', ['status' => 'open', 'finished_at' => null], ['id' => $campaign->getId()]);
        $this->requestReview(['rating' => 5, 'comment' => 'Unfinished work cannot be reviewed.']);
        self::assertResponseStatusCodeSame(409);
        $db->update('campaign', ['status' => 'finished', 'finished_at' => gmdate('Y-m-d H:i:s')], ['id' => $campaign->getId()]);
        $db->update('wave_user', ['hide_my_account' => true], ['id' => $this->companyUser->getId()], ['boolean', 'integer']);
        $this->client->request('GET', '/api/companies/review-company/reviews');
        self::assertResponseStatusCodeSame(404);
        $this->client->loginUser($this->companyUser);
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = $this->payload()['csrfToken'];
        $fields = [
            'title' => $campaign->getTitle(), 'summary' => $campaign->getSummary(), 'description' => $campaign->getDescription(),
            'categories' => ['Travel'], 'channels' => ['Instagram'], 'deliverables' => ['Post'],
            'budgetMin' => 100, 'budgetMax' => 500, 'currency' => 'BAM', 'city' => 'Sarajevo', 'countryCode' => 'BA',
            'creatorCount' => 1, 'closesAt' => $campaign->getClosesAt()->format('Y-m-d'),
        ];
        foreach ([['status' => 'open'], ['status' => 'finished', 'currency' => 'EUR']] as $change) {
            $this->client->jsonRequest('PUT', '/api/company/campaigns/'.$campaign->getId(), array_replace($fields, $change), ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(409);
        }
        $this->client->loginUser($this->stranger);
        $this->client->request('GET', '/api/me/applications/'.$this->application->getId().'/review');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnapprovedAndUnverifiedAccountsCannotReviewAndPasswordAttemptsAreLimited(): void
    {
        $this->client->request('GET', '/api/me/collaboration-summary');
        self::assertResponseStatusCodeSame(401);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([['approved' => false], ['approved' => true, 'email_verified' => false]] as $change) {
            $em->getConnection()->update('wave_user', $change, ['id' => $this->creatorUser->getId()], [...array_fill(0, count($change), 'boolean'), 'integer']);
            $em->clear();
            $user = $em->find(User::class, $this->creatorUser->getId());
            $this->client->loginUser($user);
            $this->requestReview(['rating' => 5, 'comment' => 'Approval and verification are required.']);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->loginUser($this->companyUser);
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = $this->payload()['csrfToken'];
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $this->client->jsonRequest('PUT', '/api/me/password', ['currentPassword' => 'wrong-password', 'newPassword' => 'eight888', 'confirmPassword' => 'eight888'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame($attempt < 5 ? 400 : 429);
        }
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testReviewNotificationsTargetOnlyTheOtherParticipantAndDoNotExposeBlindFeedback(): void
    {
        $this->client->loginUser($this->creatorUser);
        $this->requestReview(['rating' => 2, 'comment' => 'Private feedback before the counterpart responds.']);
        self::assertResponseStatusCodeSame(201);
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $notification = $db->fetchAssociative('SELECT * FROM wave_notification');
        self::assertSame($this->companyUser->getId(), (int) $notification['recipient_id']);
        self::assertSame($this->application->getId(), (int) $notification['review_application_id']);
        self::assertSame('review_received', $notification['type']);
        $this->client->loginUser($this->companyUser);
        $this->client->request('GET', '/api/me/notifications');
        self::assertResponseIsSuccessful();
        $body = $this->payload();
        self::assertStringNotContainsString('Private feedback', json_encode($body));
        self::assertSame($this->application->getId(), $body['data'][0]['reviewApplicationId']);
        self::assertSame('company', $body['data'][0]['reviewAccountType']);
        self::assertSame($this->application->getCampaign()->getSlug(), $body['data'][0]['campaign']['slug']);
        $this->requestReview(['rating' => 5, 'comment' => 'Clear and productive collaboration throughout.']);
        self::assertResponseStatusCodeSame(201);
        $this->requestReview(['rating' => 4, 'comment' => 'Duplicate reviews do not generate notifications.']);
        self::assertResponseStatusCodeSame(409);
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM wave_notification'));
        self::assertSame('review_published', $db->fetchOne('SELECT type FROM wave_notification WHERE recipient_id = ?', [$this->creatorUser->getId()]));
        $this->client->loginUser($this->stranger);
        $this->client->request('GET', '/api/me/notifications');
        self::assertSame([], $this->payload()['data']);
    }

    public function testCampaignStatusActionsEnforceOwnershipAndPreserveAcceptedCollaboration(): void
    {
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $campaignId = $this->application->getCampaign()->getId();
        $db->update('campaign', ['status' => 'open', 'finished_at' => null], ['id' => $campaignId]);
        $url = '/api/company/campaigns/'.$campaignId.'/status';
        $this->client->loginUser($this->companyUser);
        $this->client->jsonRequest('PUT', $url, ['status' => 'closed']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = $this->payload()['csrfToken'];
        $this->client->jsonRequest('PUT', $url, ['status' => 'open'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);
        $this->client->jsonRequest('PUT', $url, ['status' => 'closed'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertSame('closed', $this->payload()['data']['status']);
        $this->client->jsonRequest('PUT', $url, ['status' => 'finished'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertSame('finished', $this->payload()['data']['status']);
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $finishedAt = $db->fetchOne('SELECT finished_at FROM campaign WHERE id = ?', [$campaignId]);
        self::assertNotNull($finishedAt);
        self::assertSame('accepted', $db->fetchOne('SELECT status FROM campaign_offer WHERE application_id = ?', [$this->application->getId()]));
        $this->client->jsonRequest('PUT', $url, ['status' => 'finished'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertSame($finishedAt, static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT finished_at FROM campaign WHERE id = ?', [$campaignId]));
        $this->client->jsonRequest('PUT', $url, ['status' => 'closed'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(409);
        $this->client->loginUser($this->creatorUser);
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = $this->payload()['csrfToken'];
        $this->client->jsonRequest('PUT', $url, ['status' => 'finished'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(403);
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
