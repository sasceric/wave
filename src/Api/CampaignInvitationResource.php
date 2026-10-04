<?php

namespace App\Api;

use App\Entity\CampaignInvitation;

final class CampaignInvitationResource
{
    public static function fromEntity(CampaignInvitation $invitation, string $locale): array
    {
        $campaign = $invitation->getCampaign();
        $translation = $campaign->getTranslations()[$locale] ?? [];

        return [
            'id' => $invitation->getId(),
            'status' => $invitation->getStatus(),
            'message' => $invitation->getMessage(),
            'createdAt' => $invitation->getCreatedAt()->format(DATE_ATOM),
            'respondedAt' => $invitation->getRespondedAt()?->format(DATE_ATOM),
            'campaign' => [
                'id' => $campaign->getId(),
                'slug' => $campaign->getSlug(),
                'title' => $translation['title'] ?? $campaign->getTitle(),
            ],
            'creator' => [
                'id' => $invitation->getCreator()->getId(),
                'slug' => $invitation->getCreator()->getSlug(),
                'displayName' => $invitation->getCreator()->getDisplayName(),
            ],
        ];
    }
}
