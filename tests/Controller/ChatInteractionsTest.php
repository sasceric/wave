<?php

namespace App\Tests\Controller;

use App\Account\AgencyProfiles;
use App\Entity\{Application, Campaign, CampaignConversation, CampaignMessage, Company, Creator, CreatorInquiry, InquiryMessage, User};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ChatInteractionsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    public static function threads(): iterable
    {
        foreach (['campaign', 'inquiry'] as $kind) {
            foreach ([false, true] as $agency) {
                yield $kind.($agency ? ' agency' : ' creator') => [$kind, $agency];
            }
        }
    }

    #[DataProvider('threads')]
    public function testRepliesAndHeartsPersistAndStayWithinAuthorizedThreads(string $kind, bool $agency): void
    {
        $brand = $this->user('brand', 'ROLE_COMPANY');
        $brand->setCompany(new Company('chat-brand', 'Chat Brand', 'Travel'));
        $provider = $this->user('provider', $agency ? 'ROLE_COMPANY' : 'ROLE_CREATOR');
        if ($agency) {
            $company = new Company('chat-agency', 'Chat Agency', 'Travel');
            $company->setCompanyType('agency');
            $provider->setCompany($company);
            $this->em->flush();
            AgencyProfiles::ensure($provider, $this->em);
        } else {
            $provider->setCreator(new Creator('chat-creator', 'Chat Creator', 'Travel', 'Sarajevo', 'Creator biography.', [], []));
        }
        $stranger = $this->user('stranger', 'ROLE_CREATOR');
        $stranger->setCreator(new Creator('chat-stranger', 'Stranger', 'Travel', 'Sarajevo', 'Stranger biography.', [], []));
        $this->em->flush();
        if ($kind === 'campaign') {
            $campaign = new Campaign('chat-campaign', 'Chat campaign', 'Chat summary', '<p>Chat brief</p>', 'Travel', ['Instagram'], ['Video'], 100, 500, 'Sarajevo', 3, new \DateTimeImmutable('+20 days'), new \DateTimeImmutable(), $brand->getCompany());
            $application = new Application($campaign, $provider->getCreator(), 'Application message');
            $application->setStatus('shortlisted');
            $thread = new CampaignConversation($campaign, $provider->getCreator(), $brand);
            $other = new CampaignConversation($campaign, $stranger->getCreator(), $brand);
            $message = new CampaignMessage($thread, $brand, 'See https://93.184.216.34/ for details.');
            $foreign = new CampaignMessage($other, $brand, 'Private other conversation');
            foreach ([$campaign, $application, $thread, $other, $message, $foreign] as $entity) $this->em->persist($entity);
            $path = '/api/me/conversations/';
        } else {
            $thread = new CreatorInquiry($provider->getCreator(), $brand->getCompany(), null, null, null, null, 'Inquiry');
            $thread->respond('accepted');
            $other = new CreatorInquiry($stranger->getCreator(), $brand->getCompany(), null, null, null, null, 'Other inquiry');
            $other->respond('accepted');
            $message = new InquiryMessage($thread, $brand, 'See https://93.184.216.34/ for details.');
            $foreign = new InquiryMessage($other, $brand, 'Private other inquiry');
            foreach ([$thread, $other, $message, $foreign] as $entity) $this->em->persist($entity);
            $path = '/api/me/inquiries/';
        }
        $this->em->flush();
        $url = $path.$thread->getId().'/messages';
        $heart = $url.'/'.$message->getId().'/heart';
        self::getContainer()->set(\App\Service\ChatLinkPreview::class, new \App\Service\ChatLinkPreview(
            new \Symfony\Component\HttpClient\MockHttpClient(new \Symfony\Component\HttpClient\Response\MockResponse('<title>Wave preview</title>', ['response_headers' => ['content-type: text/html']])), new \Symfony\Component\Cache\Adapter\ArrayAdapter(),
        ));
        $this->client->loginUser($provider);
        $this->client->request('GET', $url.'/'.$message->getId().'/preview');
        self::assertResponseIsSuccessful();
        self::assertSame('Wave preview', $this->payload()['data']['title']);
        $this->client->jsonRequest('POST', $heart, ['active' => true]);
        self::assertResponseStatusCodeSame(403);
        $this->write($url, ['body' => 'Reply', 'replyToId' => $foreign->getId()]);
        self::assertResponseStatusCodeSame(400);
        $this->write($url, ['body' => 'Reply', 'replyToId' => 'bad']);
        self::assertResponseStatusCodeSame(400);
        $this->write($url, ['body' => 'Agreed, thank you.', 'replyToId' => $message->getId()]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($message->getId(), $this->payload()['data']['replyTo']['id']);
        self::assertSame($message->getBody(), $this->payload()['data']['replyTo']['body']);
        self::assertSame($brand->getId(), $this->payload()['data']['replyTo']['senderId']);
        self::assertSame([], $this->payload()['data']['hearts']);
        $replyId = $this->payload()['data']['id'];
        foreach ([true, true] as $active) {
            $this->write($heart, ['active' => $active]);
            self::assertResponseIsSuccessful();
            self::assertSame([$provider->getId()], $this->payload()['data']['hearts']);
        }
        $this->client->loginUser($brand);
        $this->write($heart, ['active' => true]);
        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing([$brand->getId(), $provider->getId()], $this->payload()['data']['hearts']);
        $this->em->clear();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $data = $this->payload()['data'];
        self::assertCount(2, $data);
        self::assertSame($message->getId(), $data[1]['replyTo']['id']);
        self::assertCount(2, $data[0]['hearts']);
        $this->write($heart, ['active' => false]);
        self::assertResponseIsSuccessful();
        self::assertSame([$provider->getId()], $this->payload()['data']['hearts']);
        $this->write($heart, ['active' => 'heart']);
        self::assertResponseStatusCodeSame(400);
        $this->write($path.$other->getId().'/messages/'.$message->getId().'/heart', ['active' => true]);
        self::assertResponseStatusCodeSame(404);
        $originalUrl = $url.'/'.$message->getId();
        $this->write($originalUrl, ['body' => 'Updated details https://wave.ba/'], 'PATCH');
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->payload()['data']['editedAt']);
        self::assertSame('Updated details https://wave.ba/', $this->payload()['data']['body']);
        $this->client->request('GET', $url);
        self::assertSame('Updated details https://wave.ba/', $this->payload()['data'][1]['replyTo']['body']);
        $this->client->loginUser($provider);
        $this->write($originalUrl, ['body' => 'Not my message'], 'PATCH');
        self::assertResponseStatusCodeSame(403);
        $this->write($originalUrl, [], 'DELETE');
        self::assertResponseStatusCodeSame(403);
        $this->write($url.'/'.$replyId, ['body' => 'Edited latest reply'], 'PATCH');
        self::assertResponseIsSuccessful();
        if ($kind === 'campaign') {
            self::assertSame('Edited latest reply', $this->em->getConnection()->fetchOne('SELECT last_message_preview FROM campaign_conversation WHERE id = ?', [$thread->getId()]));
            self::assertSame($this->em->getConnection()->fetchOne('SELECT created_at FROM campaign_message WHERE id = ?', [$replyId]), $this->em->getConnection()->fetchOne('SELECT updated_at FROM campaign_conversation WHERE id = ?', [$thread->getId()]));
        }
        $this->client->loginUser($brand);
        $table = $kind === 'campaign' ? 'campaign_message' : 'inquiry_message';
        $this->em->getConnection()->update($table, ['created_at' => (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s')], ['id' => $message->getId()]);
        $this->write($originalUrl, ['body' => 'Too late'], 'PATCH');
        self::assertResponseStatusCodeSame(409);
        $this->write($originalUrl, [], 'DELETE');
        self::assertResponseStatusCodeSame(409);
        $this->em->getConnection()->update($table, ['created_at' => (new \DateTimeImmutable('-9 minutes'))->format('Y-m-d H:i:s')], ['id' => $message->getId()]);
        $this->write($originalUrl, [], 'DELETE');
        self::assertResponseIsSuccessful();
        self::assertSame('', $this->payload()['data']['body']);
        self::assertSame([], $this->payload()['data']['hearts']);
        self::assertNotNull($this->payload()['data']['deletedAt']);
        $this->client->request('GET', $url);
        self::assertCount(2, $this->payload()['data']);
        self::assertSame('', $this->payload()['data'][0]['body']);
        self::assertNotNull($this->payload()['data'][1]['replyTo']['deletedAt']);
        $this->write($originalUrl, ['body' => 'Cannot revive'], 'PATCH');
        self::assertResponseStatusCodeSame(409);
        $this->write($heart, ['active' => true]);
        self::assertResponseStatusCodeSame(409);
        $this->write($url, ['body' => 'Reply to deleted', 'replyToId' => $message->getId()]);
        self::assertResponseStatusCodeSame(400);
        $this->client->loginUser($stranger);
        $this->client->request('GET', $url.'/'.$message->getId().'/preview');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $url.'/'.$message->getId().'/preview-image');
        self::assertResponseStatusCodeSame(404);
        $this->write($heart, ['active' => true]);
        self::assertResponseStatusCodeSame(404);
        $this->write($url, ['body' => 'Intruder', 'replyToId' => $message->getId()]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        $this->client->loginUser($provider);
        $this->em->getConnection()->insert('user_block', [
            'id' => str_repeat('a', 32), 'blocker_id' => $brand->getId(), 'blocked_id' => $provider->getId(),
            'created_at' => '2026-10-25 12:00:00', 'cleared_at' => null,
        ]);
        $this->write($heart, ['active' => true]);
        self::assertResponseStatusCodeSame(409);
        $this->write($url, ['body' => 'Blocked reply', 'replyToId' => $message->getId()]);
        self::assertResponseStatusCodeSame(409);
        $this->em->getConnection()->delete('user_block', ['id' => str_repeat('a', 32)]);
        if ($kind === 'campaign') {
            $this->em->getConnection()->update('campaign', ['status' => 'finished'], ['id' => $campaign->getId()]);
        } else {
            $this->em->getConnection()->update('creator_inquiry', ['status' => 'rejected'], ['id' => $thread->getId()]);
        }
        $this->em->clear();
        $this->write($heart, ['active' => true]);
        self::assertResponseStatusCodeSame(409);
    }

    private function user(string $name, string $role): User
    {
        $user = new User($name.'@example.test', $role, true);
        $user->setPassword('unused');
        $user->setEmailVerified(true);
        $this->em->persist($user);

        return $user;
    }

    private function write(string $path, array $data, string $method = 'POST'): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $this->client->jsonRequest($method, $path, $data, ['HTTP_X_CSRF_TOKEN' => $this->payload()['csrfToken']]);
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
