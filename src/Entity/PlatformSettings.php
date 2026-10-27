<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Singleton mapping; MaintenanceMode performs atomic updates. */
#[ORM\Entity]
#[ORM\Table(name: 'platform_settings')]
class PlatformSettings
{
    #[ORM\Id, ORM\Column]
    private int $id = 1;

    #[ORM\Column(options: ['default' => false])]
    private bool $maintenanceEnabled = false;

    public function getId(): int { return $this->id; }
    public function isMaintenanceEnabled(): bool { return $this->maintenanceEnabled; }
}
