<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'account_type_request')]
#[ORM\Index(name: 'idx_account_type_status', columns: ['status', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_account_type_pending', columns: ['owner_id'], options: ['where' => "status = 'pending'"])]
class AccountTypeRequest
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 12)]
    private string $sourceType;

    #[ORM\Column(length: 12)]
    private string $targetType;

    #[ORM\Column(length: 120)]
    private string $profileName;

    #[ORM\Column(length: 1000)]
    private string $message;

    #[ORM\Column(length: 12)]
    private string $status = 'pending';

    #[ORM\Column(length: 1000)]
    private string $response = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewer = null;

    public function __construct(User $owner, string $sourceType, string $targetType, string $profileName, string $message)
    {
        $this->owner = $owner;
        $this->sourceType = $sourceType;
        $this->targetType = $targetType;
        $this->profileName = $profileName;
        $this->message = $message;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getOwner(): User { return $this->owner; }
    public function getSourceType(): string { return $this->sourceType; }
    public function getTargetType(): string { return $this->targetType; }
    public function getProfileName(): string { return $this->profileName; }
    public function getMessage(): string { return $this->message; }
    public function getStatus(): string { return $this->status; }
    public function getResponse(): string { return $this->response; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function review(string $status, ?User $reviewer, string $response = ''): void
    {
        if ($this->status !== 'pending' || !in_array($status, ['approved', 'rejected', 'withdrawn'], true)) throw new \LogicException('Request already reviewed.');
        $this->status = $status;
        $this->reviewer = $reviewer;
        $this->response = $response;
        $this->reviewedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
