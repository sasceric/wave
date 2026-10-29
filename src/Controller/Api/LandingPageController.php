<?php

namespace App\Controller\Api;

use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\RegionalSeoContent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Populated landing pages (area, UGC, city) below a country, for internal links on public pages. */
final class LandingPageController
{
    #[Route('/api/creators/landing-pages', name: 'api_creators_landing_pages', methods: ['GET'], priority: 10)]
    public function index(Request $request, RegionalSeoContent $content): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $country = strtoupper(trim($request->query->getString('country')));
        if ($country === '' || $content->countrySlug($country) === null) {
            return new JsonResponse(['data' => []]);
        }

        return new JsonResponse(['data' => $content->availableTopics($country)], headers: ['Cache-Control' => 'public, max-age=600']);
    }
}
