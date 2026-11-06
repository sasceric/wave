<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An extra sign-in address of an account. The main address stays in wave_user.email; an address
 * here can be used to sign in only once confirmed, and is unique across both tables.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_email')]
#[ORM\UniqueConstraint(name: 'uniq_user_email_email', columns: ['email'])]
#[ORM\Index(name: 'idx_user_email_user', columns: ['user_id'])]
class UserEmail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $verifyTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifyExpiresAt = null;

    public function __construct(User $user, string $email, bool $verified = false)
    {
        $this->user = $user;
        $this->email = mb_strtolower(trim($email));
        $this->createdAt = new \DateTimeImmutable();
        $this->verifiedAt = $verified ? $this->createdAt : null;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getEmail(): string { return $this->email; }
    public function isVerified(): bool { return $this->verifiedAt !== null; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Returns the raw token for the confirmation link; only its hash is stored. */
    public function issueVerifyToken(): string
    {
        $raw = bin2hex(random_bytes(32));
        $this->verifyTokenHash = hash('sha256', $raw);
        $this->verifyExpiresAt = new \DateTimeImmutable('+24 hours');

        return $raw;
    }

    public function isTokenValid(string $raw): bool
    {
        return $this->verifiedAt === null && $this->verifyTokenHash !== null && $this->verifyExpiresAt !== null
            && $this->verifyExpiresAt > new \DateTimeImmutable() && hash_equals($this->verifyTokenHash, hash('sha256', $raw));
    }

    public function markVerified(): void
    {
        $this->verifiedAt = new \DateTimeImmutable();
        $this->verifyTokenHash = null;
        $this->verifyExpiresAt = null;
    }
}
