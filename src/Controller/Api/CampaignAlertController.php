<?php

namespace App\Controller\Api;

use App\Api\{ApiAccess, JsonPayload};
use App\Entity\CampaignAlertPreference;
use App\Localization\{ApiMessages, LocaleContext};
use App\Marketplace\MarketplaceAreaCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CampaignAlertController
{
    #[Route('/api/me/campaign-alerts', methods: ['GET', 'PUT'])]
    public function settings(Request $request, Security $security, EntityManagerInterface $em, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $user = ApiAccess::requireProvider($security, $locale, false, false);
        if ($user instanceof JsonResponse) return $user;
        $preference = $em->find(CampaignAlertPreference::class, $user->getId()) ?? new CampaignAlertPreference($user);
        if ($request->isMethod('PUT')) {
            if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) return $error;
            $data = JsonPayload::fromRequest($request);
            $categories = $data['categories'] ?? null; $countries = $data['countries'] ?? null; $frequency = $data['frequency'] ?? null;
            $validCategories = array_column(MarketplaceAreaCatalog::options($em, $locale), 'value');
            if (!is_bool($data['enabled'] ?? null) || !is_array($categories) || !array_is_list($categories) || count($categories) > 5 || array_filter($categories, static fn ($v) => !is_string($v) || !in_array($v, $validCategories, true)) !== []
                || !is_array($countries) || !array_is_list($countries) || count($countries) > 10 || array_filter($countries, static fn ($v) => !is_string($v) || !\App\Account\CountryCode::isSupported($v)) !== [] || !in_array($frequency, ['daily', 'weekly'], true)) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
            }
            $preference->configure($data['enabled'], array_values(array_unique($categories)), array_values(array_unique($countries)), $frequency);
            $em->persist($preference); $em->flush();
        }
        return new JsonResponse(['userId' => $user->getId(), 'data' => $preference->resource()], headers: ['Cache-Control' => 'private, no-store']);
    }
}
