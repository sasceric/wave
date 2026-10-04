<?php

namespace App\Api;

use App\Entity\Offer;

final class OfferResource
{
    public static function fromEntity(Offer $offer, string $locale): array
    {
        return [
            'id' => $offer->getId(),
            'amount' => $offer->getAmount(),
            'message' => $offer->getMessage(),
            'status' => $offer->getStatus(),
            'createdAt' => $offer->getCreatedAt()->format(DATE_ATOM),
            'respondedAt' => $offer->getRespondedAt()?->format(DATE_ATOM),
            'applicationId' => $offer->getApplication()->getId(),
            'campaign' => CampaignResource::fromEntity($offer->getApplication()->getCampaign(), $locale),
            'creator' => CreatorResource::fromEntity($offer->getApplication()->getCreator(), $locale),
        ];
    }
}
