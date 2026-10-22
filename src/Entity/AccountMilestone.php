<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Written with an idempotent DBAL insert by AccountActivationListener. */
#[ORM\Entity]
#[ORM\Table(name: 'account_milestone')]
#[ORM\Index(name: 'idx_account_milestone_stage_at', columns: ['stage', 'occurred_at'])]
class AccountMilestone
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', onDelete: 'CASCADE')]
    private User $user;
    #[ORM\Id]
    #[ORM\Column(length: 20)]
    private string $stage;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;
    public function __construct(User $user, string $stage, \DateTimeImmutable $occurredAt)
    {
        $this->user = $user; $this->stage = $stage; $this->occurredAt = $occurredAt;
    }
}

