<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\ListingAd;
use App\Entity\Media;
use App\Listing\ListingAds;
use App\Localization\LocaleContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ListingAdController
{
    #[Route('/api/listing-ads/{kind}', name: 'api_listing_ads', requirements: ['kind' => 'creators|companies|campaigns'], methods: ['GET'])]
    #[Route('/api/admin/listing-ads/{kind}', name: 'api_admin_listing_ads', requirements: ['kind' => 'creators|companies|campaigns'], methods: ['GET'])]
    public function index(string $kind, Request $request, Security $security, EntityManagerInterface $em): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => \App\Localization\ApiMessages::get('unsupported_language', 'bs')], 400);
        $admin = str_starts_with($request->attributes->getString('_route'), 'api_admin_');
        if ($admin && ($user = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale)) instanceof JsonResponse) return $user;
        $rows = $em->getRepository(ListingAd::class)->findBy($admin ? ['kind' => $kind] : ['kind' => $kind, 'enabled' => true], ['id' => 'ASC'], 100);
        return new JsonResponse(['data' => array_map(ListingAds::resource(...), $rows)], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/admin/listing-ads/{kind}', name: 'api_admin_listing_ad_create', requirements: ['kind' => 'creators|companies|campaigns'], methods: ['POST'])]
    #[Route('/api/admin/listing-ads/{kind}/{id}', name: 'api_admin_listing_ad_update', requirements: ['kind' => 'creators|companies|campaigns', 'id' => '\d+'], methods: ['PUT'])]
    public function save(string $kind, Request $request, Security $security, EntityManagerInterface $em, CsrfTokenManagerInterface $csrf, ?int $id = null): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => \App\Localization\ApiMessages::get('unsupported_language', 'bs')], 400);
        $user = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($user instanceof JsonResponse) return $user;
        if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) return $error;
        $ad = $id === null ? new ListingAd($kind) : $em->getRepository(ListingAd::class)->find($id);
        if (!$ad instanceof ListingAd || $ad->getKind() !== $kind) return new JsonResponse([], 404);
        $input = JsonPayload::fromRequest($request) ?? [];
        try {
            $content = ListingAds::content($input);
            if (!is_bool($input['enabled'] ?? null) || !(is_int($input['imageId'] ?? null) || ($input['imageId'] ?? null) === null)) throw new \InvalidArgumentException('invalid');
            $image = isset($input['imageId']) ? $em->getRepository(Media::class)->find($input['imageId']) : null;
            if (isset($input['imageId']) && (!$image instanceof Media || $image->getFolder()->getSlug() !== 'listing-ad' || ($image->getOwner()->getId() !== $user->getId() && $image->getId() !== $ad->getImage()?->getId()))) throw new \InvalidArgumentException('invalid');
            $ad->setContent($content);
            $ad->setEnabled($input['enabled']);
            $ad->setImage($image);
            $em->wrapInTransaction(function () use ($em, $ad, $kind, $id): void {
                // Serialize placements so two administrators cannot activate overlapping slots.
                $em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(682719, ?)', [array_search($kind, ListingAds::KINDS, true)]);
                $others = $em->getRepository(ListingAd::class)->findBy(['kind' => $kind]);
                if ($id === null && count($others) >= 100) throw new \InvalidArgumentException('limit');
                ListingAds::assertAvailable($ad, $others);
                $em->persist($ad);
            });
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => \App\Localization\ApiMessages::get('listing_ad_'.$e->getMessage(), $locale), 'code' => 'listingAds.'.$e->getMessage()], 422);
        }
        return new JsonResponse(['data' => ListingAds::resource($ad)], $id === null ? 201 : 200);
    }
}
