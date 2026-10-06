<?php

namespace App\Tests\Controller;

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
        foreach (['tasks', 'queues', 'log-files', 'logs'] as $endpoint) {
            $this->client->request('GET', '/api/admin/tools/' . $endpoint . '?locale=en');
            self::assertResponseStatusCodeSame(401);
        }
        $moderator = $this->user(false);
        $moderator->setModerator(true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($moderator, 'main');
        foreach (['tasks', 'queues', 'log-files', 'logs'] as $endpoint) {
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
        self::assertCount(21, $this->payload()['data']);
        self::assertSame(21, $this->payload()['meta']['total']);
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

    public function testInvalidPaginationCursorsAndPathsAreRejected(): void
    {
        $this->client->loginUser($this->user(true), 'main');
        foreach (['tasks?page=0', 'tasks?pageSize=10', 'queues?pageSize=10', 'queues?pageSize=1000', 'logs?pageSize=10', 'logs?pageSize=1000', 'logs?file=../.env.local', 'logs?cursor=unknown'] as $query) {
            $this->client->request('GET', '/api/admin/tools/' . $query . '&locale=en');
            self::assertResponseStatusCodeSame(400);
        }
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
        self::assertSame(8, $this->payload()['data']['registered']);
        $this->client->request('POST', '/api/admin/tools/tasks/CachePruneTask/run?locale=en', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(202);
        $this->client->request('POST', '/api/admin/tools/tasks/CachePruneTask/run?locale=en', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(409);
        $this->client->request('POST', '/api/admin/tools/worker/consume?locale=en', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(403);
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
