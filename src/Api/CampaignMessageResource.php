<?php

namespace App\Api;

use App\Entity\CampaignMessage;

final class CampaignMessageResource
{
    public static function fromEntity(CampaignMessage $message): array
    {
        return [
            'id' => $message->getId(),
            'body' => \App\Localization\DisplayDate::text($message->getBody()),
            'event' => $message->getEvent(),
            'createdAt' => $message->getCreatedAt()->format(DATE_ATOM),
            'readAt' => $message->getReadAt()?->format(DATE_ATOM),
            'senderId' => $message->getSender()->getId(),
        ];
    }
}
