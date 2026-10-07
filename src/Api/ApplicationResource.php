<?php

namespace App\Api;

use App\Entity\Application;

final class ApplicationResource
{
    public static function fromEntity(Application $application, string $locale, ?int $conversationId = null, int $hiredCount = 0): array
    {
        $offer = $application->getOffer();

        return [
            'id' => $application->getId(),
            'message' => $application->getMessage(),
            'status' => $application->getStatus(),
            'conversationId' => $conversationId,
            'createdAt' => $application->getCreatedAt()->format(DATE_ATOM),
            'campaign' => CampaignResource::fromEntity($application->getCampaign(), $locale, hiredCount: $hiredCount),
            'creator' => CreatorResource::fromEntity($application->getCreator(), $locale),
            'offer' => $offer === null ? null : OfferResource::fromEntity($offer, $locale, $hiredCount),
        ];
    }
}
