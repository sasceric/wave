<?php

namespace App\Controller\Api;

use App\Account\AccountDeletion;
use App\Api\ApiAccess;
use App\Api\CampaignHiredCounts;
use App\Api\OfferResource;
use App\Entity\Offer;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Marketplace\CampaignCancellation;
use App\Safety\BlockPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CampaignCancellationController
{
    #[Route('/api/me/offers/{id}/cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id, Request $request, Security $security, CsrfTokenManagerInterface $csrf, EntityManagerInterface $em, CampaignCancellation $cancellation, BlockPolicy $blocks): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) return $error;
        $user = ApiAccess::requireParticipant($security, $locale, true);
        if ($user instanceof JsonResponse) return $user;
        $offer = $em->find(Offer::class, $id);
        if (!$offer || !in_array($user->getId(), [$offer->getApplication()->getCreator()->getOwner()?->getId(), $offer->getApplication()->getCampaign()->getCompany()->getOwner()?->getId()], true)) return new JsonResponse(['error' => ApiMessages::get('offer_not_found', $locale)], 404);
        $application = $offer->getApplication();
        if (AccountDeletion::sharedHistoryDeleted($application->getCreator(), $application->getCampaign()->getCompany())) return new JsonResponse(['error' => ApiMessages::get('account_deleted_history', $locale)], 409);
        if ($blocks->pair($application->getCreator()->getOwner()?->getId(), $application->getCampaign()->getCompany()->getOwner()?->getId())) return new JsonResponse(['error' => ApiMessages::get('blocked_history', $locale)], 409);
        try { $cancellation->offer($offer, $user); }
        catch (HttpException $error) { return new JsonResponse(['error' => ApiMessages::get($error->getMessage(), $locale)], $error->getStatusCode()); }
        return new JsonResponse(['data' => OfferResource::fromEntity($offer, $locale, CampaignHiredCounts::forCampaign($em, $application->getCampaign()))], headers: ['Cache-Control' => 'private, no-store']);
    }
}
