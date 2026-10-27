<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'listing_ad')]
#[ORM\Index(name: 'idx_listing_ad_active', columns: ['kind', 'enabled'])]
#[ORM\Index(name: 'idx_listing_ad_image', columns: ['image_id'])]
class ListingAd
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $kind;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $content = [];

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Media $image = null;

    public function __construct(string $kind)
    {
        $this->kind = $kind;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /** @return array<string, mixed> */
    public function getContent(): array
    {
        return $this->content;
    }

    /** @param array<string, mixed> $content */
    public function setContent(array $content): void
    {
        $this->content = $content;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getImage(): ?Media
    {
        return $this->image;
    }

    public function setImage(?Media $image): void
    {
        $this->image = $image;
    }

}
