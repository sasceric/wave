<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_block')]
#[ORM\UniqueConstraint(name: 'uniq_active_user_block', columns: ['blocker_id', 'blocked_id'], options: ['where' => '(cleared_at IS NULL)'])]
#[ORM\Index(name: 'idx_block_reverse', columns: ['blocked_id', 'blocker_id'], options: ['where' => '(cleared_at IS NULL)'])]
class UserBlock
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    public function __construct(string $id, User $blocker, User $blocked, \DateTimeImmutable $createdAt, ?\DateTimeImmutable $clearedAt)
    {
        $this->id = $id;
        $this->blocker = $blocker;
        $this->blocked = $blocked;
        $this->createdAt = $createdAt;
        $this->clearedAt = $clearedAt;
    }

    public function getId(): string { return $this->id; }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'blocker_id', nullable: false, onDelete: 'CASCADE')]
    private User $blocker;

    public function getBlocker(): User { return $this->blocker; }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'blocked_id', nullable: false, onDelete: 'CASCADE')]
    private User $blocked;

    public function getBlocked(): User { return $this->blocked; }

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $clearedAt;

    public function getClearedAt(): ?\DateTimeImmutable { return $this->clearedAt; }
}
