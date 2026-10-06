<?php

/** Local synthetic benchmark. Always creates and drops its own PostgreSQL database. */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Entity\Campaign;
use App\Entity\CampaignConversation;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use App\Kernel;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

final class BenchmarkQueryCounter extends \Psr\Log\AbstractLogger
{
    public static int $count = 0;

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            ++self::$count;
        }
    }
}

if (($_SERVER['APP_ENV'] ?? getenv('APP_ENV')) === 'prod') {
    throw new RuntimeException('Run this benchmark locally, never in production.');
}
$root = dirname(__DIR__);
(new Dotenv())->loadEnv($root . '/.env');
if (($_ENV['APP_ENV'] ?? '') === 'prod') {
    throw new RuntimeException('Run this benchmark locally, never in production.');
}
$options = getopt('', ['size:', 'samples:', 'output:']);
$size = (int) ($options['size'] ?? 10000);
$samples = (int) ($options['samples'] ?? 20);
if ($size < 1000 || $size > 100000 || $samples < 3 || $samples > 100) {
    throw new InvalidArgumentException('Use --size=1000..100000 and --samples=3..100.');
}
$parser = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$params = $parser->parse($_ENV['DATABASE_URL']);
if ($params['driver'] !== 'pdo_pgsql') {
    throw new RuntimeException('This benchmark requires PostgreSQL.');
}
$adminParams = getenv('WAVE_BENCHMARK_ADMIN_DSN') ? $parser->parse(getenv('WAVE_BENCHMARK_ADMIN_DSN')) : $params;
$adminParams['dbname'] = 'postgres';
$admin = DriverManager::getConnection($adminParams);
$name = 'wave_benchmark_' . bin2hex(random_bytes(8));
$physicalName = $name . '_test';
$created = false;
$kernel = null;

function sample(callable $operation, int $samples): array
{
    $operation();
    $times = [];
    for ($i = 0; $i < $samples; ++$i) {
        $start = hrtime(true);
        $operation();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);

    return ['p50_ms' => round($times[(int) ceil(count($times) * .5) - 1], 2), 'p95_ms' => round($times[(int) ceil(count($times) * .95) - 1], 2)];
}

