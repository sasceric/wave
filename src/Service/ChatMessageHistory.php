<?php

namespace App\Service;

use App\Entity\CampaignConversation;
use App\Entity\CreatorInquiry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/** Indexed cursor pagination; offsets would shift whenever a new message arrives. */
final class ChatMessageHistory
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function page(string $messageClass, string $association, CampaignConversation|CreatorInquiry $thread, Request $request, User $viewer): array
    {
        $limit = $this->positiveInteger($request->query->get('limit', '50'));
        $before = $this->positiveInteger($request->query->get('before'));
        $after = $this->positiveInteger($request->query->get('after'));
        if ($limit === null || $limit > 100 || ($before !== null && $after !== null)) {
            throw new \InvalidArgumentException('Invalid chat cursor or page size.');
        }

        $query = $this->entityManager->createQueryBuilder()
            ->select('message')->from($messageClass, 'message')
            ->where('message.'.$association.' = :thread')->setParameter('thread', $thread)
            ->orderBy('message.id', $after === null ? 'DESC' : 'ASC')
            ->setMaxResults($limit + 1);
        if ($before !== null || $after !== null) {
            $query->andWhere('message.id '.($after === null ? '<' : '>').' :cursor')
                ->setParameter('cursor', $before ?? $after);
        }
        $messages = $query->getQuery()->getResult();
        $hasMore = count($messages) > $limit;
        $messages = array_slice($messages, 0, $limit);
        if ($after === null) {
            $messages = array_reverse($messages);
        }

        $lastRead = $this->entityManager->createQueryBuilder()
            ->select('message.id, message.readAt')->from($messageClass, 'message')
            ->where('message.'.$association.' = :thread')->andWhere('message.sender = :viewer')
            ->andWhere('message.readAt IS NOT NULL')->orderBy('message.id', 'DESC')->setMaxResults(1)
            ->setParameter('thread', $thread)->setParameter('viewer', $viewer)->getQuery()->getOneOrNullResult();

        $creatorOwner = $thread->getCreator()->getOwner();
        $companyOwner = $thread instanceof CampaignConversation
            ? $thread->getCampaign()->getCompany()->getOwner() : $thread->getCompany()->getOwner();
        $counterpart = $creatorOwner?->getId() === $viewer->getId() ? $companyOwner : $creatorOwner;
        $firstUnread = $counterpart === null ? null : $this->entityManager->createQueryBuilder()
            ->select('message.id')->from($messageClass, 'message')
            ->where('message.'.$association.' = :thread')->andWhere('message.sender = :sender')
            ->andWhere('message.readAt IS NULL')->orderBy('message.id', 'ASC')->setMaxResults(1)
            ->setParameter('thread', $thread)->setParameter('sender', $counterpart)
            ->getQuery()->getOneOrNullResult();

        return [
            'messages' => $messages,
            'readReceipt' => $lastRead === null ? null : ['throughId' => $lastRead['id'], 'readAt' => $lastRead['readAt']->format(DATE_ATOM)],
            'meta' => [
                'limit' => $limit,
                'firstUnreadId' => $firstUnread['id'] ?? null,
                'hasMoreOlder' => $after === null && $hasMore,
                'hasMoreNewer' => $after !== null && $hasMore,
                'oldestId' => $messages === [] ? null : $messages[0]->getId(),
                'latestId' => $messages === [] ? null : $messages[array_key_last($messages)]->getId(),
            ],
        ];
    }

    private function positiveInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if ($result === false) {
            throw new \InvalidArgumentException('Invalid chat cursor or page size.');
        }

        return $result;
    }
}
