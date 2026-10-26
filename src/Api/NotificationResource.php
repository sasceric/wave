<?php

namespace App\Api;

use App\Entity\Notification;
use App\Localization\ApiMessages;

final class NotificationResource
{
    public static function fromEntity(Notification $notification, string $locale): array
    {
        $actor = $notification->getActor();
        $actorName = $actor?->getCompany()?->getName()
            ?? $actor?->getCreator()?->getDisplayName()
            ?? 'Wave';
        $campaign = $notification->getCampaign();
        $campaignTranslation = $campaign?->getTranslations()[$locale] ?? [];

        return [
            'id' => $notification->getId(),
            'type' => $notification->getType(),
            'actorId' => $actor?->getId(),
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
                'compensationType' => $campaign->getCompensationType(),
            ],
            'supportTicketId' => $notification->getSupportTicket()?->getId(),
            'supportAdmin' => $notification->getRecipient()->hasRole('ROLE_ADMIN'),
            'conversationId' => $notification->getConversation()?->getId(),
            'reviewApplicationId' => $notification->getReviewApplication()?->getId(),
            'reviewAccountType' => $notification->getReviewApplication() === null ? null
                : ($notification->getReviewApplication()->getCreator()->getOwner()?->getId() === $notification->getRecipient()->getId() ? 'creator' : 'company'),
        ];
    }
}
