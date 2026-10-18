<?php

namespace App\Api;

use App\Entity\CampaignConversation;
use App\Account\AccountDeletion;
use App\Localization\ApiMessages;

final class CampaignConversationResource
{
    public static function fromEntity(CampaignConversation $conversation, int $unreadCount, string $locale, int $hiredCount = 0): array
    {
        $campaign = $conversation->getCampaign();
        $company = $campaign->getCompany();
        $creator = $conversation->getCreator();

        return [
            'id' => $conversation->getId(),
            'reopening' => $conversation->getReopening(),
            'readOnly' => $campaign->getStatus() === 'cancelled' || AccountDeletion::sharedHistoryDeleted($creator, $company),
            'campaign' => CampaignResource::fromEntity($campaign, $locale, hiredCount: $hiredCount),
            'creator' => CreatorResource::fromEntity($creator, $locale),
            'company' => CompanyResource::fromEntity($company, $locale),
            'lastMessage' => '' === $conversation->getLastMessagePreview() ? null : \App\Localization\DisplayDate::text($conversation->getLastMessagePreview()),
            'lastMessageAt' => $conversation->getUpdatedAt()->format(DATE_ATOM),
            'lastMessageSenderId' => '' === $conversation->getLastMessagePreview()
                ? null
                : $conversation->getLastMessageSender()->getId(),
            'unreadCount' => $unreadCount,
        ];
    }
}
