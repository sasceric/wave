<?php

namespace App\Api;

use App\Entity\CampaignConversation;

final class CampaignConversationResource
{
    public static function fromEntity(CampaignConversation $conversation, int $unreadCount, string $locale): array
    {
        $campaign = $conversation->getCampaign();
        $company = $campaign->getCompany();
        $campaignTranslation = $campaign->getTranslations()[$locale] ?? [];
        $creator = $conversation->getCreator();

        return [
            'id' => $conversation->getId(),
            'campaign' => [
                'id' => $campaign->getId(),
                'slug' => $campaign->getSlug(),
                'title' => $campaignTranslation['title'] ?? $campaign->getTitle(),
            ],
            'creator' => [
                'id' => $creator->getId(),
                'slug' => $creator->getSlug(),
                'displayName' => $creator->getDisplayName(),
            ],
            'company' => [
                'id' => $company->getId(),
                'slug' => $company->getSlug(),
                'name' => $company->getName(),
            ],
            'lastMessage' => $conversation->getLastMessagePreview(),
            'lastMessageAt' => $conversation->getUpdatedAt()->format(DATE_ATOM),
            'lastMessageSenderId' => $conversation->getLastMessageSender()->getId(),
            'unreadCount' => $unreadCount,
        ];
    }
}
