<?php

namespace App\Api;

use App\Entity\CampaignMessage;
use App\Entity\InquiryMessage;
use App\Localization\DisplayDate;

final class ChatMessageInteractionsResource
{
    public static function fromEntity(CampaignMessage|InquiryMessage $message): array
    {
        $reply = $message->getReplyTo();

        return [
            'hearts' => $message->getHearts(),
            'editedAt' => $message->getEditedAt()?->format(DATE_ATOM),
            'deletedAt' => $message->getDeletedAt()?->format(DATE_ATOM),
            'replyTo' => $reply === null ? null : [
                'id' => $reply->getId(),
                'senderId' => $reply->getSender()->getId(),
                'body' => DisplayDate::text($reply->getBody()),
                'deletedAt' => $reply->getDeletedAt()?->format(DATE_ATOM),
            ],
        ];
    }
}
