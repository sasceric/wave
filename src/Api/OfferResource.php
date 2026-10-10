<?php

namespace App\Api;

use App\Account\AccountDeletion;

use App\Entity\Offer;

final class OfferResource
{
    public static function fromEntity(Offer $offer, string $locale, int $hiredCount = 0): array
    {
        return [
            'id' => $offer->getId(),
            'readOnly' => AccountDeletion::sharedHistoryDeleted($offer->getApplication()->getCreator(), $offer->getApplication()->getCampaign()->getCompany()),
            'amount' => $offer->getAmount(),
            'message' => $offer->getMessage(),
            'status' => $offer->getStatus(),
            'createdAt' => $offer->getCreatedAt()->format(DATE_ATOM),
            'respondedAt' => $offer->getRespondedAt()?->format(DATE_ATOM),
            'applicationId' => $offer->getApplication()->getId(),
            'campaign' => CampaignResource::fromEntity($offer->getApplication()->getCampaign(), $locale, hiredCount: $hiredCount),
            'creator' => CreatorResource::fromEntity($offer->getApplication()->getCreator(), $locale),
        ];
    }
}
