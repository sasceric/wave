<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Schema mapping; mutations are performed atomically by CreditService. */
#[ORM\Entity]
#[ORM\Table(name: 'credit_entry')]
#[ORM\Index(name: 'idx_credit_entry_user_created', columns: ['user_id', 'sequence'])]
class CreditEntry
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private int $sequence;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column]
    private int $balanceAfter;

    #[ORM\Column(length: 30)]
    private string $kind;

    #[ORM\Column(length: 255)]
    private string $reference;

    #[ORM\Column(length: 100, unique: true)]
    private string $eventKey;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, User $user, int $sequence, int $amount, int $balanceAfter, string $kind, string $reference, string $eventKey, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->user = $user;
        $this->sequence = $sequence;
        $this->amount = $amount;
        $this->balanceAfter = $balanceAfter;
        $this->kind = $kind;
        $this->reference = $reference;
        $this->eventKey = $eventKey;
        $this->createdAt = $createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getBalanceAfter(): int
    {
        return $this->balanceAfter;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getEventKey(): string
    {
        return $this->eventKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
