<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Listing\ServicePackages;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ServicePackageController
{
    #[Route('/api/services', name: 'api_services_index', methods: ['GET'])]
    public function index(Request $request, ServicePackages $packages, Security $security): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $limit = $request->query->getInt('limit', 24);
        $offset = $request->query->getInt('offset', 0);
        $type = $request->query->getString('type');
        $sort = $request->query->getString('sort', 'recommended');
        $price = $request->query->getString('price');
        if ($limit < 1 || $limit > 60 || $offset < 0 || $offset > 5000
            || ($type !== '' && !in_array($type, ServicePackages::FILTERS, true))
            || !in_array($sort, ['recommended', 'price', 'newest'], true)
            || !in_array($price, ['', 'to100', '100to300', 'over300'], true)
        ) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        [$minPrice, $maxPrice] = ['' => [null, null], 'to100' => [null, 100], '100to300' => [100, 300], 'over300' => [300, null]][$price];
        $viewer = $security->getUser();
        $result = $packages->find($locale, $viewer instanceof User ? $viewer->getCompany() : null, [
            'type' => $type,
            'q' => mb_substr($request->query->getString('q'), 0, 120),
            'city' => mb_substr($request->query->getString('city'), 0, 80),
            'category' => mb_substr($request->query->getString('category'), 0, 80),
            'minPrice' => $minPrice,
            'maxPrice' => $maxPrice,
            'sort' => $sort,
        ], $limit, $offset);

        return new JsonResponse(['data' => $result['items'], 'meta' => ['total' => $result['total'], 'counts' => $result['counts'], 'limit' => $limit, 'offset' => $offset]]);
    }
}
