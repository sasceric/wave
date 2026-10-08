<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'idx_creator_directory_name', columns: ['display_name', 'id'])]
#[ORM\Table(name: 'creator')]
#[ORM\UniqueConstraint(name: 'uniq_creator_slug', columns: ['slug'])]
class Creator
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $slug;

    #[ORM\Column(length: 120)]
    private string $displayName;

    #[ORM\Column(length: 80)]
    private string $category;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $birthday = null;

    #[ORM\Column(type: 'text')]
    private string $bio;

    #[ORM\Column(length: 180, options: ['default' => ''])]
    private string $tagline = '';

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $avatarUrl;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Media $avatarMedia = null;

    /**
     * @var Collection<int, CreatorPortfolioMedia>
     */
    #[ORM\OneToMany(mappedBy: 'creator', targetEntity: CreatorPortfolioMedia::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $portfolioMedia;

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $portfolio = [];

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $packages = [];

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $categories = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $creatorTypes = [];

    #[ORM\Column(options: ['default' => false])]
    private bool $featured = false;

    #[ORM\Column(options: ['default' => '1970-01-01 00:00:00'])]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $faqs = [];

    #[ORM\Column(type: 'json')]
    private array $socialProfiles;

    #[ORM\Column(type: 'json')]
    private array $tags;

    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $translations;

    #[ORM\OneToOne(inversedBy: 'creator', targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, unique: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    public function __construct(
        string $slug,
        string $displayName,
        string $category,
        string $city,
        string $bio,
        array $socialProfiles,
        array $tags = [],
        ?string $avatarUrl = null,
        array $translations = [],
        string $tagline = '',
        array $portfolio = [],
        array $packages = [],
        array $categories = [],
        array $faqs = [],
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->slug = $slug;
        $this->displayName = $displayName;
        $this->category = $category;
        $this->city = $this->owner === null ? $city : null;
        $this->bio = $bio;
        $this->socialProfiles = $socialProfiles;
        $this->tags = $tags;
        $this->avatarUrl = $avatarUrl;
        $this->translations = $translations;
        $this->tagline = $tagline;
        $this->portfolio = $portfolio;
        $this->packages = $packages;
        $this->categories = $categories;
        $this->faqs = $faqs;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
        $this->portfolioMedia = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    /** @return list<string> */
    public function getCreatorTypes(): array
    {
        return $this->creatorTypes;
    }

    /** @param list<string> $creatorTypes */
    public function setCreatorTypes(array $creatorTypes): void
    {
        $this->creatorTypes = $creatorTypes;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): void
    {
        $this->featured = $featured;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCity(): string
    {
        return $this->owner?->getCity() ?? $this->city ?? '';
    }

    public function getBio(): string
    {
        return $this->bio;
    }

    public function getBirthday(): ?DateTimeImmutable
    {
        return $this->birthday;
    }

    public function setBirthday(?DateTimeImmutable $birthday): void
    {
        $this->birthday = $birthday;
    }

    public function setBio(string $bio): void
    {
        $this->bio = $bio;
    }

    public function getTagline(): string
    {
        return $this->tagline;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function getAvatarMedia(): ?Media
    {
        return $this->avatarMedia;
    }

    public function setAvatarMedia(?Media $avatarMedia): void
    {
        $this->avatarMedia = $avatarMedia;
    }

    /**
     * @return Collection<int, CreatorPortfolioMedia>
     */
    public function getPortfolioMedia(): Collection
    {
        return $this->portfolioMedia;
    }

    public function addPortfolioMedia(CreatorPortfolioMedia $portfolioMedia): void
    {
        if (!$this->portfolioMedia->contains($portfolioMedia)) {
            $this->portfolioMedia->add($portfolioMedia);
        }
    }

    public function clearPortfolioMedia(): void
    {
        foreach ($this->portfolioMedia as $portfolioMedia) {
            $this->portfolioMedia->removeElement($portfolioMedia);
        }
    }

    public function getPortfolio(): array
    {
        return $this->portfolio;
    }

    public function getPackages(): array
    {
        return $this->packages;
    }

    public function getCategories(): array
    {
        return $this->categories !== [] ? $this->categories : ($this->category !== '' ? [$this->category] : []);
    }

    public function hasStoredCategories(): bool
    {
        return $this->categories !== [];
    }

    public function setCategories(array $categories): void
    {
        $this->categories = $categories;
    }

    public function getFaqs(): array
    {
        return $this->faqs;
    }

    public function setFaqs(array $faqs): void
    {
        $this->faqs = $faqs;
    }

    public function setTagline(string $tagline): void
    {
        $this->tagline = $tagline;
    }

    public function setPortfolio(array $portfolio): void
    {
        $this->portfolio = $portfolio;
    }

    public function setPackages(array $packages): void
    {
        $this->packages = $packages;
    }

    public function getSocialProfiles(): array
    {
        return $this->socialProfiles;
    }

    public function getTags(): array
    {
        return $this->tags;
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
        if ($owner !== null && !$owner->getCity() && $this->city) {
            $owner->setCity(mb_substr($this->city, 0, 70));
        }
        $this->owner = $owner;
        if ($owner !== null) {
            $this->city = null;
        }
    }

    public function updateProfile(
        string $displayName,
        string $category,
        string $city,
        string $bio,
        array $socialProfiles,
        array $tags,
        ?string $avatarUrl,
        string $tagline,
        array $portfolio,
        array $packages,
        array $categories,
        array $faqs,
    ): void {
        $this->displayName = $displayName;
        $this->category = $categories[0] ?? $category;
        $this->categories = $categories;
        $this->city = $this->owner === null ? $city : null;
        $this->bio = $bio;
        $this->socialProfiles = $socialProfiles;
        $this->tags = $tags;
        $this->avatarUrl = $avatarUrl;
        $this->tagline = $tagline;
        $this->portfolio = $portfolio;
        $this->packages = $packages;
        $this->faqs = $faqs;
        $this->translations = [];
    }
}
