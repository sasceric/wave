<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'campaign_invitation')]
#[ORM\UniqueConstraint(name: 'uniq_campaign_invitation_pair', columns: ['campaign_id', 'creator_id'])]
class CampaignInvitation
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

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $inviter;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $respondedAt = null;

    public function __construct(Campaign $campaign, Creator $creator, User $inviter, string $message)
    {
        $this->campaign = $campaign;
        $this->creator = $creator;
        $this->inviter = $inviter;
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

    public function getInviter(): User
    {
        return $this->inviter;
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
        if (!in_array($status, ['accepted', 'declined'], true)) {
            throw new \InvalidArgumentException('Campaign invitations can only be accepted or declined.');
        }

        $this->status = $status;
        $this->respondedAt = new DateTimeImmutable();
    }
}
