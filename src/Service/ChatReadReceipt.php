<?php

namespace App\Service;

use App\Entity\CampaignConversation;
use App\Entity\CampaignMessage;
use App\Entity\CreatorInquiry;
use App\Entity\InquiryMessage;
use App\Entity\Notification;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class ChatReadReceipt
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RealtimeUpdatePublisher $publisher,
    ) {
    }

    /** The controller must first verify participation and acceptance/access. */
    public function acknowledge(CampaignConversation|CreatorInquiry $thread, User $reader, mixed $throughId): array
    {
        if (!is_int($throughId) || $throughId < 1 || $throughId > 2147483647) {
            throw new \InvalidArgumentException('Invalid read cursor.');
        }
        $isCampaign = $thread instanceof CampaignConversation;
        $messageClass = $isCampaign ? CampaignMessage::class : InquiryMessage::class;
        $association = $isCampaign ? 'conversation' : 'inquiry';
        $message = $this->entityManager->getRepository($messageClass)->findOneBy([
            'id' => $throughId, $association => $thread,
        ]);
        if ($message === null) {
            throw new \InvalidArgumentException('Read cursor must belong to this chat.');
        }
        $readAt = new DateTimeImmutable();
        $updated = $this->entityManager->createQueryBuilder()
            ->update($messageClass, 'message')->set('message.readAt', ':readAt')
            ->where('message.'.$association.' = :thread')
            ->andWhere('message.sender != :reader')->andWhere('message.readAt IS NULL')
            ->andWhere('message.id <= :throughId')
            ->setParameter('readAt', $readAt)->setParameter('thread', $thread)
            ->setParameter('reader', $reader)->setParameter('throughId', $throughId)
            ->getQuery()->execute();

        if ($isCampaign) {
            // A concurrently arriving message must keep its notification unread.
            $this->entityManager->createQueryBuilder()
                ->update(Notification::class, 'notification')->set('notification.readAt', ':readAt')
                ->where('notification.conversation = :thread')->andWhere('notification.recipient = :reader')
                ->andWhere('notification.readAt IS NULL')
                ->andWhere('NOT EXISTS (SELECT newer.id FROM '.CampaignMessage::class.' newer WHERE newer.conversation = :thread AND newer.sender != :reader AND newer.id > :throughId)')
                ->setParameter('readAt', $readAt)->setParameter('thread', $thread)
            ->setParameter('reader', $reader)->setParameter('throughId', $throughId)
                ->getQuery()->execute();
            if ($thread->clearUnreadReminder($reader)) {
                $this->entityManager->flush();
            }
        }

        $receipt = [
            'type' => 'chat_read',
            $isCampaign ? 'conversationId' : 'inquiryId' => $thread->getId(),
            'readerId' => $reader->getId(),
            'throughId' => $throughId,
            'readAt' => $readAt->format(DATE_ATOM),
        ];
        if ($updated > 0) {
            $creatorOwner = $thread->getCreator()->getOwner();
            $companyOwner = $isCampaign
                ? $thread->getCampaign()->getCompany()->getOwner()
                : $thread->getCompany()->getOwner();
            $recipient = $creatorOwner?->getId() === $reader->getId() ? $companyOwner : $creatorOwner;
            if ($recipient instanceof User) {
                $this->publisher->publishEvent($recipient, $receipt);
            }
        }

        return $receipt;
    }
}
