<?php

namespace App\Service;

use App\Entity\CampaignMessage;
use App\Entity\InquiryMessage;
use Doctrine\ORM\EntityManagerInterface;

final class ChatMessageReplies
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function attach(CampaignMessage|InquiryMessage $message, mixed $replyId): bool
    {
        if ($replyId === null) {
            return true;
        }
        if (!is_int($replyId) || $replyId < 1) {
            return false;
        }
        $reply = $this->entityManager->find($message::class, $replyId);
        if ($reply?->getDeletedAt() !== null) {
            return false;
        }
        if ($message instanceof CampaignMessage && $reply instanceof CampaignMessage
            && $reply->getConversation()->getId() === $message->getConversation()->getId()) {
            $message->setReplyTo($reply);

            return true;
        }
        if ($message instanceof InquiryMessage && $reply instanceof InquiryMessage
            && $reply->getInquiry()->getId() === $message->getInquiry()->getId()) {
            $message->setReplyTo($reply);

            return true;
        }

        return false;
    }
}
