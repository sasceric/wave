<?php

namespace App\EventSubscriber;

use App\Service\StoredFileCleanup;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class StoredFileCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly StoredFileCleanup $cleanup)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Run after ApiTransactionSubscriber has committed the domain write.
        return [KernelEvents::RESPONSE => ['finish', -600]];
    }

    public function finish(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $event->getRequest()->isMethod('DELETE')
            && str_starts_with($event->getRequest()->getPathInfo(), '/api/')
            && $event->getResponse()->isSuccessful()) {
            $this->cleanup->run();
        }
    }
}
