<?php

namespace App\Tests\Service;

use App\Background\AdminWorker;
use App\Background\FailedJobs;
use App\Background\JobDispatcher;
use App\Background\PayloadCipher;
use App\Background\TaskRegistry;
use App\Entity\User;
use App\Entity\UserPushSubscription;
use App\MessageHandler\JobHandler;
use App\Service\QueueInspector;
use App\Service\UnreadInboxCounter;
use App\Service\WebPushNotificationSender;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class BackgroundPipelineTest extends KernelTestCase
{
    private Connection $db;
    private JobDispatcher $jobs;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->db = $em->getConnection();
        $this->jobs = self::getContainer()->get(JobDispatcher::class);
    }

    public function testQueuePayloadIsProtectedAndDomainRollbackRemovesBothRecords(): void
    {
        $id = $this->jobs->enqueue('SendEmailMessage', ['raw' => 'private-content', 'recipient' => 'private@example.test'], 'unique-delivery');
        self::assertSame($id, $this->jobs->enqueue('SendEmailMessage', ['raw' => 'ignored'], 'unique-delivery'));
        $row = $this->db->fetchAssociative('SELECT * FROM messenger_messages');
        self::assertSame(['jobId' => $id], json_decode($row['body'], true));
        self::assertStringNotContainsString('private-content', $row['body'] . $row['headers']);
        $payload = $this->db->fetchOne('SELECT payload FROM background_job WHERE id = ?', [$id]);
        self::assertStringNotContainsString('private-content', $payload);
        self::assertSame('private-content', self::getContainer()->get(PayloadCipher::class)->decrypt($payload)['raw']);
        $this->db->beginTransaction();
        $rolledBack = $this->jobs->enqueue('CachePruneTask');
        $this->db->rollBack();
        self::assertFalse($this->db->fetchOne('SELECT id FROM background_job WHERE id = ?', [$rolledBack]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    public function testTasksRegisterIdempotentlyAndManualRunsAreQueuedAndCompleted(): void
    {
        $tasks = self::getContainer()->get(TaskRegistry::class);
        self::assertSame(8, $tasks->register());
        $reminder = $this->db->fetchAssociative("SELECT * FROM wave_scheduled_task WHERE name = 'UnreadMessageReminderTask'");
        self::assertSame(3600, (int) $reminder['interval_seconds']);
        self::assertEqualsWithDelta(time() + 3600, strtotime($reminder['next_run_at'].' UTC'), 5);
        self::assertSame(0, $tasks->register());
        self::assertSame($reminder, $this->db->fetchAssociative("SELECT * FROM wave_scheduled_task WHERE name = 'UnreadMessageReminderTask'"));
        $id = $tasks->action('CachePruneTask', 'run');
        self::assertSame('queued', $this->db->fetchOne("SELECT status FROM wave_scheduled_task WHERE name = 'CachePruneTask'"));
        self::assertEqualsWithDelta(time() + 86400, strtotime($this->db->fetchOne("SELECT next_run_at FROM wave_scheduled_task WHERE name = 'CachePruneTask'").' UTC'), 5);
        $this->work('background');
        self::assertSame('completed', $this->db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$id]));
        self::assertSame('', $this->db->fetchOne('SELECT payload FROM background_job WHERE id = ?', [$id]));
        self::assertSame('success', $this->db->fetchOne("SELECT last_outcome FROM wave_scheduled_task WHERE name = 'CachePruneTask'"));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        $tasks->action('CachePruneTask', 'inactive');
        self::assertSame(0, $tasks->register());
        self::assertSame('inactive', $this->db->fetchOne("SELECT status FROM wave_scheduled_task WHERE name = 'CachePruneTask'"));
        $this->db->executeStatement('UPDATE wave_scheduled_task SET next_run_at = ?', ['2000-01-01 00:00:00']);
        self::assertSame(7, $tasks->dispatchDue());
        self::assertSame(0, $tasks->dispatchDue());
    }

    public function testAdminRegistrationResetsAllIntervalsWithoutDuplicatingActiveJobs(): void
    {
        $tasks = self::getContainer()->get(TaskRegistry::class);
        $tasks->register();
        $id = $tasks->action('SitemapGenerateTask', 'run');
        $tasks->started($id);
        $tasks->action('CachePruneTask', 'inactive');
        $this->db->update('wave_scheduled_task', ['status' => 'failed', 'last_outcome' => 'failed'], ['name' => 'ExpiredTokenCleanupTask']);
        $this->db->update('wave_scheduled_task', ['interval_seconds' => 7200], ['name' => 'UnreadMessageReminderTask']);
        $this->db->executeStatement('UPDATE wave_scheduled_task SET next_run_at = ?', ['2000-01-01 00:00:00']);
        $this->db->delete('wave_scheduled_task', ['name' => 'LogCleanupTask']);
        $before = $this->db->fetchAllAssociativeIndexed('SELECT * FROM wave_scheduled_task');

        self::assertSame(8, $tasks->registerAndReschedule());
        $rows = $this->db->fetchAllAssociativeIndexed('SELECT * FROM wave_scheduled_task');
        $origins = [];
        foreach ($rows as $name => $row) {
            $origin = strtotime($row['next_run_at'].' UTC') - (int) $row['interval_seconds'];
            self::assertEqualsWithDelta(time(), $origin, 5);
            $origins[] = $origin;
            if (isset($before[$name])) {
                $old = $before[$name];
                unset($row['next_run_at'], $old['next_run_at']);
                self::assertSame($old, $row);
            }
        }
        self::assertCount(1, array_unique($origins));
        self::assertSame('scheduled', $rows['LogCleanupTask']['status']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        self::assertSame(0, $tasks->dispatchDue());
    }

    public function testPermanentFailureIsVisibleAndTargetedRetryDoesNotExecuteSynchronously(): void
    {
        $id = $this->jobs->enqueue('CachePruneTask');
        $this->db->update('background_job', ['payload' => 'corrupted'], ['id' => $id]);
        $this->work('background');
        self::assertSame('failed', $this->db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$id]));
        $failed = self::getContainer()->get(QueueInspector::class)->failed(1, 25);
        self::assertCount(1, $failed);
        self::assertSame($id, $failed[0]['job_id']);
        $this->db->update('background_job', ['payload' => self::getContainer()->get(PayloadCipher::class)->encrypt([])], ['id' => $id]);
        self::getContainer()->get(FailedJobs::class)->action((int) $failed[0]['id'], 'retry');
        self::assertSame('queued', $this->db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'"));
        $this->work('background');
        self::assertSame('completed', $this->db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$id]));
    }

    public function testDuplicateDeliveryOfCompletedJobIsNoOpAndAdminWorkerIsDisabledInTests(): void
    {
        $id = $this->jobs->enqueue('CachePruneTask');
        $this->work('background');
        self::getContainer()->get(JobHandler::class)(new \App\Message\CachePruneTask($id));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT attempts FROM background_job WHERE id = ?', [$id]));
        self::assertFalse(self::getContainer()->get(AdminWorker::class)->config()['enabled']);
        $this->expectException(\DomainException::class);
        self::getContainer()->get(AdminWorker::class)->consume();
    }

    public function testDevelopmentHttpWorkerStopsWhenIdleAndUsesNormalFailureHandling(): void
    {
        $container = self::getContainer();
        $locator = new \Symfony\Component\DependencyInjection\ServiceLocator(array_combine(['realtime', 'mail', 'push', 'background'], array_map(static fn (string $queue): \Closure => static fn () => $container->get('messenger.transport.' . $queue), ['realtime', 'mail', 'push', 'background'])));
        $worker = new AdminWorker($this->db, $container->get(MessageBusInterface::class), $container->get(TaskRegistry::class), $container->get(EventDispatcherInterface::class), new NullLogger(), $locator, 'dev', true, true, 2, 3, 134217728, 2000);
        $start = microtime(true);
        self::assertSame(['handled' => 0, 'busy' => false], $worker->consume());
        self::assertLessThan(3, microtime(true) - $start);
        $id = $this->jobs->enqueue('CachePruneTask');
        $this->db->update('background_job', ['payload' => 'invalid'], ['id' => $id]);
        self::assertSame(1, $worker->consume()['handled']);
        self::assertSame('failed', $this->db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$id]));
    }

    public function testQueuedMailerCapturesExactContentAndSkipsExpiredSecurityToken(): void
    {
        $inner = $this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $inner->expects(self::never())->method('send');
        $mailer = new \App\Background\QueuedMailer($this->jobs, $inner, true);
        $token = str_repeat('a', 64);
        $email = (new \Symfony\Component\Mime\Email())->from('sender@example.test')->to('recipient@example.test')->subject('Verification')->html('<a href="https://wave.example/verify#' . $token . '">Verify</a>');
        $email->getHeaders()->addTextHeader(\App\Background\QueuedMailer::SECURITY_TOKEN_HEADER, hash('sha256', $token));
        $mailer->send($email);
        $job = $this->db->fetchAssociative('SELECT * FROM background_job');
        $payload = self::getContainer()->get(PayloadCipher::class)->decrypt($job['payload']);
        self::assertSame(hash('sha256', $token), $payload['tokenHash']);
        self::assertStringContainsString('Verification', base64_decode($payload['raw']));
        self::assertStringNotContainsString(\App\Background\QueuedMailer::SECURITY_TOKEN_HEADER, base64_decode($payload['raw']));
        // The token is absent: no attempt to send this obsolete security email.
        $this->work('mail');
        self::assertSame('completed', $this->db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$job['id']]));
    }

    public function testCityAndSecondaryIndustryChangesInvalidateAndRefreshSearchIndexes(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getEventManager()->addEventListener(['onFlush', 'postFlush'], new \App\EventSubscriber\IndexChangesSubscriber($this->jobs, true));
        $owner = new User('index-city@example.test', 'ROLE_CREATOR');
        $owner->setPassword('unused');
        $owner->setCity('Zenica');
        $creator = new \App\Entity\Creator('index-city', 'Creator', 'Travel', 'Old duplicate', '', []);
        $owner->setCreator($creator);
        $company = new \App\Entity\Company('index-industries', 'Company', 'Technology');
        $company->setIndustries(['Technology', 'Fashion']);
        foreach ([$owner, $creator, $company] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $indexer = self::getContainer()->get(\App\Background\DirectoryIndexer::class);
        $indexer->index('creator', [$creator->getId()]);
        $indexer->index('company', [$company->getId()]);
        self::assertStringContainsString('zenica', $this->db->fetchOne("SELECT search_text FROM directory_index WHERE kind = 'creator'"));
        self::assertStringContainsString('fashion', $this->db->fetchOne("SELECT search_text FROM directory_index WHERE kind = 'company'"));
        $owner->setCity('Mostar');
        $company->setIndustries(['Technology', 'Food']);
        $em->flush();
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM directory_index'));
        $indexer->index('creator', [$creator->getId()]);
        $indexer->index('company', [$company->getId()]);
        self::assertStringContainsString('mostar', $this->db->fetchOne("SELECT search_text FROM directory_index WHERE kind = 'creator'"));
        $text = $this->db->fetchOne("SELECT search_text FROM directory_index WHERE kind = 'company'");
        self::assertStringContainsString('food', $text);
        self::assertStringNotContainsString('fashion', $text);
    }

    public function testIndexingAndSitemapGenerateRealArtifactsFromBoundedSourceQueries(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $creator = new \App\Entity\Creator('pipeline-creator', 'Pipeline Creator', 'Travel', 'Sarajevo', 'Test profile', [['platform' => 'Instagram']], ['Travel']);
        $company = new \App\Entity\Company('pipeline-company', 'Pipeline Company', 'Technology');
        $campaign = new \App\Entity\Campaign('pipeline-campaign', 'Pipeline Campaign', 'Summary', 'Description', 'Travel', [], [], 100, 200, 'Sarajevo', 1, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable(), $company);
        foreach ([$creator, $company, $campaign] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $indexer = self::getContainer()->get(\App\Background\DirectoryIndexer::class);
        foreach (['creator' => $creator, 'company' => $company, 'campaign' => $campaign] as $kind => $entity) {
            $indexer->index($kind, [$entity->getId()]);
        }
        self::assertSame('["instagram"]', $this->db->fetchOne("SELECT platform_keys FROM directory_index WHERE kind = 'creator'"));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT available_campaign_count FROM directory_index WHERE kind = 'company'"));
        $directory = sys_get_temp_dir() . '/wave-sitemap-test-' . bin2hex(random_bytes(6));
        $files = new \Symfony\Component\Filesystem\Filesystem();
        $sitemap = new \App\Background\CachedSitemap($this->db, self::getContainer()->get(\App\Localization\LocalizedRouteMap::class), new \App\Service\SiteOrigin('https://wave.example'), $this->jobs, $files, $directory, self::getContainer()->get(\App\Service\RegionalSeoContent::class));
        try {
            $sitemap->generate();
            $xml = file_get_contents($sitemap->index());
            self::assertStringContainsString('<sitemapindex', $xml);
            $parts = glob($directory . '/*-0.xml');
            self::assertCount(1, $parts);
            $part = file_get_contents($parts[0]);
            self::assertStringContainsString('/en/creators/pipeline-creator', $part);
            self::assertStringContainsString('/en/campaigns/pipeline-campaign', $part);
            self::assertNull($sitemap->part('../.env.local'));
        } finally {
            $files->remove($directory);
        }
    }

    public function testDelayedJobsAreVisibleWithoutBeingRunnableAndTransientFailureRetries(): void
    {
        $id = $this->jobs->enqueue('SendEmailMessage', ['raw' => base64_encode('Invalid MIME'), 'sender' => 'bad-address', 'recipients' => []], delayMilliseconds: 60000);
        $stats = array_column(self::getContainer()->get(QueueInspector::class)->queues(), null, 'id');
        self::assertSame(1, $stats['SendEmailMessage']['delayed']);
        $this->db->executeStatement('UPDATE messenger_messages SET available_at = ?', ['2000-01-01 00:00:00']);
        $this->work('mail');
        self::assertSame('retrying', $this->db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$id]));
        self::assertSame('mail', $this->db->fetchOne('SELECT queue_name FROM messenger_messages'));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE available_at > (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')"));
    }

    public function testMailHandlerDeliversTheCapturedMimeOnceThroughTheRealTransport(): void
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        $sent = [];
        $events->addListener(\Symfony\Component\Mailer\Event\SentMessageEvent::class, static function ($event) use (&$sent): void { $sent[] = $event->getMessage()->getOriginalMessage()->toString(); });
        $inner = $this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $inner->expects(self::never())->method('send');
        $mailer = new \App\Background\QueuedMailer($this->jobs, $inner, true);
        // Customer text containing a token-looking fragment is ordinary mail.
        $mailer->send((new \Symfony\Component\Mime\Email())->from('sender@example.test')->to('recipient@example.test')->subject('Pipeline mail')->text('Exact captured content')->html('<a href="https://example.test/#' . str_repeat('a', 64) . '">A customer link</a>'));
        $this->work('mail');
        self::assertCount(1, $sent);
        self::assertStringContainsString('Exact captured content', $sent[0]);
        self::assertSame('completed', $this->db->fetchOne('SELECT status FROM background_job'));
    }

    #[DataProvider('pushResponses')]
    public function testQueuedPushUsesTheInstalledLibraryAndPreservesDeliveryOutcomes(int $httpStatus, string $jobStatus, bool $keepsSubscription): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('push-pipeline@example.test', 'creator');
        $user->setPassword('unused-test-password-hash');
        $deviceKeys = VAPID::createVapidKeys();
        $subscription = new UserPushSubscription($user, 'https://push.example.test/test-subscription', $deviceKeys['publicKey'], rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='), 'en');
        $em->persist($user);
        $em->persist($subscription);
        $em->flush();
        $subscriptionId = $subscription->getId();
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $httpStatus): MockResponse {
            $requests[] = [$method, $url, $options];

            return new MockResponse('', ['http_code' => $httpStatus]);
        });
        $keys = VAPID::createVapidKeys();
        self::getContainer()->set(WebPushNotificationSender::class, new WebPushNotificationSender(
            $em, new NullLogger(), $keys['publicKey'], $keys['privateKey'], 'mailto:test@example.test',
            self::getContainer()->get(UnreadInboxCounter::class), httpClient: $client,
        ));
        $id = $this->jobs->enqueue('SendWebPushMessage', ['subscriptionId' => $subscriptionId, 'userId' => $user->getId(), 'title' => 'Queued test', 'body' => 'Test message', 'url' => '/messages?conversation=1']);
        $this->work('push');

        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0][0]);
        self::assertSame('https://push.example.test/test-subscription', $requests[0][1]);
        self::assertSame(10.0, $requests[0][2]['timeout']);
        self::assertSame(10.0, $requests[0][2]['max_duration']);
        self::assertSame(0, $requests[0][2]['max_redirects']);
        self::assertStringContainsString('content-encoding: aes128gcm', strtolower(implode("\n", $requests[0][2]['headers'])));
        self::assertSame($jobStatus, $this->db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$id]));
        self::assertSame($keepsSubscription ? 1 : 0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_push_subscription WHERE id = ?', [$subscriptionId]));
        if ($jobStatus === 'retrying') {
            self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'push' AND available_at > (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')"));
        } elseif ($jobStatus === 'failed') {
            self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'"));
        }
    }

    public static function pushResponses(): array
    {
        return [
            'accepted' => [201, 'completed', true],
            'expired subscription' => [410, 'completed', false],
            'temporary provider failure' => [503, 'retrying', true],
            'rate limited' => [429, 'retrying', true],
            'permanent rejection' => [403, 'failed', true],
        ];
    }

    private function work(string $queue): void
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        $events->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void { $event->getWorker()->stop(); }, 2048);
        $receiver = self::getContainer()->get('messenger.transport.' . $queue);
        $worker = new Worker([$queue => $receiver], self::getContainer()->get(MessageBusInterface::class), $events);
        $worker->run(['time_limit' => 1, 'sleep' => 0]);
    }
}
