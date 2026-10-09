<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wave_support_ticket_message')]
#[ORM\Index(name: 'idx_support_message_history', columns: ['ticket_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_support_reply_key', columns: ['ticket_id', 'submission_key'])]
class SupportTicketMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SupportTicket::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SupportTicket $ticket;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $sender;

    #[ORM\Column(length: 180)]
    private string $senderName;

    #[ORM\Column]
    private bool $staff;

    #[ORM\Column]
    private bool $internal;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'json')]
    private array $attachments = [];

    #[ORM\Column(length: 64)]
    private string $submissionKey;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(SupportTicket $ticket, User $sender, string $body, bool $internal, string $submissionKey)
    {
        $this->ticket = $ticket;
        $this->sender = $sender;
        $this->senderName = $sender->getCreator()?->getDisplayName() ?? $sender->getCompany()?->getName() ?? ($sender->getId() === $ticket->getOwner()?->getId() ? $ticket->getName() : 'Wave');
        $this->staff = $sender->hasRole('ROLE_ADMIN');
        $this->body = $body;
        $this->internal = $internal;
        $this->submissionKey = $submissionKey;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getTicket(): SupportTicket { return $this->ticket; }
    public function getSender(): ?User { return $this->sender; }
    public function getSenderName(): string { return $this->senderName; }
    public function isStaff(): bool { return $this->staff; }
    public function isInternal(): bool { return $this->internal; }
    public function getBody(): string { return $this->body; }
    public function getAttachments(): array { return $this->attachments; }
    public function setAttachments(array $files): void { $this->attachments = $files; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
