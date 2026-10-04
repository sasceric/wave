<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'creator_portfolio_media')]
#[ORM\UniqueConstraint(name: 'uniq_creator_portfolio_position', columns: ['creator_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_creator_portfolio_media', columns: ['creator_id', 'media_id'])]
class CreatorPortfolioMedia
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Creator::class, inversedBy: 'portfolioMedia')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Creator $creator;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Media $media;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: 120)]
    private string $title;

    #[ORM\Column(length: 30)]
    private string $platform;

    public function __construct(Creator $creator, Media $media, int $position, string $title, string $platform)
    {
        $this->creator = $creator;
        $this->media = $media;
        $this->position = $position;
        $this->title = $title;
        $this->platform = $platform;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }
}
