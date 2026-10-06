<?php

namespace App\EventSubscriber;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[WithMonologChannel('background')]
final class OperationalAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['start', 1024], KernelEvents::RESPONSE => ['finish', -1024]];
    }

    public function start(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set('_wave_request_id', bin2hex(random_bytes(16)));
        }
    }

    public function finish(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $event->getResponse()->headers->set('X-Request-Id', (string) $request->attributes->get('_wave_request_id'));
        if (!$request->isMethodSafe() && str_starts_with($request->getPathInfo(), '/api/') && !str_starts_with($request->getPathInfo(), '/api/admin/tools/worker/')) {
            $this->logger->info('api.mutation.completed', [
                'route' => $request->attributes->get('_route'),
                'method' => $request->getMethod(),
                'status' => $event->getResponse()->getStatusCode(),
            ]);
        }
    }
}
