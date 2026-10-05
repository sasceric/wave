<?php

namespace App\Command;

use App\Account\AccountEmailSender;
use App\Entity\CampaignConversation;
use App\Entity\CampaignMessage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:send-unread-message-reminders',
    description: 'Email participants about campaign messages that have been unread for one hour.',
)]
final class SendUnreadMessageRemindersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountEmailSender $emailSender,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cutoff = new \DateTimeImmutable('-1 hour');
        $dueMessages = $this->entityManager->createQueryBuilder()
            ->select('message')
            ->from(CampaignMessage::class, 'message')
            ->where('message.readAt IS NULL')
            ->andWhere('message.createdAt <= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('message.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var array<string, array{conversation: CampaignConversation, recipient: User, sender: User}> $reminders */
        $reminders = [];
        foreach ($dueMessages as $message) {
            if (!$message instanceof CampaignMessage) {
                throw new \LogicException('Unexpected result while loading due campaign messages.');
            }

            $conversation = $message->getConversation();
            $sender = $message->getSender();
            $companyOwner = $conversation->getCampaign()->getCompany()->getOwner();
            $creatorOwner = $conversation->getCreator()->getOwner();
            if ($companyOwner instanceof User && $companyOwner->getId() === $sender->getId()) {
                $recipient = $creatorOwner;
            } elseif ($creatorOwner instanceof User && $creatorOwner->getId() === $sender->getId()) {
                $recipient = $companyOwner;
            } else {
                throw new \LogicException('Campaign message sender is not a conversation participant.');
            }
            if (!$recipient instanceof User || $conversation->getUnreadReminderSentAt($recipient) !== null) {
                continue;
            }

            $conversationId = $conversation->getId();
            $recipientId = $recipient->getId();
            if ($conversationId === null || $recipientId === null) {
                throw new \LogicException('Unread message reminders require persisted conversation participants.');
            }
            $key = $conversationId.':'.$recipientId;
            $reminders[$key] ??= [
                'conversation' => $conversation,
                'recipient' => $recipient,
                'sender' => $sender,
            ];
        }

        $sent = 0;
        foreach ($reminders as $reminder) {
            /** @var CampaignConversation $conversation */
            $conversation = $reminder['conversation'];
            /** @var User $recipient */
            $recipient = $reminder['recipient'];
            /** @var User $sender */
            $sender = $reminder['sender'];
            $unreadCount = (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(message.id)')
                ->from(CampaignMessage::class, 'message')
                ->where('message.conversation = :conversation')
                ->andWhere('message.sender != :recipient')
                ->andWhere('message.readAt IS NULL')
                ->setParameter('conversation', $conversation)
                ->setParameter('recipient', $recipient)
                ->getQuery()
                ->getSingleScalarResult();
            if ($unreadCount === 0) {
                continue;
            }

            $this->emailSender->sendUnreadMessageReminder(
                $recipient,
                $this->participantName($sender),
                $conversation->getCampaign()->getTitle(),
                $unreadCount,
                $conversation->getId() ?? throw new \LogicException('Conversation must be persisted.'),
            );
            $conversation->markUnreadReminderSent($recipient);
            $this->entityManager->flush();
            ++$sent;
        }

        $output->writeln(sprintf('Sent %d unread message reminder(s).', $sent));

        return Command::SUCCESS;
    }

    private function participantName(User $user): string
    {
        if ($user->getCreator() !== null) {
            return $user->getCreator()->getDisplayName();
        }
        if ($user->getCompany() !== null) {
            return $user->getCompany()->getName();
        }

        return $user->getEmail();
    }
}
