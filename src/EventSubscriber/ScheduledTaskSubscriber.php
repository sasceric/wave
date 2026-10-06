<?php

namespace App\EventSubscriber;

use App\Service\ScheduledTaskMonitor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ScheduledTaskSubscriber implements EventSubscriberInterface
{
    private ?string $runId = null;

    public function __construct(private readonly ScheduledTaskMonitor $monitor, private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => 'start', ConsoleEvents::ERROR => 'error', ConsoleEvents::TERMINATE => 'finish'];
    }

    public function start(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName() ?? '';
        if (!$this->monitor->tracks($name)) {
            return;
        }
        $this->runId = null;
        try {
            $this->runId = $this->monitor->start($name);
        } catch (\Throwable $exception) {
            $this->logger->warning('Unable to record scheduled task start.', ['exception' => $exception]);
        }
    }

    public function error(ConsoleErrorEvent $event): void
    {
        $this->complete($event->getCommand()?->getName() ?? '', $event->getExitCode());
    }

    public function finish(ConsoleTerminateEvent $event): void
    {
        $this->complete($event->getCommand()?->getName() ?? '', $event->getExitCode());
    }

    private function complete(string $name, int $exitCode): void
    {
        if ($this->runId === null || !$this->monitor->tracks($name)) {
            return;
        }
        try {
            $this->monitor->finish($name, $this->runId, $exitCode);
        } catch (\Throwable $exception) {
            $this->logger->warning('Unable to record scheduled task completion.', ['exception' => $exception]);
        } finally {
            $this->runId = null;
        }
    }
}
