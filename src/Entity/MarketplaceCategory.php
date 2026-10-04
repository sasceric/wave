<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'marketplace_category')]
#[ORM\UniqueConstraint(name: 'uniq_marketplace_category_value', columns: ['value'])]
class MarketplaceCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $value;

    #[ORM\Column(type: 'json')]
    private array $labels;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(string $value, array $labels, int $position)
    {
        $this->value = $value;
        $this->labels = $labels;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getLabels(): array
    {
        return $this->labels;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function update(array $labels, int $position, bool $active): void
    {
        $this->labels = $labels;
        $this->position = $position;
        $this->active = $active;
    }

    public function label(string $locale): string
    {
        return $this->labels[$locale] ?? $this->labels['bs'] ?? $this->value;
    }
}
