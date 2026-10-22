<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Daily aggregates only: no visitor identities, IP addresses or device fingerprints. */
#[ORM\Entity]
#[ORM\Table(name: 'creator_profile_visit')]
class CreatorProfileVisit
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Creator::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Creator $creator;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Column]
    private int $views = 0;

    public function __construct(Creator $creator, \DateTimeImmutable $day) { $this->creator = $creator; $this->day = $day; }
    public function getViews(): int { return $this->views; }
}
