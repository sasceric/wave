<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A creator who registered through another creator's referral link or code (Pozovi kreatore).
 * It counts toward the referrer's ambassador tiers once the referred account is confirmed and approved.
 * A flagged referral (same network, many sign-ups from one address, look-alike email) counts only
 * after an administrator accepts it; a rejected one never counts.
 */
#[ORM\Entity]
#[ORM\Table(name: 'creator_referral')]
#[ORM\UniqueConstraint(name: 'uniq_creator_referral_referred', columns: ['referred_id'])]
#[ORM\Index(name: 'idx_creator_referral_referrer', columns: ['referrer_id'])]
class CreatorReferral
{
    public const FLAGS = ['same_network', 'many_from_network', 'similar_email'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $referrer;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $referred;

    #[ORM\Column(length: 24)]
    private string $code;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $networkHash;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $flag = null;

    /** null = automatic, 'accepted' or 'rejected' by an administrator. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $decision = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $referrer, User $referred, string $code, ?string $networkHash, ?string $flag = null)
    {
        $this->referrer = $referrer;
        $this->referred = $referred;
        $this->code = $code;
        $this->networkHash = $networkHash;
        $this->flag = in_array($flag, self::FLAGS, true) ? $flag : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getReferrer(): User { return $this->referrer; }
    public function getReferred(): User { return $this->referred; }
    public function getCode(): string { return $this->code; }
    public function getNetworkHash(): ?string { return $this->networkHash; }
    public function getFlag(): ?string { return $this->flag; }
    public function getDecision(): ?string { return $this->decision; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Waiting for an administrator: flagged and not decided yet. */
    public function needsReview(): bool
    {
        return $this->flag !== null && $this->decision === null;
    }

    public function decide(bool $accept): void
    {
        $this->decision = $accept ? 'accepted' : 'rejected';
        $this->decidedAt = new \DateTimeImmutable();
    }
}
