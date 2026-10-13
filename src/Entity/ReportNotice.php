<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'report_notice')]
#[ORM\Index(name: 'idx_report_notice_pending', columns: ['recipient_id', 'read_at', 'created_at'])]
class ReportNotice
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column]
    private int $version;

    public function getVersion(): int { return $this->version; }

    public function __construct(string $id, int $version, User $recipient, MarketplaceReport $report, string $status, string $message, \DateTimeImmutable $createdAt, ?\DateTimeImmutable $readAt)
    {
        $this->version = $version;
        $this->id = $id;
        $this->recipient = $recipient;
        $this->report = $report;
        $this->status = $status;
        $this->message = $message;
        $this->createdAt = $createdAt;
        $this->readAt = $readAt;
    }

    public function getId(): string { return $this->id; }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recipient_id', nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    public function getRecipient(): User { return $this->recipient; }

    #[ORM\ManyToOne(targetEntity: MarketplaceReport::class)]
    #[ORM\JoinColumn(name: 'report_id', nullable: false, onDelete: 'CASCADE')]
    private MarketplaceReport $report;

    public function getReport(): MarketplaceReport { return $this->report; }

    #[ORM\Column(length: 20)]
    private string $status;

    public function getStatus(): string { return $this->status; }

    #[ORM\Column(type: 'text')]
    private string $message;

    public function getMessage(): string { return $this->message; }

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt;

    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
}
