<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_user_push_subscription_endpoint_hash', columns: ['endpoint_hash'])]
class UserPushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $endpoint;

    #[ORM\Column(length: 64)]
    private string $endpointHash;

    #[ORM\Column(length: 255)]
    private string $publicKey;

    #[ORM\Column(length: 255)]
    private string $authToken;

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(
        User $user,
        string $endpoint,
        string $publicKey,
        string $authToken,
        string $locale,
    ) {
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->endpointHash = hash('sha256', $endpoint);
        $this->publicKey = $publicKey;
        $this->authToken = $authToken;
        $this->locale = $locale;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getAuthToken(): string
    {
        return $this->authToken;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function update(User $user, string $endpoint, string $publicKey, string $authToken, string $locale): void
    {
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->endpointHash = hash('sha256', $endpoint);
        $this->publicKey = $publicKey;
        $this->authToken = $authToken;
        $this->locale = $locale;
    }
}
