<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Schema mapping; mutations are performed atomically by CreditService. */
#[ORM\Entity]
#[ORM\Table(name: 'credit_announcement')]
class CreditAnnouncement
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private int $grantAmount;

    #[ORM\Column]
    private int $applicationCost;

    #[ORM\Column]
    private int $campaignCost;

    #[ORM\Column]
    private \DateTimeImmutable $activatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $queuedAt = null;

    public function __construct(string $id, User $user, int $grantAmount, int $applicationCost, int $campaignCost, \DateTimeImmutable $activatedAt)
    {
        $this->id = $id;
        $this->user = $user;
        $this->grantAmount = $grantAmount;
        $this->applicationCost = $applicationCost;
        $this->campaignCost = $campaignCost;
        $this->activatedAt = $activatedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getGrantAmount(): int
    {
        return $this->grantAmount;
    }

    public function getApplicationCost(): int
    {
        return $this->applicationCost;
    }

    public function getCampaignCost(): int
    {
        return $this->campaignCost;
    }

    public function getActivatedAt(): \DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getQueuedAt(): ?\DateTimeImmutable
    {
        return $this->queuedAt;
    }
}
