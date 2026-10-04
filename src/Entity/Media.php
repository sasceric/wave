<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'media')]
#[ORM\UniqueConstraint(name: 'uniq_media_storage_path', columns: ['storage_path'])]
#[ORM\Index(name: 'idx_media_owner_folder', columns: ['owner_id', 'folder_id'])]
class Media
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MediaFolder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private MediaFolder $folder;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 500)]
    private string $storagePath;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $fileSize;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(
        MediaFolder $folder,
        User $owner,
        string $originalName,
        string $storagePath,
        string $mimeType,
        int $fileSize,
    ) {
        $this->folder = $folder;
        $this->owner = $owner;
        $this->originalName = $originalName;
        $this->storagePath = $storagePath;
        $this->mimeType = $mimeType;
        $this->fileSize = $fileSize;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFolder(): MediaFolder
    {
        return $this->folder;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUrl(): string
    {
        return '/api/media/'.$this->id.'/file';
    }
}
