<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Localization\{ApiMessages, LocaleContext, LocalizedRouteMap};
use App\Service\MaintenanceMode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse};
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class MaintenanceSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly MaintenanceMode $mode, private readonly Security $security, private readonly LocalizedRouteMap $routes) {}

    public static function getSubscribedEvents(): array
    {
        // After the firewall (8), before directory throttling (4) and controllers.
        return [KernelEvents::REQUEST => ['check', 5]];
    }

    public function check(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        // Health and crawler instructions remain available. Static assets are served normally.
        if (in_array($path, ['/api/health', '/api/maintenance', '/robots.txt'], true)
            || preg_match('~^/(?:assets|build|images|fonts|_profiler|_wdt)(?:/|$)~', $path)) return;
        $maintenancePath = $this->routes->localizedPath('maintenance', 'bs');
        if ($path === '/maintenance') {
            $event->setResponse(new RedirectResponse($maintenancePath, 302, ['Cache-Control' => 'private, no-store']));
            return;
        }
        $user = $this->security->getUser();
        if ($user instanceof User && !$user->isDeleted() && $user->hasRole('ROLE_ADMIN')) return;
        // Keep sign-in and recovery available so admins cannot lock themselves out.
        if (in_array($path, ['/api/auth/session', '/api/auth/csrf', '/api/auth/login', '/api/auth/logout', '/api/auth/password-reset-requests', '/api/auth/password-resets'], true)
            || preg_match('~^/api/auth/oauth/(?:providers|(?:google|apple)/(?:start|callback))$~', $path)) return;
        if ($request->isMethodSafe() && in_array($this->routes->resolve($path)['name'] ?? null, ['login', 'forgot-password', 'reset-password'], true)) return;

        $enabled = $this->mode->enabled();
        $request->attributes->set('_wave_maintenance', $enabled);
        if ($path === $maintenancePath) {
            if (!$enabled) $event->setResponse(new RedirectResponse('/', 302, ['Cache-Control' => 'private, no-store']));
            return;
        }
        if (!$enabled) return;
        if ($path === '/api' || str_starts_with($path, '/api/')) {
            $event->setResponse(new JsonResponse([
                'error' => ApiMessages::get('maintenance_unavailable', LocaleContext::fromRequest($request) ?? 'bs'),
                'code' => 'maintenance',
            ], 503, ['Cache-Control' => 'private, no-store', 'Retry-After' => '60']));
        } else {
            // Temporary routing only: never create or modify a UrlRedirect record.
            $event->setResponse(new RedirectResponse($maintenancePath, 302, ['Cache-Control' => 'private, no-store']));
        }
    }
}
