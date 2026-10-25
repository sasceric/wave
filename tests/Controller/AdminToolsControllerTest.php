<?php

namespace App\Tests\Controller;

use App\Background\JobCatalog;
use App\Background\JobDispatcher;
use App\Background\TaskRegistry;
use App\Entity\User;
use App\Service\AdminLogReader;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;

final class AdminToolsControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private string $directory;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $this->directory = sys_get_temp_dir() . '/wave-admin-log-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        static::getContainer()->set(AdminLogReader::class, new AdminLogReader($this->directory, new ArrayAdapter()));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testEveryEndpointRequiresAdminIncludingForModerators(): void
    {
        foreach (['tasks', 'queues', 'failed', 'worker/config', 'log-files', 'logs', 'email-preview/'.str_repeat('a',32)] as $endpoint) {
            $this->client->request('GET', '/api/admin/tools/' . $endpoint . '?locale=en');
            self::assertResponseStatusCodeSame(401);
        }
        $moderator = $this->user(false);
        $moderator->setModerator(true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($moderator, 'main');
        foreach (['tasks', 'queues', 'failed', 'worker/config', 'log-files', 'logs', 'email-preview/'.str_repeat('a',32)] as $endpoint) {
            $this->client->request('GET', '/api/admin/tools/' . $endpoint . '?locale=en');
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testAdminSeesHonestTaskAndQueueStateAndPrivatePaginatedLogs(): void
    {
        $this->client->loginUser($this->user(true), 'main');
        $this->client->request('GET', '/api/admin/tools/tasks?locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame('unregistered', $this->payload()['data'][0]['status']);
        $this->client->request('GET', '/api/admin/tools/queues?locale=en');
        self::assertCount(25, $this->payload()['data']);
        self::assertSame(count(JobCatalog::TYPES) + 5, $this->payload()['meta']['total']);
        self::assertContains('CreditsAnnouncementMessage', array_column($this->payload()['data'], 'id'));
        for ($index = 1; $index <= 35; ++$index) {
            file_put_contents($this->directory . '/prod.log', 'line ' . $index . "\n", FILE_APPEND);
        }
        $this->client->request('GET', '/api/admin/tools/logs?locale=en&pageSize=25');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $first = $this->payload();
        self::assertCount(25, $first['data']);
        self::assertSame('line 35', $first['data'][0]['message']);
        $this->client->request('GET', '/api/admin/tools/logs?locale=en&pageSize=25&cursor=' . $first['meta']['nextCursor']);
        self::assertCount(10, $this->payload()['data']);
        self::assertFalse($this->payload()['meta']['hasMore']);
    }

    public function testNumberedLogsExposeTotalAndAllowDirectLastPageAccess(): void
    {
        $this->client->loginUser($this->user(true), 'main');
        for ($index = 1; $index <= 81; ++$index) {
            file_put_contents($this->directory.'/prod.log', 'line '.$index."\n", FILE_APPEND);
        }
        $this->client->request('GET', '/api/admin/tools/logs?locale=en&page=1&pageSize=25');
        self::assertResponseIsSuccessful();
        $first = $this->payload();
        self::assertSame(81, $first['meta']['total']);
        $this->client->request('GET', '/api/admin/tools/logs?locale=en&page=4&pageSize=25&cursor='.$first['meta']['cursor']);
        self::assertResponseIsSuccessful();
        self::assertCount(6, $this->payload()['data']);
        self::assertSame('line 6', $this->payload()['data'][0]['message']);
        self::assertSame(4, $this->payload()['meta']['page']);
    }

    public function testAdminPreviewUsesCapturedFilesAndKeepsContentOutOfTheDatabase(): void
    {
        $logs = new \App\Mail\MailLog($this->directory, 'test', new \App\Background\PayloadCipher('test-email-key'), new \Psr\Log\NullLogger());
        static::getContainer()->set(\App\Mail\MailLog::class, $logs);
        $email = (new \Symfony\Component\Mime\Email())->from('sender@example.test')->to('recipient@example.test')->subject('Captured subject')->html('<p>Original captured email</p>')->text('Original captured text');
        $envelope = \Symfony\Component\Mailer\Envelope::create($email);
        $prepared = $logs->prepare($email, $envelope);
        $logs->record($prepared, $envelope, 'failed', ['protocol' => 'SMTP', 'responseCode' => 550, 'error' => '550 Mailbox unavailable']);
        $this->client->loginUser($this->user(true), 'main');
        $this->client->request('GET', '/api/admin/tools/log-files?locale=en');
        self::assertResponseIsSuccessful();
        $file = $this->payload()['data']['files'][0]['name'];
        $this->client->request('GET', '/api/admin/tools/logs?locale=en&page=1&file='.urlencode($file));
        self::assertResponseIsSuccessful();
        $mail = $this->payload()['data'][0]['mail'];
        self::assertSame(['recipient@example.test'], $mail['recipients']);
        self::assertSame('Captured subject', $mail['subject']);
        self::assertSame(550, $mail['responseCode']);
        self::assertSame('550 Mailbox unavailable', $mail['error']);
        self::assertStringNotContainsString('Original captured email', $this->client->getResponse()->getContent());
        $this->client->request('GET', '/api/admin/tools/email-preview/'.$mail['emailId'].'?locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame('<p>Original captured email</p>', $this->payload()['data']['html']);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $this->client->request('GET', '/api/admin/tools/email-preview/'.str_repeat('b',32).'?locale=en');
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidPaginationCursorsAndPathsAreRejected(): void
    {
        $this->client->loginUser($this->user(true), 'main');
        foreach (['tasks?page=0', 'tasks?pageSize=10', 'queues?pageSize=10', 'queues?pageSize=1000', 'logs?pageSize=10', 'logs?pageSize=1000', 'logs?file=../.env.local', 'logs?cursor=unknown', 'logs?page=0', 'logs?page=1&cursor=unknown'] as $query) {
            $this->client->request('GET', '/api/admin/tools/' . $query . '&locale=en');
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testTasksSortByNextExecutionAscendingWithUnscheduledTasksLast(): void
    {
        $this->client->loginUser($this->user(true), 'main');
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ([
            ['UnreadMessageReminderTask', 'scheduled', '2030-10-07 12:00:00'],
            ['SitemapGenerateTask', 'scheduled', '2030-10-07 08:00:00'],
            ['QueueMaintenanceTask', 'scheduled', '2030-10-07 08:00:00'],
            ['CachePruneTask', 'inactive', '2030-10-06 08:00:00'],
        ] as [$name, $status, $next]) {
            $db->insert('wave_scheduled_task', ['name' => $name, 'interval_seconds' => 300, 'status' => $status, 'next_run_at' => $next]);
        }
        $this->client->request('GET', '/api/admin/tools/tasks?locale=en');
        self::assertResponseIsSuccessful();
        $rows = $this->payload()['data'];
        self::assertSame(['QueueMaintenanceTask', 'SitemapGenerateTask', 'UnreadMessageReminderTask'], array_slice(array_column($rows, 'name'), 0, 3));
        self::assertSame(array_fill(0, count(TaskRegistry::DEFINITIONS) - 3, null), array_slice(array_column($rows, 'nextExpectedAt'), 3));
    }

    public function testAdminTaskWritesRequireCsrfAndProductionWorkerCannotRun(): void
    {
        $this->client->loginUser($this->user(true), 'main');
        $this->client->request('POST', '/api/admin/tools/tasks/register?locale=en');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $token = $this->payload()['csrfToken'];
        $this->client->request('POST', '/api/admin/tools/tasks/register?locale=en', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(count(TaskRegistry::DEFINITIONS), $this->payload()['data']['registered']);
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $db->executeStatement('UPDATE wave_scheduled_task SET next_run_at = ?', ['2000-01-01 00:00:00']);
        $this->client->request('POST', '/api/admin/tools/tasks/register?locale=en', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(count(TaskRegistry::DEFINITIONS), $this->payload()['data']['registered']);
        foreach ($db->fetchAllAssociative('SELECT next_run_at, interval_seconds FROM wave_scheduled_task') as $row) {
            self::assertEqualsWithDelta(time() + (int) $row['interval_seconds'], strtotime($row['next_run_at'].' UTC'), 5);
        }
        $this->client->request('POST', '/api/admin/tools/tasks/CachePruneTask/run?locale=en', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(202);
        $this->client->request('POST', '/api/admin/tools/tasks/CachePruneTask/run?locale=en', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(409);
        $this->client->request('POST', '/api/admin/tools/worker/consume?locale=en', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testFailedJobsArePaginatedAndCanBeRetriedOrDiscardedThroughTheApi(): void
    {
        $this->client->loginUser($this->user(true), 'main');
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $jobs = static::getContainer()->get(JobDispatcher::class);
        for ($index = 0; $index < 30; ++$index) {
            $job = $jobs->enqueue('SendWebPushMessage', ['body' => 'Private message text']);
            $db->update('background_job', ['status' => 'failed', 'attempts' => 6, 'error_code' => 'TypeError', 'finished_at' => gmdate('Y-m-d H:i:s')], ['id' => $job]);
        }
        $db->executeStatement("UPDATE messenger_messages SET queue_name = 'failed'");
        $this->client->request('GET', '/api/admin/tools/failed?locale=en&pageSize=25');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $first = $this->payload();
        self::assertCount(25, $first['data']);
        self::assertTrue($first['meta']['hasMore']);
        self::assertSame('SendWebPushMessage', $first['data'][0]['type']);
        self::assertSame('TypeError', $first['data'][0]['error_code']);
        self::assertStringNotContainsString('Private message text', $this->client->getResponse()->getContent());
        $this->client->request('GET', '/api/admin/tools/failed?locale=en&page=2&pageSize=25');
        self::assertResponseIsSuccessful();
        self::assertCount(5, $this->payload()['data']);
        self::assertFalse($this->payload()['meta']['hasMore']);
        $row = $first['data'][0];
        $this->client->request('POST', '/api/admin/tools/failed/' . $row['id'] . '/retry?locale=en');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $token = $this->payload()['csrfToken'];
        $this->client->request('POST', '/api/admin/tools/failed/' . $row['id'] . '/retry?locale=en', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(202);
        self::assertSame('queued', $db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$row['job_id']]));
        self::assertSame(29, (int) $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'"));
        self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'push'"));
        $discard = $first['data'][1];
        $this->client->request('POST', '/api/admin/tools/failed/' . $discard['id'] . '/discard?locale=en', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(202);
        self::assertSame('discarded', $db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$discard['job_id']]));
        self::assertSame('', $db->fetchOne('SELECT payload FROM background_job WHERE id = ?', [$discard['job_id']]));
    }

    private function user(bool $admin): User
    {
        $user = new User($admin ? 'admin-tools@example.test' : 'moderator-tools@example.test', 'ROLE_COMPANY');
        $user->setPassword('unused-test-hash');
        $user->setAdmin($admin);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