try {
    $admin->executeStatement('CREATE DATABASE ' . $admin->quoteIdentifier($physicalName) . ' OWNER ' . $admin->quoteIdentifier($params['user']));
    $created = true;
    $url = preg_replace('~(/)[^/?]+(?=\?|$)~', '$1' . $name, $_ENV['DATABASE_URL']);
    if ($url === $_ENV['DATABASE_URL'] || !str_contains($url, '/' . $name)) {
        throw new RuntimeException('Unable to select the temporary database.');
    }
    foreach (['DATABASE_URL' => $url, 'APP_ENV' => 'test', 'APP_DEBUG' => '0', 'WAVE_QUEUE_ENABLED' => 'false', 'WAVE_ADMIN_WORKER_ENABLED' => 'false', 'MAILER_DSN' => 'null://null'] as $key => $value) {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
    $kernel = new class('test', false, $name) extends Kernel {
        public function __construct(string $environment, bool $debug, private readonly string $benchmarkName)
        {
            parent::__construct($environment, $debug);
        }

        protected function build(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
        {
            parent::build($container);
            $container->register('benchmark.query_counter', BenchmarkQueryCounter::class);
            $container->register('benchmark.query_middleware', \Doctrine\DBAL\Logging\Middleware::class)
                ->addArgument(new \Symfony\Component\DependencyInjection\Reference('benchmark.query_counter'))
                ->addTag('doctrine.middleware');
        }

        public function getCacheDir(): string
        {
            return $this->getProjectDir() . '/var/cache/' . $this->benchmarkName;
        }

        public function getLogDir(): string
        {
            return $this->getCacheDir() . '/logs';
        }
    };
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $em = $container->get(EntityManagerInterface::class);
    $db = $em->getConnection();
    if ($db->getDatabase() !== $physicalName) {
        throw new RuntimeException('Database isolation verification failed.');
    }
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    $company = new Company('bench-company-1', 'Company 000001', 'Travel');
    $creator = new Creator('bench-creator-1', 'Creator 000001', 'Travel', 'Sarajevo', 'Synthetic profile', []);
    $campaign = new Campaign('bench-campaign-1', 'Campaign 000001', 'Synthetic brief', 'Synthetic description', 'Travel', ['Instagram'], ['1 post'], 100, 200, 'Sarajevo', 1, new DateTimeImmutable('+28 days'), new DateTimeImmutable('today'), $company);
    foreach ([$company, $creator, $campaign] as $entity) {
        $em->persist($entity);
    }
    $em->flush();
    foreach (['company' => 'name', 'creator' => 'display_name', 'campaign' => 'title'] as $table => $label) {
        $row = $db->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = 1');
        unset($row['id']);
        $values = [];
        $bindings = ['size' => $size];
        $types = ['size' => ParameterType::INTEGER];
        foreach ($row as $column => $value) {
            $values[] = match ($column) {
                'slug' => "'bench-" . $table . "-' || n",
                $label => "'" . ucfirst($table) . " ' || lpad(n::text, 6, '0')",
                'featured' => '(n % 7 = 0)',
                'company_id' => 'n',
                'closes_at' => "CURRENT_DATE + (n % 28 + 1) * INTERVAL '1 day'",
                default => ':' . $column,
            };
            if (end($values) === ':' . $column) {
                $bindings[$column] = $value;
                $types[$column] = $value === null ? ParameterType::NULL : (is_bool($value) ? ParameterType::BOOLEAN : ParameterType::STRING);
            }
        }
        $db->executeStatement('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($row)) . ') SELECT ' . implode(', ', $values) . ' FROM generate_series(2, :size) n', $bindings, $types);
        $db->executeStatement("SELECT setval(pg_get_serial_sequence('" . $table . "', 'id'), :size)", ['size' => $size]);
    }
    $viewer = new User('benchmark@example.test', 'ROLE_CREATOR');
    $viewer->setPassword('unused');
    $viewer->setCreator($creator);
    $em->persist($viewer);
    $conversation = new CampaignConversation($campaign, $creator, $viewer);
    $em->persist($conversation);
    $em->flush();
    $db->executeStatement("INSERT INTO campaign_message (conversation_id, sender_id, body, created_at) SELECT :conversation, :sender, 'Synthetic message', CURRENT_TIMESTAMP FROM generate_series(1, :size)", ['conversation' => $conversation->getId(), 'sender' => $viewer->getId(), 'size' => $size], ['conversation' => ParameterType::INTEGER, 'sender' => ParameterType::INTEGER, 'size' => ParameterType::INTEGER]);
    $db->executeStatement('DROP INDEX idx_campaign_directory_order');
    $db->executeStatement('CREATE INDEX idx_campaign_directory_order ON campaign (status, featured DESC, closes_at ASC, id ASC)');
    $db->executeStatement('ANALYZE');
    $em->clear();
    $requestStats = [];
    $request = function (string $path) use ($kernel, $em, &$requestStats): array {
        BenchmarkQueryCounter::$count = 0;
        $em->clear();
        $response = $kernel->handle(Request::create($path));
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Benchmark request failed (' . $response->getStatusCode() . ').');
        }

        $requestStats[$path] = ['sql_queries' => BenchmarkQueryCounter::$count, 'json_bytes' => strlen($response->getContent())];

        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    };
    $report = ['generated_at' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'postgresql' => $db->fetchOne('SHOW server_version'), 'size_per_directory' => $size, 'chat_messages' => $size, 'samples' => $samples, 'measurements' => [], 'plans' => []];
    foreach (['creators', 'campaigns', 'companies'] as $kind) {
        $base = '/api/' . $kind . '?view=card&limit=30&pagination=cursor';
        $first = $request($base);
        $report['measurements'][$kind . '_first'] = sample(fn () => $request($base), $samples) + $requestStats[$base];
        $continuation = $base . '&cursor=' . urlencode($first['meta']['nextCursor']);
        $report['measurements'][$kind . '_continuation'] = sample(fn () => $request($continuation), $samples) + $requestStats[$continuation];
    }
    $deepRequest = Request::create('/api/creators?view=card&limit=30&pagination=cursor');
    $deep = (int) floor($size * .8);
    $deepCursor = $container->get(App\Api\DirectoryCursor::class)->encode($deepRequest, 'creator', ['name' => sprintf('Creator %06d', $deep), 'id' => $deep]);
    $report['measurements']['creators_deep_api'] = sample(fn () => $request($deepRequest->getRequestUri() . '&cursor=' . urlencode($deepCursor)), $samples);
    $label = sprintf('Creator %06d', $deep);
    foreach (['indexed' => true, 'without_new_index' => false] as $phase => $indexed) {
        if (!$indexed) {
            $db->executeStatement('DROP INDEX idx_creator_directory_name');
        }
        foreach (['cursor' => 'SELECT id, display_name FROM creator WHERE display_name >= :name AND (display_name > :name OR (display_name = :name AND id > :id)) ORDER BY display_name, id LIMIT 31', 'offset' => 'SELECT id, display_name FROM creator ORDER BY display_name, id LIMIT 31 OFFSET ' . $deep] as $kind => $sql) {
            $parameters = $kind === 'cursor' ? ['name' => $label, 'id' => $deep] : [];
            $report['measurements']['creator_deep_' . $kind . '_' . $phase] = sample(fn () => $db->fetchAllAssociative($sql, $parameters), $samples);
            $report['plans']['creator_deep_' . $kind . '_' . $phase] = json_decode($db->fetchOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' . $sql, $parameters), true, flags: JSON_THROW_ON_ERROR);
        }
    }
    $db->executeStatement('CREATE INDEX idx_creator_directory_name ON creator (display_name, id)');
    $report['measurements']['chat_latest_50'] = sample(fn () => $db->fetchAllAssociative('SELECT id, body FROM campaign_message WHERE conversation_id = :thread ORDER BY id DESC LIMIT 51', ['thread' => $conversation->getId()]), $samples);
    $report['plans']['chat_latest_50'] = json_decode($db->fetchOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT id, body FROM campaign_message WHERE conversation_id = :thread ORDER BY id DESC LIMIT 51', ['thread' => $conversation->getId()]), true, flags: JSON_THROW_ON_ERROR);
    $jobs = $container->get(App\Background\JobDispatcher::class);
    $transport = $container->get('messenger.transport.background');
    $start = hrtime(true);
    for ($i = 0; $i < 10; ++$i) {
        $jobs->enqueue('CreatorIndexingMessage', ['ids' => range($i * 100 + 1, ($i + 1) * 100)]);
    }
    $report['queue']['enqueue_10_jobs_ms'] = round((hrtime(true) - $start) / 1e6, 2);
    $processed = 0;
    $events = $container->get('event_dispatcher');
    $events->addListener(Symfony\Component\Messenger\Event\WorkerMessageHandledEvent::class, function ($event) use (&$processed): void {
        if (++$processed === 10) {
            $event->getWorker()->stop();
        }
    });
    $events->addListener(Symfony\Component\Messenger\Event\WorkerRunningEvent::class, static function ($event): void {
        if ($event->isWorkerIdle()) {
            $event->getWorker()->stop();
        }
    });
    $worker = new Symfony\Component\Messenger\Worker(['background' => $transport], $container->get(Symfony\Component\Messenger\MessageBusInterface::class), $events);
    $start = hrtime(true);
    $worker->run(['sleep' => 0]);
    if ($processed !== 10 || (int) $db->fetchOne("SELECT COUNT(*) FROM background_job WHERE status = 'completed'") !== 10) {
        throw new RuntimeException('Synthetic worker did not complete all jobs.');
    }
    $report['queue']['processed_jobs'] = $processed;
    $report['queue']['indexed_creators'] = $db->fetchOne("SELECT COUNT(*) FROM directory_index WHERE kind = 'creator'");
    $report['queue']['consume_ms'] = round((hrtime(true) - $start) / 1e6, 2);
    $report['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1048576, 2);
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    if (isset($options['output'])) {
        file_put_contents($options['output'], $json);
    }
    echo $json;
} finally {
    if ($kernel !== null) {
        $cache = $kernel->getCacheDir();
        $kernel->shutdown();
        if (is_dir($cache)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($cache);
        }
    }
    if ($created) {
        $admin->executeStatement('DROP DATABASE ' . $admin->quoteIdentifier($physicalName) . ' WITH (FORCE)');
    }
    $admin->close();
}
