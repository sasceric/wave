<?php

namespace App\Background;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

#[\Monolog\Attribute\WithMonologChannel('background')]
final class AdminWorker
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MessageBusInterface $bus,
        private readonly TaskRegistry $tasks,
        private readonly EventDispatcherInterface $events,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'messenger.receiver_locator')] private readonly ServiceProviderInterface $receivers,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%wave.admin_worker.enabled%')] private readonly bool $enabled,
        #[Autowire('%wave.queue.enabled%')] private readonly bool $queueEnabled,
        #[Autowire('%wave.admin_worker.time_limit%')] private readonly int $seconds,
        #[Autowire('%wave.admin_worker.message_limit%')] private readonly int $messages,
        #[Autowire('%wave.admin_worker.memory_limit%')] private readonly int $memory,
        #[Autowire('%wave.admin_worker.idle_interval_ms%')] private readonly int $idle,
    ) {
    }

    public function config(): array
    {
        return ['enabled' => $this->environment === 'dev' && $this->enabled && $this->queueEnabled, 'mode' => $this->environment === 'dev' && $this->enabled && $this->queueEnabled ? 'admin' : 'cli', 'idleIntervalMs' => max(1000, $this->idle)];
    }

    public function consume(): array
    {
        if (!$this->config()['enabled']) {
            throw new \DomainException('Development admin worker is disabled.');
        }
        $locked = (int) $this->connection->fetchOne("SELECT CASE WHEN pg_try_advisory_lock(hashtext('wave.admin_worker')) THEN 1 ELSE 0 END") === 1;
        if (!$locked) {
            return ['handled' => 0, 'busy' => true];
        }
        try {
            $this->tasks->dispatchDue();
            $receivers = [];
            foreach (['realtime', 'mail', 'push', 'background'] as $queue) {
                $receivers[$queue] = $this->receivers->get($queue);
            }
            // Delegate normal events to Symfony's real retry/failure/heartbeat listeners.
            // An idle HTTP request must not enter Doctrine's CLI LISTEN/NOTIFY wait.
            $dispatcher = new class($this->events, max(1, $this->messages), max(16777216, $this->memory)) implements EventDispatcherInterface {
                public int $handled = 0;

                public function __construct(private readonly EventDispatcherInterface $inner, private readonly int $limit, private readonly int $memory)
                {
                }

                public function dispatch(object $event, ?string $eventName = null): object
                {
                    if ($event instanceof WorkerRunningEvent && $event->isWorkerIdle()) {
                        $event->getWorker()->stop();

                        return $event;
                    }
                    $result = $this->inner->dispatch($event, $eventName);
                    if ($event instanceof WorkerMessageHandledEvent || $event instanceof WorkerMessageFailedEvent) {
                        ++$this->handled;
                    }
                    if ($event instanceof WorkerRunningEvent && ($this->handled >= $this->limit || memory_get_usage(true) >= $this->memory)) {
                        $event->getWorker()->stop();
                    }

                    return $result;
                }
            };
            $worker = new Worker($receivers, $this->bus, $dispatcher, $this->logger);
            $worker->run(['time_limit' => min(10, max(1, $this->seconds)), 'sleep' => 0]);

            return ['handled' => $dispatcher->handled, 'busy' => false];
        } finally {
            $this->connection->executeQuery("SELECT pg_advisory_unlock(hashtext('wave.admin_worker'))");
        }
    }
}
