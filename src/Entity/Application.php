<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'campaign_application')]
#[ORM\UniqueConstraint(name: 'uniq_application_campaign_creator', columns: ['campaign_id', 'creator_id'])]
class Application
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\ManyToOne(targetEntity: Creator::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Creator $creator;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\OneToOne(mappedBy: 'application', targetEntity: Offer::class, cascade: ['persist', 'remove'])]
    private ?Offer $offer = null;

    public function __construct(Campaign $campaign, Creator $creator, string $message)
    {
        $this->campaign = $campaign;
        $this->creator = $creator;
        $this->message = $message;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getCreator(): Creator
    {
        return $this->creator;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getOffer(): ?Offer
    {
        return $this->offer;
    }

    public function setOffer(?Offer $offer): void
    {
        $this->offer = $offer;
    }
}
