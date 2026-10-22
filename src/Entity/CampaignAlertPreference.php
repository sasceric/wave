<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'campaign_alert_preference')]
class CampaignAlertPreference
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', onDelete: 'CASCADE')]
    private User $user;
    #[ORM\Column]
    private bool $enabled = false;
    #[ORM\Column(type: 'json')]
    private array $categories = [];
    #[ORM\Column(type: 'json')]
    private array $countries = [];
    #[ORM\Column(length: 10)]
    private string $frequency = 'weekly';
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $checkedAt;

    public function __construct(User $user) { $this->user = $user; $this->checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
    public function configure(bool $enabled, array $categories, array $countries, string $frequency): void
    {
        // Changing scope starts a fresh window. Previously published campaigns are never resent.
        if ($this->enabled !== $enabled || $this->categories !== $categories || $this->countries !== $countries || $this->frequency !== $frequency) $this->checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->enabled = $enabled; $this->categories = $categories; $this->countries = $countries; $this->frequency = $frequency;
    }
    public function resource(): array { return ['enabled' => $this->enabled, 'categories' => $this->categories, 'countries' => $this->countries, 'frequency' => $this->frequency]; }
}
