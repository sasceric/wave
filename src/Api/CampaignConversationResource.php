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
            'readOnly' => AccountDeletion::sharedHistoryDeleted($creator, $company),
            'campaign' => CampaignResource::fromEntity($campaign, $locale, hiredCount: $hiredCount),
            'creator' => [
                'id' => $creator->getId(),
                'slug' => $creator->getSlug(),
                'displayName' => $creator->getOwner()?->isDeleted() ? ApiMessages::get('deleted_account', $locale) : $creator->getDisplayName(),
                'deleted' => $creator->getOwner()?->isDeleted() ?? false,
                'avatarUrl' => $creator->getAvatarMedia()?->getUrl() ?? $creator->getAvatarUrl(),
            ],
            'company' => CompanyResource::fromEntity($company, $locale),
            'lastMessage' => '' === $conversation->getLastMessagePreview() ? null : $conversation->getLastMessagePreview(),
            'lastMessageAt' => $conversation->getUpdatedAt()->format(DATE_ATOM),
            'lastMessageSenderId' => '' === $conversation->getLastMessagePreview()
                ? null
                : $conversation->getLastMessageSender()->getId(),
            'unreadCount' => $unreadCount,
        ];
    }
}
