<?php

namespace App\Service;

use App\Entity\CampaignMessage;
use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class UnreadInboxCounter
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function notifications(User $user): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(notification.id)')
            ->from(Notification::class, 'notification')
            ->where('notification.recipient = :user')
            ->andWhere('notification.readAt IS NULL')
            ->andWhere('notification.type <> :chatMessage')
            ->setParameter('user', $user)
            ->setParameter('chatMessage', 'chat_message')
            ->getQuery()->getSingleScalarResult();
    }

    public function total(User $user): int
    {
        $messages = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(message.id)')
            ->from(CampaignMessage::class, 'message')
            ->join('message.conversation', 'conversation')
            ->join('conversation.creator', 'creator')
            ->join('conversation.campaign', 'campaign')
            ->join('campaign.company', 'company')
            ->where('(creator.owner = :user OR company.owner = :user)')
            ->andWhere('message.sender <> :user')
            ->andWhere('message.readAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()->getSingleScalarResult();

        // Chat notification records describe these same messages; do not count them twice.
        return $messages + $this->notifications($user);
    }
}
