<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'idx_campaign_directory_order', columns: ['status', 'featured', 'closes_at', 'id'])]
#[ORM\Index(name: 'idx_campaign_company_available', columns: ['company_id', 'status', 'closes_at'])]
#[ORM\Table(name: 'campaign')]
#[ORM\UniqueConstraint(name: 'uniq_campaign_slug', columns: ['slug'])]
class Campaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $slug;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(length: 220)]
    private string $summary;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(length: 80)]
    private string $category;

    #[ORM\Column(type: 'json')]
    private array $channels;

    #[ORM\Column(type: 'json')]
    private array $deliverables;

    #[ORM\Column]
    private int $budgetMin;

    #[ORM\Column]
    private int $budgetMax;

    #[ORM\Column(length: 3, options: ['default' => 'BAM'])]
    private string $currency = 'BAM';

    #[ORM\Column(length: 120)]
    private string $city;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $countryCode = null;

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $categories = [];

    #[ORM\Column]
    private int $creatorCount;

    #[ORM\Column]
    private DateTimeImmutable $closesAt;

    #[ORM\Column]
    private DateTimeImmutable $publishedAt;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column]
    private bool $featured;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Media $coverMedia = null;

    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $translations;

    public function __construct(
        string $slug,
        string $title,
        string $summary,
        string $description,
        string $category,
        array $channels,
        array $deliverables,
        int $budgetMin,
        int $budgetMax,
        string $city,
        int $creatorCount,
        DateTimeImmutable $closesAt,
        DateTimeImmutable $publishedAt,
        Company $company,
        bool $featured = false,
        string $status = 'open',
        array $translations = [],
        ?Media $coverMedia = null,
        string $currency = 'BAM',
        ?string $countryCode = null,
        array $categories = [],
    ) {
        $this->slug = $slug;
        $this->countryCode = $countryCode;
        $this->categories = $categories !== [] ? array_values(array_unique($categories)) : [$category];
        $this->title = $title;
        $this->summary = $summary;
        $this->description = $description;
        $this->category = $category;
        $this->channels = $channels;
        $this->deliverables = $deliverables;
        $this->budgetMin = $budgetMin;
        $this->budgetMax = $budgetMax;
        $this->currency = $currency;
        $this->city = $city;
        $this->creatorCount = $creatorCount;
        $this->closesAt = $closesAt;
        $this->publishedAt = $publishedAt;
        $this->company = $company;
        $this->featured = $featured;
        $this->status = $status;
        $this->translations = $translations;
        $this->coverMedia = $coverMedia;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getChannels(): array
    {
        return $this->channels;
    }

    public function getDeliverables(): array
    {
        return $this->deliverables;
    }

    public function getBudgetMin(): int
    {
        return $this->budgetMin;
    }

    public function getBudgetMax(): int
    {
        return $this->budgetMax;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function getCategories(): array
    {
        return $this->categories !== [] ? $this->categories : [$this->category];
    }

    public function getCreatorCount(): int
    {
        return $this->creatorCount;
    }

    public function getClosesAt(): DateTimeImmutable
    {
        return $this->closesAt;
    }

    public function getPublishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): void
    {
        $this->featured = $featured;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getCoverMedia(): ?Media
    {
        return $this->coverMedia;
    }

    public function setCoverMedia(?Media $coverMedia): void
    {
        $this->coverMedia = $coverMedia;
    }

    public function getTranslations(): array
    {
        return $this->translations;
    }

    public function setTranslations(array $translations): void
    {
        $this->translations = $translations;
    }

    public function update(
        string $title,
        string $summary,
        string $description,
        string $category,
        array $channels,
        array $deliverables,
        int $budgetMin,
        int $budgetMax,
        string $city,
        int $creatorCount,
        DateTimeImmutable $closesAt,
        string $status,
        ?string $currency = null,
        ?string $countryCode = null,
        array $categories = [],
    ): void {
        $this->countryCode = $countryCode;
        $this->categories = $categories !== [] ? array_values(array_unique($categories)) : [$category];
        $this->title = $title;
        $this->summary = $summary;
        $this->description = $description;
        $this->category = $category;
        $this->channels = $channels;
        $this->deliverables = $deliverables;
        $this->budgetMin = $budgetMin;
        $this->budgetMax = $budgetMax;
        $this->city = $city;
        $this->creatorCount = $creatorCount;
        $this->closesAt = $closesAt;
        $this->status = $status;
        if ($currency !== null) {
            $this->currency = $currency;
        }
        $this->translations = [];
    }
}
