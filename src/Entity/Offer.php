<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'campaign_offer')]
class Offer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'offer', targetEntity: Application::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Application $application;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $respondedAt = null;

    public function __construct(Application $application, int $amount, string $message)
    {
        $this->application = $application;
        $this->amount = $amount;
        $this->message = $message;
        $this->createdAt = new DateTimeImmutable();
        $application->setOffer($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApplication(): Application
    {
        return $this->application;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRespondedAt(): ?DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function respond(string $status): void
    {
        $this->status = $status;
        $this->respondedAt = new DateTimeImmutable();
        $this->application->setStatus($status === 'accepted' ? 'accepted' : 'offer_declined');
    }
}
