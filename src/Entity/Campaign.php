<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'idx_campaign_directory_order', columns: ['status', 'featured', 'closes_at', 'id'])]
#[ORM\Index(name: 'idx_campaign_directory_latest', columns: ['status', 'featured', 'published_at', 'id'])]
#[ORM\Index(name: 'idx_campaign_company_available', columns: ['company_id', 'status', 'closes_at'])]
#[ORM\Table(name: 'campaign')]
#[ORM\UniqueConstraint(name: 'uniq_campaign_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_campaign_presentation_demo_key', columns: ['presentation_demo_key'])]
class Campaign
{
    public const COMPENSATION_PAID = 'paid';
    public const COMPENSATION_VOLUNTARY = 'voluntary';

    #[ORM\Column(length: 16, options: ['default' => 'paid'])]
    private string $compensationType = self::COMPENSATION_PAID;

    public function getCompensationType(): string { return $this->compensationType; }
    public function isVoluntary(): bool { return $this->compensationType === self::COMPENSATION_VOLUNTARY; }
    public function setCompensationType(string $type): void
    {
        if (!in_array($type, [self::COMPENSATION_PAID, self::COMPENSATION_VOLUNTARY], true)) {
            throw new \InvalidArgumentException('Invalid campaign compensation type.');
        }
        $this->compensationType = $type;
    }
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $presentationDemoKey = null;

    public function getPresentationDemoKey(): ?string { return $this->presentationDemoKey; }
    public function markPresentationDemo(string $key): void { $this->presentationDemoKey = $key; }


    #[ORM\Column(length: 120)]
    private string $slug;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publicUrlChangedAt = null;

    public function getPublicUrlChangedAt(): ?\DateTimeImmutable { return $this->publicUrlChangedAt; }
    public function changePublicSlug(string $slug, ?\DateTimeImmutable $at = null): void
    {
        $this->slug = $slug;
        $this->publicUrlChangedAt = $at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }


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

    // Updated atomically on submission, never overwritten by stale profile edits.
    #[ORM\Column(options: ['default' => 0], updatable: false)]
    private int $applicationCount = 0;

    #[ORM\Column]
    private DateTimeImmutable $closesAt;

    #[ORM\Column]
    private DateTimeImmutable $publishedAt;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

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
        string $compensationType = self::COMPENSATION_PAID,
    ) {
        $this->setCompensationType($compensationType);
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
        if ($status === 'finished') {
            $this->finishedAt = new DateTimeImmutable();
        }
        $this->translations = $translations;
        $this->coverMedia = $coverMedia;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cancellationReason = null;

    public function getCancellationReason(): ?string { return $this->cancellationReason; }

    public function setCancellationReason(string $reason): void { $this->cancellationReason = $reason; }

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

    public function getApplicationCount(): int
    {
        return $this->applicationCount;
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
        if ($this->status === 'cancelled' && $status !== 'cancelled') { throw new \InvalidArgumentException('Cancelled campaigns cannot be reopened.'); }
        if ($this->finishedAt !== null && $status !== 'finished') {
            throw new \InvalidArgumentException('Finished campaigns cannot be reopened.');
        }
        if ($status === 'finished') {
            $this->finishedAt ??= new DateTimeImmutable();
        }
        $this->status = $status;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
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
        ?string $compensationType = null,
    ): void {
        if ($compensationType !== null) $this->setCompensationType($compensationType);
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
        $this->setStatus($status);
        if ($currency !== null) {
            $this->currency = $currency;
        }
        $this->translations = [];
    }
}
