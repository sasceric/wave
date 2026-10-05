<?php

namespace App\Api;

use App\Entity\CampaignConversation;

final class CampaignConversationResource
{
    public static function fromEntity(CampaignConversation $conversation, int $unreadCount, string $locale): array
    {
        $campaign = $conversation->getCampaign();
        $company = $campaign->getCompany();
        $creator = $conversation->getCreator();

        return [
            'id' => $conversation->getId(),
            'campaign' => CampaignResource::fromEntity($campaign, $locale),
            'creator' => [
                'id' => $creator->getId(),
                'slug' => $creator->getSlug(),
                'displayName' => $creator->getDisplayName(),
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
