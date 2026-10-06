<?php

namespace App\Service;

use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MediaStorage
{
    private const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly string $storagePath,
        private readonly Filesystem $filesystem,
        private readonly ImageUploadProcessor $imageProcessor,
        private readonly MediaThumbnails $thumbnails,
        #[Autowire('%wave.queue.enabled%')] private readonly bool $queued = false,
    ) {
    }

    public function supports(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), self::MIME_TYPES, true);
    }

    public function store(UploadedFile $file, MediaFolder $folder, User $owner): array
    {
        if (!$this->supports($file)) {
            throw new \InvalidArgumentException('Unsupported media type.');
        }

        $now = new \DateTimeImmutable();
        $relativePath = sprintf(
            '%s/%d/%s/%s/%s.webp',
            $folder->getSlug(),
            $owner->getId(),
            $now->format('Y'),
            $now->format('m'),
            bin2hex(random_bytes(16)),
        );
        $absoluteDirectory = $this->storagePath . '/' . dirname($relativePath);
        $this->filesystem->mkdir($absoluteDirectory);
        try {
            $fileSize = $this->imageProcessor->writeWebp($file->getPathname(), $this->absolutePath($relativePath));
            $dimensions = $this->queued ? $this->imageProcessor->dimensions($this->absolutePath($relativePath)) : $this->thumbnails->warm($relativePath);
        } catch (\Throwable $exception) {
            $this->filesystem->remove($this->absolutePath($relativePath));
            $this->thumbnails->remove($relativePath);
            throw $exception;
        }

        return [
            'path' => $relativePath,
            'mimeType' => 'image/webp',
            'fileSize' => $fileSize,
            ...$dimensions,
        ];
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->storagePath . '/' . $relativePath;
    }

    public function remove(Media $media): void
    {
        $this->filesystem->remove($this->absolutePath($media->getStoragePath()));
        $this->thumbnails->remove($media->getStoragePath());
    }
}
