<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'company')]
#[ORM\UniqueConstraint(name: 'uniq_company_slug', columns: ['slug'])]
class Company
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $slug;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 100)]
    private string $industry;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $logoUrl;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Media $logoMedia = null;

    #[ORM\Column]
    private bool $verified;

    #[ORM\Column(options: ['default' => false])]
    private bool $featured = false;

    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $translations;

    #[ORM\OneToOne(inversedBy: 'company', targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, unique: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    public function __construct(
        string $slug,
        string $name,
        string $industry,
        ?string $logoUrl = null,
        bool $verified = false,
        array $translations = [],
    ) {
        $this->slug = $slug;
        $this->name = $name;
        $this->industry = $industry;
        $this->logoUrl = $logoUrl;
        $this->verified = $verified;
        $this->translations = $translations;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getIndustry(): string
    {
        return $this->industry;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function getLogoMedia(): ?Media
    {
        return $this->logoMedia;
    }

    public function setLogoMedia(?Media $logoMedia): void
    {
        $this->logoMedia = $logoMedia;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): void
    {
        $this->featured = $featured;
    }

    public function getTranslations(): array
    {
        return $this->translations;
    }

    public function setTranslations(array $translations): void
    {
        $this->translations = $translations;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): void
    {
        $this->owner = $owner;
    }

    public function updateProfile(string $name, string $industry, ?string $logoUrl): void
    {
        $this->name = $name;
        $this->industry = $industry;
        $this->logoUrl = $logoUrl;
        $this->translations = [];
    }
}
