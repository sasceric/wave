<?php

namespace App\Tests\Controller;

use App\Entity\Application;
use App\Entity\Campaign;
use App\Entity\CampaignBookmark;
use App\Entity\CampaignInvitation;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261016150000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CampaignApplicationRangeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->owner = $this->user('range-company', 'ROLE_COMPANY');
        $this->company = new Company('range-company', 'Range company', 'Beauty');
        $this->owner->setCompany($this->company);
        $this->em->persist($this->company);
    }

    public function testPublicCardsDetailsHomepageAndBookmarksOnlyExposeCoarseRanges(): void
    {
        $expected = [];
        foreach ([0 => 'fewerThan5', 4 => 'fewerThan5', 5 => 'upTo10', 10 => 'upTo10', 11 => '10to20', 20 => '10to20', 21 => '20to50', 50 => '20to50', 51 => '50plus', 1000 => '50plus'] as $count => $range) {
            $campaign = $this->campaign('range-'.$count);
            $campaign->setFeatured(true);
            for ($index = 0; $index < $count; ++$index) {
                $application = new Application($campaign, $this->creator('creator-'.$count.'-'.$index), 'A real submitted proposal for this campaign.');
                if ($index % 2 === 1) $application->setStatus('rejected');
                $this->em->persist($application);
            }
            $expected[$campaign->getSlug()] = $range;
        }
        $visitor = $this->user('range-visitor', 'ROLE_CREATOR');
        $visitor->setCreator($this->creator('range-visitor'));
        $this->em->persist(new CampaignBookmark($visitor, $campaign));
        $this->em->flush();
        $this->backfillCounters();

        foreach (['/api/campaigns?view=card&limit=90', '/api/campaigns?limit=90', '/api/campaigns?company=range-company&view=card&limit=90', '/api/homepage'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
            $payload = $this->payload();
            $rows = $path === '/api/homepage' ? $payload['data']['campaigns'] : $payload['data'];
            self::assertNotEmpty($rows);
            foreach ($rows as $row) {
                self::assertSame($expected[$row['slug']], $row['applicationRange']);
                self::assertArrayNotHasKey('applicationCount', $row);
                self::assertArrayNotHasKey('applicationsCount', $row);
                self::assertArrayNotHasKey('applications', $row);
            }
        }
        foreach ($expected as $slug => $range) {
            $this->client->request('GET', '/api/campaigns/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSame($range, $this->payload()['data']['applicationRange']);
        }
        $this->client->loginUser($visitor, 'main');
        $this->client->request('GET', '/api/me/bookmarks');
        self::assertResponseIsSuccessful();
        self::assertSame('50plus', $this->payload()['data'][0]['applicationRange']);
        // The owner retains the complete list of genuine applicants.
        $this->client->loginUser($this->owner, 'main');
        $this->client->request('GET', '/api/company/campaigns/range-11/applications');
        self::assertResponseIsSuccessful();
        self::assertCount(11, $this->payload()['data']);
    }

    public function testInvitationsAndOfferApplicationsAreExcludedButDeclinedInvitationsCanBecomeRealProposals(): void
    {
        $campaign = $this->campaign('invitation-range');
        for ($index = 0; $index < 4; ++$index) {
            $this->em->persist(new Application($campaign, $this->creator('applied-'.$index), 'A genuine proposal.'));
        }
        foreach (['pending', 'accepted', 'declined', 'cancelled'] as $status) {
            $creator = $this->creator('invited-'.$status);
            $invitation = new CampaignInvitation($campaign, $creator, $this->owner, 'Invitation, not a proposal.');
            if ($status === 'cancelled') $invitation->cancel();
            elseif ($status !== 'pending') $invitation->respond($status);
            $this->em->persist($invitation);
            if (in_array($status, ['accepted', 'cancelled'], true)) {
                $this->em->persist(new Application($campaign, $creator, $invitation->getMessage()));
            }
            if ($status === 'declined') $declinedCreator = $creator;
        }
        $this->em->flush();
        $this->backfillCounters();
        $this->em->refresh($campaign);
        self::assertSame(4, $campaign->getApplicationCount());

        $applicant = $this->user('declined-applicant', 'ROLE_CREATOR');
        $applicant->setCreator($declinedCreator);
        $this->em->flush();
        $this->client->loginUser($applicant, 'main');
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = $this->payload()['csrfToken'];
        $body = json_encode(['message' => 'A later, independently submitted application to this campaign.'], JSON_THROW_ON_ERROR);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf];
        $this->client->request('POST', '/api/campaigns/invitation-range/applications', [], [], $headers, $body);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('upTo10', $this->payload()['data']['campaign']['applicationRange']);
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(5, (int) $db->fetchOne('SELECT application_count FROM campaign WHERE id = ?', [$campaign->getId()]));

        // Duplicate and invalid submissions must not increment the stored total.
        $this->client->request('POST', '/api/campaigns/invitation-range/applications', [], [], $headers, $body);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(5, (int) $db->fetchOne('SELECT application_count FROM campaign WHERE id = ?', [$campaign->getId()]));
        $this->client->request('GET', '/api/campaigns/invitation-range');
        self::assertResponseIsSuccessful();
        self::assertSame('upTo10', $this->payload()['data']['applicationRange']);
    }

    public function testAtomicIncrementsSurviveStaleEntitiesAndRollBackWithTheApplication(): void
    {
        $campaign = $this->campaign('atomic-counter');
        $this->em->flush();
        $db = $this->em->getConnection();
        $otherEm = new \Doctrine\ORM\EntityManager($db, $this->em->getConfiguration());
        $stale = $otherEm->find(Campaign::class, $campaign->getId());
        $counter = new \App\Service\CampaignApplicationCounter($this->em);
        $otherCounter = new \App\Service\CampaignApplicationCounter($otherEm);
        $db->beginTransaction();
        $counter->increment($campaign);
        self::assertSame(0, $stale->getApplicationCount());
        $otherCounter->increment($stale);
        self::assertSame(2, $stale->getApplicationCount());
        // A later unrelated edit through the stale campaign must retain both increments.
        $campaign->setFeatured(true);
        $this->em->flush();
        self::assertSame(2, (int) $db->fetchOne('SELECT application_count FROM campaign WHERE id = ?', [$campaign->getId()]));
        $db->commit();
        $db->beginTransaction();
        $application = new Application($campaign, $this->creator('rolled-back-applicant'), 'This entire submission will roll back.');
        $this->em->persist($application);
        $this->em->flush();
        $counter->increment($campaign);
        $db->rollBack();
        self::assertSame(2, (int) $db->fetchOne('SELECT application_count FROM campaign WHERE id = ?', [$campaign->getId()]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM campaign_application WHERE campaign_id = ?', [$campaign->getId()]));
    }

    private function backfillCounters(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261016150000.php';
        $db = $this->em->getConnection();
        $down = new Version20261016150000($db, new NullLogger());
        $down->down($db->createSchemaManager()->introspectSchema());
        foreach ($down->getSql() as $query) $db->executeStatement($query->getStatement());
        $migration = new Version20261016150000($db, new NullLogger());
        $migration->up($db->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
        foreach ($this->em->getRepository(Campaign::class)->findAll() as $campaign) $this->em->refresh($campaign);
    }

    private function campaign(string $slug): Campaign
    {
        $campaign = new Campaign($slug, 'Campaign '.$slug, 'Summary', '<p>Campaign description.</p>', 'Beauty', ['Instagram'], ['Video'], 100, 500, 'Sarajevo', 3, new DateTimeImmutable('+30 days'), new DateTimeImmutable(), $this->company);
        $this->em->persist($campaign);

        return $campaign;
    }

    private function creator(string $slug): Creator
    {
        $creator = new Creator($slug, 'Creator '.$slug, 'Beauty', 'Sarajevo', 'Creator biography.', []);
        $this->em->persist($creator);

        return $creator;
    }

    private function user(string $name, string $role): User
    {
        $user = new User($name.'@example.test', $role);
        $user->setPassword('test-only-unused-hash');
        $user->setApproved(true);
        $user->setEmailVerified(true);
        $user->setHideMyAccount(false);
        $this->em->persist($user);

        return $user;
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
