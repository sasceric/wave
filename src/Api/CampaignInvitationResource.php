<?php

namespace App\Api;

use App\Entity\CampaignInvitation;
use App\Account\AccountDeletion;
use App\Localization\ApiMessages;

final class CampaignInvitationResource
{
    public static function fromEntity(CampaignInvitation $invitation, string $locale, int $hiredCount = 0, ?int $conversationId = null): array
    {
        $campaign = $invitation->getCampaign();

        return [
            'id' => $invitation->getId(),
            'readOnly' => AccountDeletion::sharedHistoryDeleted($invitation->getCreator(), $campaign->getCompany()),
            'status' => $invitation->getStatus(),
            'conversationId' => $conversationId,
            'message' => $invitation->getMessage(),
            'createdAt' => $invitation->getCreatedAt()->format(DATE_ATOM),
            'respondedAt' => $invitation->getRespondedAt()?->format(DATE_ATOM),
            'campaign' => CampaignResource::fromEntity($campaign, $locale, hiredCount: $hiredCount),
            'company' => [
                'name' => $campaign->getCompany()->getOwner()?->isDeleted() ? ApiMessages::get('deleted_account', $locale) : $campaign->getCompany()->getName(),
                'deleted' => $campaign->getCompany()->getOwner()?->isDeleted() ?? false,
            ],
            'creator' => [
                'id' => $invitation->getCreator()->getId(),
                'slug' => $invitation->getCreator()->getSlug(),
                'displayName' => $invitation->getCreator()->getOwner()?->isDeleted() ? ApiMessages::get('deleted_account', $locale) : $invitation->getCreator()->getDisplayName(),
                'deleted' => $invitation->getCreator()->getOwner()?->isDeleted() ?? false,
            ],
        ];
    }
}
