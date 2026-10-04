<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\UserPushSubscription;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class PushSubscriptionController
{
    public function __construct(private readonly string $webPushPublicKey)
    {
    }

    #[Route('/api/me/push/config', name: 'api_my_push_config', methods: ['GET'])]
    public function config(): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'enabled' => '' !== $this->webPushPublicKey,
                'publicKey' => $this->webPushPublicKey,
            ],
        ]);
    }

    #[Route('/api/me/push-subscriptions', name: 'api_my_push_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if (null === $locale) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $data = JsonPayload::fromRequest($request);
        $endpoint = is_array($data) && is_string($data['endpoint'] ?? null) ? trim($data['endpoint']) : '';
        $keys = is_array($data) && is_array($data['keys'] ?? null) ? $data['keys'] : [];
        $publicKey = is_string($keys['p256dh'] ?? null) ? $keys['p256dh'] : '';
        $authToken = is_string($keys['auth'] ?? null) ? $keys['auth'] : '';
        if (null === $data
            || !$this->isAllowedEndpoint($endpoint)
            || !preg_match('/^[A-Za-z0-9_-]{20,200}$/', $publicKey)
            || !preg_match('/^[A-Za-z0-9_-]{8,200}$/', $authToken)
        ) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $subscription = $entityManager->getRepository(UserPushSubscription::class)->findOneBy([
            'endpointHash' => hash('sha256', $endpoint),
        ]);
        if ($subscription instanceof UserPushSubscription) {
            $subscription->update($user, $endpoint, $publicKey, $authToken, $locale);
        } else {
            $entityManager->persist(new UserPushSubscription($user, $endpoint, $publicKey, $authToken, $locale));
        }
        $entityManager->flush();

        return new JsonResponse(['data' => ['subscribed' => true]], 201);
    }

    #[Route('/api/me/push-subscriptions', name: 'api_my_push_unsubscribe', methods: ['DELETE'])]
    public function unsubscribe(
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if (null === $locale) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $data = JsonPayload::fromRequest($request);
        $endpoint = is_array($data) && is_string($data['endpoint'] ?? null) ? trim($data['endpoint']) : '';
        if (null === $data || !$this->isAllowedEndpoint($endpoint)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $subscription = $entityManager->getRepository(UserPushSubscription::class)->findOneBy([
            'user' => $user,
            'endpointHash' => hash('sha256', $endpoint),
        ]);
        if ($subscription instanceof UserPushSubscription) {
            $entityManager->remove($subscription);
            $entityManager->flush();
        }

        return new JsonResponse(['data' => ['subscribed' => false]]);
    }

    private function isAllowedEndpoint(string $endpoint): bool
    {
        if (mb_strlen($endpoint) > 4096 || !str_starts_with($endpoint, 'https://')) {
            return false;
        }
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        return 'fcm.googleapis.com' === $host
            || str_ends_with($host, '.push.services.googleapis.com')
            || 'push.services.mozilla.com' === $host
            || str_ends_with($host, '.push.services.mozilla.com')
            || 'web.push.apple.com' === $host
            || 'wns.windows.com' === $host;
    }
}
