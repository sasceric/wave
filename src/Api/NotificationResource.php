<?php

namespace App\Api;

use App\Entity\Notification;

final class NotificationResource
{
    public static function fromEntity(Notification $notification, string $locale): array
    {
        $actor = $notification->getActor();
        $actorName = $actor?->getCreator()?->getDisplayName()
            ?? $actor?->getCompany()?->getName()
            ?? 'Wave';
        $campaign = $notification->getCampaign();
        $campaignTranslation = $campaign?->getTranslations()[$locale] ?? [];

        return [
            'id' => $notification->getId(),
            'type' => $notification->getType(),
            'actorName' => $actorName,
            'actorImageUrl' => $actor?->getCreator()?->getAvatarMedia()?->getUrl()
                ?? $actor?->getCreator()?->getAvatarUrl()
                ?? $actor?->getCompany()?->getLogoMedia()?->getUrl()
                ?? $actor?->getCompany()?->getLogoUrl(),
            'createdAt' => $notification->getCreatedAt()->format(DATE_ATOM),
            'readAt' => $notification->getReadAt()?->format(DATE_ATOM),
            'campaign' => $campaign === null ? null : [
                'slug' => $campaign->getSlug(),
                'title' => $campaignTranslation['title'] ?? $campaign->getTitle(),
            ],
            'conversationId' => $notification->getConversation()?->getId(),
        ];
    }
}
