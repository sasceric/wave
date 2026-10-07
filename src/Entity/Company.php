<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $about = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $logoUrl;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Media $logoMedia = null;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Media $coverMedia = null;

    /** @var Collection<int, CompanyIndustry> */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: CompanyIndustry::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $industrySelections;

    #[ORM\Column]
    private bool $verified;

    #[ORM\Column(options: ['default' => false])]
    private bool $featured = false;

    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $translations;

    /** @var list<array{platform: string, url: string}> */
    #[ORM\Column(name: 'social_links', type: 'json', options: ['default' => '[]'])]
    private array $socialLinks = [];

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
        ?string $about = null,
    ) {
        $this->industrySelections = new ArrayCollection();
        $this->slug = $slug;
        $this->name = $name;
        $this->industry = $industry;
        $this->logoUrl = $logoUrl;
        $this->verified = $verified;
        $this->translations = $translations;
        $this->about = $about;
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

    public function getIndustries(): array
    {
        $values = $this->industrySelections->map(static fn (CompanyIndustry $industry): string => $industry->getValue())->toArray();
        return array_values(array_unique(array_filter([$this->industry, ...$values])));
    }

    public function setIndustries(array $values): void
    {
        $values = array_values(array_unique($values));
        if ($values === []) {
            throw new \InvalidArgumentException('At least one industry is required.');
        }
        foreach ($this->industrySelections->toArray() as $selection) {
            if (!in_array($selection->getValue(), $values, true)) {
                $this->industrySelections->removeElement($selection);
            }
        }
        $existing = $this->industrySelections->map(static fn (CompanyIndustry $industry): string => $industry->getValue())->toArray();
        foreach ($values as $value) {
            if (!in_array($value, $existing, true)) {
                $this->industrySelections->add(new CompanyIndustry($this, $value));
            }
        }
        $this->industry = $values[0];
        $this->translations = [];
    }

    public function getCoverMedia(): ?Media
    {
        return $this->coverMedia;
    }

    public function setCoverMedia(?Media $media): void
    {
        $this->coverMedia = $media;
    }

    public function getAbout(): ?string
    {
        return $this->about;
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

    /** @return list<array{platform: string, url: string}> */
    public function getSocialLinks(): array
    {
        return $this->socialLinks;
    }

    /** @param list<array{platform: string, url: string}> $socialLinks */
    public function setSocialLinks(array $socialLinks): void
    {
        $this->socialLinks = $socialLinks;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): void
    {
        $this->owner = $owner;
    }

    public function updateProfile(string $name, string $industry, ?string $logoUrl, ?string $about): void
    {
        $this->name = $name;
        $this->industry = $industry;
        $this->logoUrl = $logoUrl;
        $this->about = $about;
        $this->translations = [];
    }
}
