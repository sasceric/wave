<?php

namespace App\Api;

use App\Entity\Application;

final class ApplicationResource
{
    public static function fromEntity(Application $application, string $locale): array
    {
        $offer = $application->getOffer();

        return [
            'id' => $application->getId(),
            'message' => $application->getMessage(),
            'status' => $application->getStatus(),
            'createdAt' => $application->getCreatedAt()->format(DATE_ATOM),
            'campaign' => CampaignResource::fromEntity($application->getCampaign(), $locale),
            'creator' => CreatorResource::fromEntity($application->getCreator(), $locale),
            'offer' => $offer === null ? null : OfferResource::fromEntity($offer, $locale),
        ];
    }
}
