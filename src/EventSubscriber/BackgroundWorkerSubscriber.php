<?php

namespace App\EventSubscriber;

use App\Background\TaskRegistry;
use App\Message\JobMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

final class BackgroundWorkerSubscriber implements EventSubscriberInterface
{
    private string $id = '';
    private string $queues = '[]';
    private int $lastBeat = 0;
    private int $handled = 0;
    private string $mode = 'cli';

    public function __construct(private readonly Connection $connection, private readonly TaskRegistry $tasks)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerStartedEvent::class => 'start', WorkerRunningEvent::class => 'heartbeat', WorkerMessageHandledEvent::class => 'handled', WorkerMessageFailedEvent::class => ['failed', -1024], WorkerStoppedEvent::class => 'stop'];
    }

    public function start(WorkerStartedEvent $event): void
    {
        $this->mode = PHP_SAPI === 'cli' ? 'cli' : 'admin';
        $this->id = $this->mode . ':' . gethostname() . ':' . getmypid();
        $this->queues = json_encode($event->getWorker()->getMetadata()->getTransportNames(), JSON_THROW_ON_ERROR);
        $this->handled = 0;
        $this->lastBeat = 0;
        $this->beat();
    }

    public function heartbeat(WorkerRunningEvent $event): void
    {
        $this->beat();
    }

    public function handled(WorkerMessageHandledEvent $event): void
    {
        ++$this->handled;
        $this->beat();
    }

    public function failed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($message instanceof JobMessage && !$event->willRetry()) {
            $this->connection->transactional(function () use ($message): void {
                $this->connection->update('background_job', ['status' => 'failed', 'finished_at' => gmdate('Y-m-d H:i:s')], ['id' => $message->jobId]);
                $this->tasks->finished($message->jobId, false);
            });
        }
    }

    public function stop(WorkerStoppedEvent $event): void
    {
        if ($this->id !== '') {
            $this->connection->delete('worker_heartbeat', ['id' => $this->id]);
        }
    }

    private function beat(): void
    {
        if ($this->id === '' || time() - $this->lastBeat < 30) {
            return;
        }
        $this->lastBeat = time();
        $this->connection->executeStatement('INSERT INTO worker_heartbeat (id, mode, queues, last_seen_at, memory_bytes, handled) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (id) DO UPDATE SET last_seen_at = EXCLUDED.last_seen_at, memory_bytes = EXCLUDED.memory_bytes, handled = EXCLUDED.handled, queues = EXCLUDED.queues', [$this->id, $this->mode, $this->queues, gmdate('Y-m-d H:i:s'), memory_get_usage(true), $this->handled]);
    }
}
