<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wave_support_ticket')]
#[ORM\Index(name: 'idx_support_owner_activity', columns: ['owner_id', 'updated_at', 'id'])]
#[ORM\Index(name: 'idx_support_activity', columns: ['updated_at', 'id'])]
#[ORM\Index(name: 'idx_support_assignment', columns: ['assigned_to_id'])]
#[ORM\UniqueConstraint(name: 'uniq_support_tracking', columns: ['tracking_token'])]
#[ORM\UniqueConstraint(name: 'uniq_support_submission', columns: ['submission_key'])]
class SupportTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $trackingToken;

    #[ORM\Column(length: 64)]
    private string $submissionKey;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 40)]
    private string $phone;

    #[ORM\Column(length: 20)]
    private string $kind;

    #[ORM\Column(length: 30)]
    private string $category;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(length: 3)]
    private string $locale;

    #[ORM\Column(length: 20)]
    private string $status = 'open';

    #[ORM\Column(type: 'json')]
    private array $attachments = [];

    #[ORM\Column]
    private bool $receiptSent = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    #[ORM\Column(length: 10, options: ['default' => 'normal'])]
    private string $priority = 'normal';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 240, nullable: true)]
    private ?string $lastReplyPreview = null;

    public function __construct(array $data, string $locale, string $submissionKey)
    {
        $this->trackingToken = bin2hex(random_bytes(32));
        $this->submissionKey = $submissionKey;
        $this->name = $data['name'];
        $this->email = $data['email'];
        $this->phone = $data['phone'];
        $this->kind = $data['kind'];
        $this->category = $data['category'];
        $this->title = $data['title'];
        $this->description = $data['description'];
        $this->locale = $locale;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $owner): void { $this->owner = $owner; }
    public function getAssignedTo(): ?User { return $this->assignedTo; }
    public function setAssignedTo(?User $user): void { $this->assignedTo = $user; }
    public function getPriority(): string { return $this->priority; }
    public function setPriority(string $priority): void { $this->priority = $priority; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function setCategory(string $category): void { $this->category = $category; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt ?? $this->createdAt; }
    public function touch(?string $preview = null): void {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($preview !== null) $this->lastReplyPreview = mb_substr($preview, 0, 240);
    }
    public function getLastReplyPreview(): string { return $this->lastReplyPreview ?? mb_substr($this->description, 0, 240); }

    public function getId(): ?int { return $this->id; }
    public function getNumber(): string { return 'T-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT); }
    public function getTrackingToken(): string { return $this->trackingToken; }
    public function getName(): string { return $this->name; }
    public function getEmail(): string { return $this->email; }
    public function getPhone(): string { return $this->phone; }
    public function getKind(): string { return $this->kind; }
    public function getCategory(): string { return $this->category; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): string { return $this->description; }
    public function getLocale(): string { return $this->locale; }
    public function getStatus(): string { return $this->status; }
    public function getAttachments(): array { return $this->attachments; }
    public function setAttachments(array $attachments): void { $this->attachments = $attachments; }
    public function isReceiptSent(): bool { return $this->receiptSent; }
    public function markReceiptSent(): void { $this->receiptSent = true; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
