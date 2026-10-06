<?php

namespace App\Tests\Controller;

use App\Entity\Campaign;
use App\Entity\CampaignConversation;
use App\Entity\CampaignMessage;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\CreatorInquiry;
use App\Entity\InquiryMessage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InboxControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $creatorUser;
    private User $companyUser;
    private int $conversationId;
    private int $inquiryId;
    private int $otherConversationId;
    private int $pendingInquiryId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $this->creatorUser = $this->user('creator');
        $this->companyUser = $this->user('company');
        $creator = $this->creatorUser->getCreator();
        $company = $this->companyUser->getCompany();
        $outsider = $this->user('creator', 'outsider');
        for ($index = 0; $index < 33; ++$index) {
            $campaign = new Campaign('inbox-' . $index, 'Inbox campaign ' . $index, 'Summary', 'Full brief', 'Travel', ['Instagram'], ['Post'], 10, 20, 'Bosnia', 1, new \DateTimeImmutable('+1 month'), new \DateTimeImmutable('-1 day'), $company);
            $campaign->setTranslations(['en' => ['title' => 'Translated campaign ' . $index]]);
            $em->persist($campaign);
            $conversation = new CampaignConversation($campaign, $index === 32 ? $outsider->getCreator() : $creator, $this->companyUser);
            $em->persist($conversation);
            $em->persist(new CampaignMessage($conversation, $this->companyUser, $index === 0 ? 'Literal 100% preview' : 'Campaign unread ' . $index));
            $inquiry = new CreatorInquiry($creator, $company, null, 'Package ' . $index, null, null, 'Initial inquiry');
            $inquiry->respond('accepted');
            $em->persist($inquiry);
            $em->persist(new InquiryMessage($inquiry, $this->companyUser, 'Inquiry preview ' . $index));
            $em->flush();
            if ($index === 0) {
                $this->conversationId = $conversation->getId();
                $this->inquiryId = $inquiry->getId();
            }
            if ($index === 32) {
                $this->otherConversationId = $conversation->getId();
            }
        }
        $pending = new CreatorInquiry($creator, $company, null, 'Pending', null, null, 'Not a chat');
        $em->persist($pending);
        $em->flush();
        $this->pendingInquiryId = $pending->getId();
        // Identical timestamps exercise both type and ID tie-breakers across pages.
        $em->getConnection()->executeStatement("UPDATE campaign_conversation SET updated_at = '2026-10-01 12:00:00'");
        $em->getConnection()->executeStatement("UPDATE inquiry_message SET created_at = '2026-10-01 12:00:00'");
        $this->client->loginUser($this->creatorUser);
    }

    public function testMixedInboxHasBoundedStablePagesAndReadOnlyCounts(): void
    {
        $first = $this->get('/api/me/inbox');
        self::assertCount(30, $first['data']);
        self::assertTrue($first['meta']['hasMore']);
        self::assertArrayNotHasKey('description', $first['data'][0]['campaign']);
        $keys = array_column($first['data'], 'threadKey');
        $cursor = $first['meta']['nextCursor'];
        // A newly active thread ahead of the cursor must not shift the older page.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement("UPDATE campaign_conversation SET updated_at = '2026-10-02 12:00:00' WHERE id = ?", [$first['data'][0]['id']]);
        do {
            $page = $this->get('/api/me/inbox?cursor=' . rawurlencode($cursor));
            self::assertLessThanOrEqual(30, count($page['data']));
            $keys = [...$keys, ...array_column($page['data'], 'threadKey')];
            $cursor = $page['meta']['nextCursor'];
        } while ($page['meta']['hasMore']);
        self::assertCount(65, $keys);
        self::assertCount(65, array_unique($keys));
        self::assertNotContains('campaign-' . $this->otherConversationId, $keys);
        self::assertNull($cursor);
        self::assertSame(65, $this->get('/api/me/inbox/unread')['unreadCount']);
    }

    public function testSearchAndUnreadApplyBeyondTheFirstBatchAndRespectLocale(): void
    {
        $page = $this->get('/api/me/inbox?q=Inquiry+preview+32');
        self::assertCount(1, $page['data']);
        self::assertSame('inquiry', $page['data'][0]['threadType']);
        $page = $this->get('/api/me/inbox?q=Translated+campaign+0&locale=en');
        self::assertCount(1, $page['data']);
        self::assertSame('Translated campaign 0', $page['data'][0]['campaign']['title']);
        self::assertCount(1, $this->get('/api/me/inbox?q=100%25')['data']);
        self::assertCount(0, $this->get('/api/me/inbox?q=100_')['data']);
        $this->client->loginUser($this->companyUser);
        self::assertCount(0, $this->get('/api/me/inbox?filter=unread')['data']);
        self::assertSame(0, $this->get('/api/me/inbox/unread')['unreadCount']);
        self::assertSame(30, count($this->get('/api/me/inbox')['data']));
    }

    public function testNotificationTargetsAreAccessibleOutsidePagesAndEnforceOwnership(): void
    {
        $page = $this->get('/api/me/inbox/campaign/' . $this->conversationId);
        self::assertSame('Full brief', $page['data']['campaign']['description']);
        $page = $this->get('/api/me/inbox/inquiry/' . $this->inquiryId);
        self::assertSame('Inquiry preview 0', $page['data']['lastMessage']);
        self::assertSame(1, $page['data']['unreadCount']);
        foreach (['campaign/' . $this->otherConversationId, 'inquiry/' . $this->pendingInquiryId, 'inquiry/999999'] as $target) {
            $this->client->request('GET', '/api/me/inbox/' . $target);
            self::assertResponseStatusCodeSame(404);
        }
        $this->client->loginUser($this->companyUser);
        self::assertSame($this->otherConversationId, $this->get('/api/me/inbox/campaign/' . $this->otherConversationId)['data']['id']);
    }

    public function testBadCursorsAndFiltersAreRejected(): void
    {
        $invalidDate = rtrim(strtr(base64_encode(json_encode(['at' => '2026-02-31 12:00:00', 'type' => 'campaign', 'id' => 1])), '+/', '-_'), '=');
        foreach (['limit=0', 'limit=101', 'cursor=bad', 'cursor=' . $invalidDate, 'filter=invalid', 'q=' . str_repeat('x', 201)] as $query) {
            $this->client->request('GET', '/api/me/inbox?' . $query);
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testAuthenticationApprovalAndVerificationAreRequired(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/me/inbox');
        self::assertResponseStatusCodeSame(401);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $this->creatorUser->getId());
        $user->setEmailVerified(false);
        $em->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', '/api/me/inbox/unread');
        self::assertResponseStatusCodeSame(403);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $this->creatorUser->getId());
        $user->setEmailVerified(true);
        $user->setApproved(false);
        $em->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', '/api/me/inbox/campaign/' . $this->conversationId);
        self::assertResponseStatusCodeSame(403);
    }

    public function testInboxIndexMigrationRunsOnPostgreSql(): void
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach (['idx_conversation_creator_activity', 'idx_conversation_campaign_activity', 'idx_inquiry_message_latest'] as $name) {
            $connection->executeStatement('DROP INDEX ' . $name);
        }
        require_once __DIR__ . '/../../migrations/Version20261006210000.php';
        $migration = new \DoctrineMigrations\Version20261006210000($connection, new \Psr\Log\NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertArrayHasKey('idx_conversation_creator_activity', $connection->createSchemaManager()->listTableIndexes('campaign_conversation'));
        self::assertArrayHasKey('idx_conversation_campaign_activity', $connection->createSchemaManager()->listTableIndexes('campaign_conversation'));
        self::assertArrayHasKey('idx_inquiry_message_latest', $connection->createSchemaManager()->listTableIndexes('inquiry_message'));
    }

    private function get(string $path): array
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function user(string $role, string $suffix = ''): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User($role . $suffix . '@example.test', 'ROLE_' . strtoupper($role));
        $user->setApproved(true);
        $user->setEmailVerified(true);
        $user->setPassword('unused-test-hash');
        $em->persist($user);
        if ($role === 'creator') {
            $profile = new Creator($role . $suffix, 'Creator ' . $suffix, 'Travel', 'Bosnia', 'Bio', [], []);
        } else {
            $profile = new Company($role . $suffix, 'Company ' . $suffix, 'Travel');
        }
        if ($role === 'creator') {
            $user->setCreator($profile);
        } else {
            $user->setCompany($profile);
        }
        $em->persist($profile);
        $em->flush();

        return $user;
    }
}
