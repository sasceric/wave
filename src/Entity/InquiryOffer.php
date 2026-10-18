<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'inquiry_offer')]
#[ORM\Index(name: 'idx_inquiry_offer_history', columns: ['inquiry_id', 'id'])]
class InquiryOffer
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CreatorInquiry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CreatorInquiry $inquiry;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $dueDate;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $respondedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cancellationReason = null;

    public function __construct(CreatorInquiry $inquiry, int $amount, string $currency, DateTimeImmutable $dueDate, string $message)
    {
        $this->inquiry = $inquiry;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->dueDate = $dueDate;
        $this->message = $message;
        $this->createdAt = new DateTimeImmutable();
        $inquiry->setOffer($this);
    }

    public function getId(): ?int { return $this->id; }
    public function getInquiry(): CreatorInquiry { return $this->inquiry; }
    public function getAmount(): int { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getDueDate(): DateTimeImmutable { return $this->dueDate; }
    public function getMessage(): string { return $this->message; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getRespondedAt(): ?DateTimeImmutable { return $this->respondedAt; }
    public function getCancellationReason(): ?string { return $this->cancellationReason; }
    public function respond(string $status): void { $this->status = $status; $this->respondedAt = new DateTimeImmutable(); }
    public function cancel(string $reason): void { $this->respond('cancelled'); $this->cancellationReason = $reason; }
}
