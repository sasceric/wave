<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'campaign_conversation')]
#[ORM\UniqueConstraint(name: 'uniq_campaign_conversation_campaign_creator', columns: ['campaign_id', 'creator_id'])]
class CampaignConversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\ManyToOne(targetEntity: Creator::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Creator $creator;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 240)]
    private string $lastMessagePreview;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $lastMessageSender;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $creatorUnreadReminderSentAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $companyUnreadReminderSentAt = null;

    public function __construct(Campaign $campaign, Creator $creator, User $lastMessageSender)
    {
        $this->campaign = $campaign;
        $this->creator = $creator;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->lastMessagePreview = '';
        $this->lastMessageSender = $lastMessageSender;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getCreator(): Creator
    {
        return $this->creator;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastMessagePreview(): string
    {
        return $this->lastMessagePreview;
    }

    public function getLastMessageSender(): User
    {
        return $this->lastMessageSender;
    }

    public function getUnreadReminderSentAt(User $recipient): ?DateTimeImmutable
    {
        return $this->isCreator($recipient)
            ? $this->creatorUnreadReminderSentAt
            : $this->companyUnreadReminderSentAt;
    }

    public function markUnreadReminderSent(User $recipient): void
    {
        $sentAt = new DateTimeImmutable();
        if ($this->isCreator($recipient)) {
            $this->creatorUnreadReminderSentAt = $sentAt;

            return;
        }

        $this->companyUnreadReminderSentAt = $sentAt;
    }

    public function clearUnreadReminder(User $recipient): bool
    {
        if ($this->isCreator($recipient)) {
            if ($this->creatorUnreadReminderSentAt === null) {
                return false;
            }
            $this->creatorUnreadReminderSentAt = null;

            return true;
        }
        if ($this->companyUnreadReminderSentAt === null) {
            return false;
        }
        $this->companyUnreadReminderSentAt = null;

        return true;
    }

    public function recordMessage(string $body, User $sender): void
    {
        $this->lastMessagePreview = mb_substr($body, 0, 240);
        $this->lastMessageSender = $sender;
        $this->updatedAt = new DateTimeImmutable();
    }

    private function isCreator(User $user): bool
    {
        $creatorOwner = $this->creator->getOwner();
        if ($creatorOwner instanceof User && $creatorOwner->getId() === $user->getId()) {
            return true;
        }
        $companyOwner = $this->campaign->getCompany()->getOwner();
        if ($companyOwner instanceof User && $companyOwner->getId() === $user->getId()) {
            return false;
        }

        throw new \InvalidArgumentException('Only a conversation participant can manage its unread reminder.');
    }
}
