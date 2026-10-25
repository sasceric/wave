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

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $event = null;

    public function __construct(CampaignConversation $conversation, User $sender, string $body, ?array $event = null)
    {
        $this->conversation = $conversation;
        $this->sender = $sender;
        $this->body = $body;
        $this->event = $event;
        $this->createdAt = new DateTimeImmutable();
        $conversation->recordMessage($body, $sender);
    }

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $replyTo = null;

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $hearts = [];

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $editedAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    public function getEditedAt(): ?DateTimeImmutable { return $this->editedAt; }
    public function getDeletedAt(): ?DateTimeImmutable { return $this->deletedAt; }

    public function canModify(User $user): bool
    {
        return $this->sender->getId() === $user->getId() && $this->event === null
            && $this->deletedAt === null && $this->createdAt > new DateTimeImmutable('-10 minutes');
    }

    public function editBody(string $body): void
    {
        $this->body = $body;
        $this->editedAt = new DateTimeImmutable();
    }

    public function deleteBody(): void
    {
        $this->body = '';
        $this->hearts = [];
        $this->deletedAt = new DateTimeImmutable();
    }

    public function getReplyTo(): ?self { return $this->replyTo; }
    public function setReplyTo(self $message): void { $this->replyTo = $message; }
    public function getHearts(): array { return $this->hearts; }

    public function setHeart(User $user, bool $active): void
    {
        $this->hearts = array_values(array_filter($this->hearts, static fn (int $id): bool => $id !== $user->getId()));
        if ($active) {
            $this->hearts[] = $user->getId();
        }
        sort($this->hearts);
    }

    public function getEvent(): ?array { return $this->event; }

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
