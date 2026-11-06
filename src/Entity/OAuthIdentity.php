<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'oauth_identity')]
#[ORM\UniqueConstraint(name: 'uniq_oauth_provider_subject', columns: ['provider', 'subject'])]
class OAuthIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $provider;

    #[ORM\Column(length: 255)]
    private string $subject;

    /** The Google/Apple account's own address; it can differ from the Wave address. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $linkedAt = null;

    public function __construct(User $user, string $provider, string $subject, ?string $email = null)
    {
        $this->user = $user;
        $this->provider = $provider;
        $this->subject = $subject;
        $this->email = $email === null ? null : mb_substr(mb_strtolower(trim($email)), 0, 180);
        $this->linkedAt = new \DateTimeImmutable();
    }

    public function getEmail(): ?string { return $this->email; }
    public function getLinkedAt(): ?\DateTimeImmutable { return $this->linkedAt; }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }
}
