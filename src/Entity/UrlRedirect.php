<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'url_redirect')]
#[ORM\UniqueConstraint(name: 'uniq_url_redirect_source', columns: ['source_path'])]
class UrlRedirect
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private string $sourcePath;

    #[ORM\Column(length: 1000)]
    private string $targetPath;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private bool $preserveQuery = false;

    #[ORM\Column(length: 30)]
    private string $origin;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $sourcePath, string $targetPath, string $origin = 'manual')
    {
        $this->sourcePath = $sourcePath;
        $this->targetPath = $targetPath;
        $this->origin = $origin;
        $this->createdAt = $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSourcePath(): string { return $this->sourcePath; }
    public function getTargetPath(): string { return $this->targetPath; }
    public function isEnabled(): bool { return $this->enabled; }
    public function preservesQuery(): bool { return $this->preserveQuery; }
    public function getOrigin(): string { return $this->origin; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function update(string $sourcePath, string $targetPath, bool $enabled, bool $preserveQuery): void
    {
        $this->sourcePath = $sourcePath;
        $this->targetPath = $targetPath;
        $this->enabled = $enabled;
        $this->preserveQuery = $preserveQuery;
        $this->updatedAt = new DateTimeImmutable();
    }
}
