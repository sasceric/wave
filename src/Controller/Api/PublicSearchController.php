<?php

namespace App\Controller\Api;

use App\Localization\{ApiMessages, LocaleContext};
use App\Search\PublicSearch;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class PublicSearchController
{
    #[Route('/api/search', name: 'api_public_search', methods: ['GET'])]
    public function search(Request $request, PublicSearch $search, #[Target('wave_public_search')] RateLimiterFactoryInterface $limiter): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) return new JsonResponse(['error' => ApiMessages::get('rate_limited', $locale)], 429, ['Cache-Control' => 'private, no-store']);
        try {
            $mode = $request->query->getString('mode', 'page');
            if (!in_array($mode, ['page', 'suggest'], true)) throw new \InvalidArgumentException();
            $data = $search->search(trim($request->query->getString('q')), $locale, $request->query->getString('type', 'all'), $request->query->getInt('page', 1), $mode === 'suggest');
            return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store']);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        } catch (\RuntimeException) {
            return new JsonResponse(['error' => ApiMessages::get('search_unavailable', $locale)], 503, ['Cache-Control' => 'private, no-store', 'Retry-After' => '10']);
        }
    }
}
