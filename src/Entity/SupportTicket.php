<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wave_support_ticket')]
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
    private string $status = 'received';

    #[ORM\Column(type: 'json')]
    private array $attachments = [];

    #[ORM\Column]
    private bool $receiptSent = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

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
    }

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
