<?php

namespace App\Api;

use App\Entity\Notification;
use App\Localization\ApiMessages;

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
            'actorName' => $actor?->isDeleted() ? ApiMessages::get('deleted_account', $locale) : $actorName,
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
            'supportTicketId' => $notification->getSupportTicket()?->getId(),
            'supportAdmin' => $notification->getRecipient()->hasRole('ROLE_ADMIN'),
            'conversationId' => $notification->getConversation()?->getId(),
        ];
    }
}
