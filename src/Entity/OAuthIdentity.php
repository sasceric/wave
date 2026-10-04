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

    public function __construct(User $user, string $provider, string $subject)
    {
        $this->user = $user;
        $this->provider = $provider;
        $this->subject = $subject;
    }

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
