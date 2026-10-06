<?php

namespace App\EventSubscriber;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Queue inserts use the same connection as domain writes and commit together. */
final class ApiTransactionSubscriber implements EventSubscriberInterface
{
    private bool $ownsTransaction = false;

    public function __construct(private readonly Connection $connection, #[Autowire('%wave.queue.enabled%')] private readonly bool $enabled)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['start', 0], KernelEvents::RESPONSE => ['finish', -512], KernelEvents::EXCEPTION => ['rollback', 512]];
    }

    public function start(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->enabled || !$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/') || str_starts_with($request->getPathInfo(), '/api/admin/tools/worker/')) {
            return;
        }
        if ($request->isMethodSafe() && !str_contains($request->getPathInfo(), '/callback')) {
            return;
        }
        $this->connection->beginTransaction();
        $this->ownsTransaction = true;
    }

    public function finish(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->ownsTransaction) {
            return;
        }
        $this->ownsTransaction = false;
        if ($event->getResponse()->getStatusCode() >= 400) {
            $this->connection->rollBack();
        } else {
            $this->connection->commit();
        }
    }

    public function rollback(ExceptionEvent $event): void
    {
        if ($event->isMainRequest() && $this->ownsTransaction) {
            $this->ownsTransaction = false;
            $this->connection->rollBack();
        }
    }
}
