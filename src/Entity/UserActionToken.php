<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_action_token')]
#[ORM\Index(name: 'idx_action_token_expiry', columns: ['expires_at'])]
#[ORM\UniqueConstraint(name: 'uniq_user_action_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_user_action_token_user_purpose', columns: ['user_id', 'purpose'])]
class UserActionToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $purpose;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $purpose, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->purpose = $purpose;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }
}
