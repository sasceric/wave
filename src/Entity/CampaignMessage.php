<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'campaign_message')]
#[ORM\Index(name: 'idx_campaign_message_unread', columns: ['conversation_id', 'sender_id', 'id'], options: ['where' => '(read_at IS NULL)'])]
#[ORM\Index(name: 'idx_campaign_message_history', columns: ['conversation_id', 'id'])]
#[ORM\Index(name: 'idx_campaign_message_receipt', columns: ['conversation_id', 'sender_id', 'id'], options: ['where' => '(read_at IS NOT NULL)'])]
class CampaignMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CampaignConversation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CampaignConversation $conversation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $sender;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $readAt = null;

    public function __construct(CampaignConversation $conversation, User $sender, string $body)
    {
        $this->conversation = $conversation;
        $this->sender = $sender;
        $this->body = $body;
        $this->createdAt = new DateTimeImmutable();
        $conversation->recordMessage($body, $sender);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): CampaignConversation
    {
        return $this->conversation;
    }

    public function getSender(): User
    {
        return $this->sender;
    }

    public function getBody(): string
    {
        return $this->body;
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
