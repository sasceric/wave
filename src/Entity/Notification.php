<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wave_notification')]
#[ORM\Index(name: 'idx_notification_support', columns: ['recipient_id', 'support_ticket_id', 'read_at'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Campaign $campaign;

    #[ORM\ManyToOne(targetEntity: CampaignConversation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CampaignConversation $conversation;

    #[ORM\ManyToOne(targetEntity: SupportTicket::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?SupportTicket $supportTicket;

    #[ORM\Column(length: 40)]
    private string $type;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $readAt = null;

    public function __construct(
        User $recipient,
        string $type,
        ?User $actor = null,
        ?Campaign $campaign = null,
        ?CampaignConversation $conversation = null,
        ?SupportTicket $supportTicket = null,
    ) {
        $this->recipient = $recipient;
        $this->type = $type;
        $this->actor = $actor;
        $this->campaign = $campaign;
        $this->conversation = $conversation;
        $this->supportTicket = $supportTicket;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getCampaign(): ?Campaign
    {
        return $this->campaign;
    }

    public function getConversation(): ?CampaignConversation
    {
        return $this->conversation;
    }

    public function getSupportTicket(): ?SupportTicket
    {
        return $this->supportTicket;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function markRead(): void
    {
        $this->readAt ??= new DateTimeImmutable();
    }
}
