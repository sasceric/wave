<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Schema mapping; mutations are performed atomically by CreditService. */
#[ORM\Entity]
#[ORM\Table(name: 'credit_settings')]
class CreditSettings
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    #[ORM\Column]
    private bool $paid = false;

    #[ORM\Column]
    private int $unitPriceMinor = 20;

    #[ORM\Column]
    private int $applicationCost = 10;

    #[ORM\Column]
    private int $campaignCost = 30;

    #[ORM\Column]
    private int $welcomeGrant = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column]
    private int $activation = 0;

    #[ORM\Column]
    private int $version = 1;

    public function getId(): int
    {
        return $this->id;
    }

    public function getPaid(): bool
    {
        return $this->paid;
    }

    public function getUnitPriceMinor(): int
    {
        return $this->unitPriceMinor;
    }

    public function getApplicationCost(): int
    {
        return $this->applicationCost;
    }

    public function getCampaignCost(): int
    {
        return $this->campaignCost;
    }

    public function getWelcomeGrant(): int
    {
        return $this->welcomeGrant;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getActivation(): int
    {
        return $this->activation;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
