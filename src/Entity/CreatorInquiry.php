<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'creator_inquiry')]
#[ORM\Index(name: 'idx_creator_inquiry_creator_status', columns: ['creator_id', 'status'])]
#[ORM\Index(name: 'idx_creator_inquiry_company_status', columns: ['company_id', 'status'])]
class CreatorInquiry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Creator::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Creator $creator;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $packageId;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $packageTitle;

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $selectedPackages = [];

    #[ORM\Column(nullable: true)]
    private ?int $listedPrice;

    #[ORM\Column(length: 3, options: ['default' => 'BAM'])]
    private string $listedPriceCurrency = 'BAM';

    #[ORM\Column(nullable: true)]
    private ?int $proposedAmount;

    #[ORM\Column(length: 3, options: ['default' => 'BAM'])]
    private string $currency = 'BAM';

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $respondedAt = null;

    public function __construct(
        Creator $creator,
        Company $company,
        ?string $packageId,
        ?string $packageTitle,
        ?int $listedPrice,
        ?int $proposedAmount,
        string $message,
        string $currency = 'BAM',
        string $listedPriceCurrency = 'BAM',
        array $selectedPackages = [],
    ) {
        $this->creator = $creator;
        $this->company = $company;
        $this->packageId = $packageId;
        $this->packageTitle = $packageTitle;
        $this->selectedPackages = $selectedPackages;
        $this->listedPrice = $listedPrice;
        $this->listedPriceCurrency = $listedPriceCurrency;
        $this->proposedAmount = $proposedAmount;
        $this->currency = $currency;
        $this->message = $message;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreator(): Creator
    {
        return $this->creator;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getPackageId(): ?string
    {
        return $this->packageId;
    }

    public function getPackageTitle(): ?string
    {
        return $this->packageTitle;
    }

    public function getSelectedPackages(): array
    {
        return $this->selectedPackages;
    }

    public function getListedPrice(): ?int
    {
        return $this->listedPrice;
    }

    public function getListedPriceCurrency(): string
    {
        return $this->listedPriceCurrency;
    }

    public function getProposedAmount(): ?int
    {
        return $this->proposedAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRespondedAt(): ?DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function respond(string $status): void
    {
        $this->status = $status;
        $this->respondedAt = new DateTimeImmutable();
    }
}
