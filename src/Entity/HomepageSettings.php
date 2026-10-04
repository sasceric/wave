<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'homepage_settings')]
class HomepageSettings
{
    public const MODE_LATEST = 'latest';
    public const MODE_FEATURED = 'featured';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, options: ['default' => self::MODE_LATEST])]
    private string $creatorMode = self::MODE_LATEST;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatorMode(): string
    {
        return $this->creatorMode;
    }

    public function setCreatorMode(string $creatorMode): void
    {
        $this->creatorMode = $creatorMode;
    }
}
