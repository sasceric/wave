<?php

namespace App\Service;

use App\Entity\CampaignMessage;
use App\Entity\InquiryMessage;
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

    public function inquiryMessages(User $user, ?int $inquiryId = null): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('COUNT(message.id)')->from(InquiryMessage::class, 'message')
            ->join('message.inquiry', 'inquiry')->join('inquiry.creator', 'creator')
            ->join('inquiry.company', 'company')
            ->where('(creator.owner = :user OR company.owner = :user)')
            ->andWhere('inquiry.status = :accepted')->setParameter('accepted', 'accepted')
            ->andWhere('message.sender <> :user')->andWhere('message.readAt IS NULL')
            ->setParameter('user', $user);
        if ($inquiryId !== null) {
            $query->andWhere('inquiry.id = :inquiryId')->setParameter('inquiryId', $inquiryId);
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    /** @return array<int, int> */
    public function conversations(User $user, ?int $conversationId = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(message.conversation) AS threadId, COUNT(message.id) AS unreadCount')
            ->from(CampaignMessage::class, 'message')
            ->join('message.conversation', 'conversation')
            ->join('conversation.creator', 'creator')
            ->join('conversation.campaign', 'campaign')
            ->join('campaign.company', 'company')
            ->where('(creator.owner = :user OR company.owner = :user)')
            ->andWhere('message.sender <> :user')
            ->andWhere('message.readAt IS NULL')
            ->setParameter('user', $user)
            ->groupBy('message.conversation');
        if ($conversationId !== null) {
            $query->andWhere('conversation.id = :conversationId')->setParameter('conversationId', $conversationId);
        }
        $rows = $query->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $row): array => ['id' => (int) $row['threadId'], 'count' => (int) $row['unreadCount']], $rows), 'count', 'id');
    }

    /** @return array<int, int> */
    public function inquiries(User $user): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(message.inquiry) AS threadId, COUNT(message.id) AS unreadCount')
            ->from(InquiryMessage::class, 'message')
            ->join('message.inquiry', 'inquiry')
            ->join('inquiry.creator', 'creator')
            ->join('inquiry.company', 'company')
            ->where('(creator.owner = :user OR company.owner = :user)')
            ->andWhere('inquiry.status = :accepted')->setParameter('accepted', 'accepted')
            ->andWhere('message.sender <> :user')
            ->andWhere('message.readAt IS NULL')
            ->setParameter('user', $user)
            ->groupBy('message.inquiry')
            ->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $row): array => ['id' => (int) $row['threadId'], 'count' => (int) $row['unreadCount']], $rows), 'count', 'id');
    }

    public function messages(User $user): int
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
        return $messages + $this->inquiryMessages($user);
    }

    public function total(User $user): int
    {
        return $this->messages($user) + $this->notifications($user);
    }
}
