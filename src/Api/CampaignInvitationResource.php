<?php

namespace App\Api;

use App\Entity\CampaignInvitation;

final class CampaignInvitationResource
{
    public static function fromEntity(CampaignInvitation $invitation, string $locale, int $hiredCount = 0, ?int $conversationId = null): array
    {
        $campaign = $invitation->getCampaign();

        return [
            'id' => $invitation->getId(),
            'status' => $invitation->getStatus(),
            'conversationId' => $conversationId,
            'message' => $invitation->getMessage(),
            'createdAt' => $invitation->getCreatedAt()->format(DATE_ATOM),
            'respondedAt' => $invitation->getRespondedAt()?->format(DATE_ATOM),
            'campaign' => CampaignResource::fromEntity($campaign, $locale, hiredCount: $hiredCount),
            'company' => [
                'name' => $campaign->getCompany()->getName(),
            ],
            'creator' => [
                'id' => $invitation->getCreator()->getId(),
                'slug' => $invitation->getCreator()->getSlug(),
                'displayName' => $invitation->getCreator()->getDisplayName(),
            ],
        ];
    }
}
