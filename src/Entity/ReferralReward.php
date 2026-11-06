<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An ambassador tier a creator reached (20, 35 or 50 approved referrals). Rewards become active
 * (badge, featured placement) when an administrator approves them after checking the referrals.
 */
#[ORM\Entity]
#[ORM\Table(name: 'referral_reward')]
#[ORM\UniqueConstraint(name: 'uniq_referral_reward_user_tier', columns: ['user_id', 'tier'])]
class ReferralReward
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'smallint')]
    private int $tier;

    #[ORM\Column]
    private \DateTimeImmutable $reachedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    public function __construct(User $user, int $tier)
    {
        $this->user = $user;
        $this->tier = $tier;
        $this->reachedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getTier(): int { return $this->tier; }
    public function getReachedAt(): \DateTimeImmutable { return $this->reachedAt; }
    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function isApproved(): bool { return $this->approvedAt !== null; }
    public function approve(): void { $this->approvedAt ??= new \DateTimeImmutable(); }
}
