<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wave_qr_link')]
#[ORM\UniqueConstraint(name: 'uniq_qr_link_token', columns: ['token'])]
class QrLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $token;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(length: 2048)]
    private string $destination;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $label, string $destination)
    {
        $this->token = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->update($label, $destination);
    }

    public function update(string $label, string $destination): void
    {
        $this->label = $label;
        $this->destination = $destination;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getToken(): string { return $this->token; }
    public function getLabel(): string { return $this->label; }
    public function getDestination(): string { return $this->destination; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
