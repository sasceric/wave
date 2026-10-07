<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SearchHistoryController
{
    #[Route('/api/search/history', methods: ['POST'])]
    public function record(
        Request $request,
        CsrfTokenManagerInterface $csrf,
        #[Target('wave_search_history')] RateLimiterFactoryInterface $limiter,
        #[Autowire(service: 'monolog.logger.search')] LoggerInterface $logger,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return $this->response(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) {
            return $error;
        }
        if (strlen($request->getContent()) > 2048) {
            return $this->response(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $data = JsonPayload::fromRequest($request);
        $query = is_string($data['query'] ?? null) ? trim($data['query']) : '';
        $type = $data['type'] ?? null;
        $source = $data['source'] ?? null;
        if ($query === '' || mb_strlen($query) > 200 || preg_match('/[\x00-\x1F\x7F]/u', $query)
            || !in_array($type, ['creators', 'companies', 'campaigns'], true)
            || !in_array($source, ['submit', 'all'], true)) {
            return $this->response(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->response(['error' => ApiMessages::get('rate_limited', $locale)], 429);
        }
        // Only explicit searches are recorded; no account, IP, or suggestion keystrokes.
        $logger->info(sprintf('Search %s: %s', $type, $query), [
            'query' => $query, 'type' => $type, 'locale' => $locale, 'source' => $source,
        ]);

        return $this->response(['recorded' => true]);
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
