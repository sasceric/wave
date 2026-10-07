<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Schema mapping; mutations are performed atomically by CreditService. */
#[ORM\Entity]
#[ORM\Table(name: 'credit_voucher')]
class CreditVoucher
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $codeHash;

    #[ORM\Column(length: 4)]
    private string $suffix;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column]
    private int $priceMinor;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $issuedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $redeemedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $redeemedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(string $id, string $codeHash, string $suffix, int $amount, int $priceMinor, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->codeHash = $codeHash;
        $this->suffix = $suffix;
        $this->amount = $amount;
        $this->priceMinor = $priceMinor;
        $this->createdAt = $createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getSuffix(): string
    {
        return $this->suffix;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getPriceMinor(): int
    {
        return $this->priceMinor;
    }

    public function getIssuedBy(): ?User
    {
        return $this->issuedBy;
    }

    public function getRedeemedBy(): ?User
    {
        return $this->redeemedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRedeemedAt(): ?\DateTimeImmutable
    {
        return $this->redeemedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
