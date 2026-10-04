<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'campaign_bookmark')]
#[ORM\UniqueConstraint(name: 'uniq_campaign_bookmark_user_campaign', columns: ['user_id', 'campaign_id'])]
class CampaignBookmark
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(User $user, Campaign $campaign)
    {
        $this->user = $user;
        $this->campaign = $campaign;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
