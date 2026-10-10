<?php

namespace App\Background;

use App\Account\AccountEmailSender;
use App\Entity\CampaignConversation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class ReminderBatch
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountEmailSender $emails,
        private readonly QueuedMailer $mailer,
        private readonly JobDispatcher $jobs,
    ) {
    }

    public function run(bool $continue = true): int
    {
        $db = $this->entityManager->getConnection();
        // One bounded row per unread thread/recipient, rather than hydrating its backlog.
        $sql = 'SELECT t.id, cr.owner_id AS recipient, co.owner_id AS sender, MIN(m.id) AS first_id, COUNT(m.id) AS unread_count FROM campaign_conversation t JOIN campaign c ON c.id = t.campaign_id JOIN company co ON co.id = c.company_id JOIN creator cr ON cr.id = t.creator_id JOIN campaign_message m ON m.conversation_id = t.id AND m.sender_id = co.owner_id WHERE cr.owner_id IN (SELECT id FROM wave_user WHERE deleted_at IS NULL) AND co.owner_id IN (SELECT id FROM wave_user WHERE deleted_at IS NULL) AND t.creator_unread_reminder_sent_at IS NULL AND cr.owner_id IS NOT NULL AND m.read_at IS NULL GROUP BY t.id, cr.owner_id, co.owner_id HAVING MIN(m.created_at) <= ? UNION ALL SELECT t.id, co.owner_id AS recipient, cr.owner_id AS sender, MIN(m.id) AS first_id, COUNT(m.id) AS unread_count FROM campaign_conversation t JOIN campaign c ON c.id = t.campaign_id JOIN company co ON co.id = c.company_id JOIN creator cr ON cr.id = t.creator_id JOIN campaign_message m ON m.conversation_id = t.id AND m.sender_id = cr.owner_id WHERE cr.owner_id IN (SELECT id FROM wave_user WHERE deleted_at IS NULL) AND co.owner_id IN (SELECT id FROM wave_user WHERE deleted_at IS NULL) AND t.company_unread_reminder_sent_at IS NULL AND co.owner_id IS NOT NULL AND m.read_at IS NULL GROUP BY t.id, co.owner_id, cr.owner_id HAVING MIN(m.created_at) <= ? ORDER BY id, recipient LIMIT 100';
        $cutoff = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $rows = $db->fetchAllAssociative($sql, [$cutoff, $cutoff]);
        $count = 0;
        foreach ($rows as $row) {
            $count += $db->transactional(function () use ($db, $row): int {
                $state = $db->fetchAssociative('SELECT * FROM campaign_conversation WHERE id = ? FOR UPDATE', [$row['id']]);
                if (!$state) {
                    return 0;
                }
                $conversation = $this->entityManager->find(CampaignConversation::class, (int) $row['id']);
                $recipient = $this->entityManager->find(User::class, (int) $row['recipient']);
                $sender = $this->entityManager->find(User::class, (int) $row['sender']);
                if (!$conversation || !$recipient || !$sender || $recipient->isDeleted() || $sender->isDeleted()) {
                    return 0;
                }
                $creator = $conversation->getCreator()->getOwner()?->getId() === $recipient->getId();
                $field = $creator ? 'creator_unread_reminder_sent_at' : 'company_unread_reminder_sent_at';
                if ($state[$field] !== null) {
                    return 0;
                }
                $unread = $db->fetchAssociative('SELECT MIN(id) AS first_id, COUNT(*) AS unread_count FROM campaign_message WHERE conversation_id = ? AND sender_id != ? AND read_at IS NULL', [$row['id'], $row['recipient']]);
                if ((int) $unread['unread_count'] === 0) {
                    return 0;
                }
                $context = ['conversationId' => (int) $row['id'], 'recipientId' => (int) $row['recipient'], 'firstId' => (int) $unread['first_id']];
                $this->mailer->withReminder($context, fn () => $this->emails->sendUnreadMessageReminder($recipient, $sender->getCreator()?->getDisplayName() ?? $sender->getCompany()?->getName() ?? 'Wave', $conversation->getCampaign()->getTitle(), (int) $unread['unread_count'], (int) $row['id']));
                $db->executeStatement('UPDATE campaign_conversation SET ' . $field . ' = ? WHERE id = ?', [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $row['id']]);
                $this->entityManager->refresh($conversation);

                return 1;
            });
        }
        if ($continue && count($rows) === 100) {
            $this->jobs->enqueue('UnreadMessageReminderTask');
        }

        return $count;
    }
}
