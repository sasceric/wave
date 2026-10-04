<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\RealtimeUpdatePublisher;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Routing\Attribute\Route;

final class RealtimeController
{
    public function __construct(private readonly string $mercurePublicUrl)
    {
    }

    #[Route('/api/me/realtime', name: 'api_my_realtime_authorization', methods: ['GET'])]
    public function authorize(
        Request $request,
        Security $security,
        Authorization $authorization,
        RealtimeUpdatePublisher $realtimeUpdatePublisher,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if (null === $locale) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $topic = $realtimeUpdatePublisher->topicFor($user);
        $authorization->setCookie($request, [
            new Grant([Grant::ACTION_SUBSCRIBE], [$topic]),
        ]);

        return new JsonResponse([
            'data' => [
                'hubUrl' => $this->mercurePublicUrl,
                'topic' => $topic,
            ],
        ]);
    }
}
