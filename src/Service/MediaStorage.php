<?php

namespace App\Service;

use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\User;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MediaStorage
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly string $storagePath,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function supports(UploadedFile $file): bool
    {
        return isset(self::MIME_EXTENSIONS[$file->getMimeType() ?? '']);
    }

    public function store(UploadedFile $file, MediaFolder $folder, User $owner): array
    {
        $mimeType = $file->getMimeType();
        $extension = self::MIME_EXTENSIONS[$mimeType ?? ''] ?? null;
        $fileSize = $file->getSize();
        if ($extension === null || $fileSize === null) {
            throw new \InvalidArgumentException('Unsupported media type.');
        }

        $now = new \DateTimeImmutable();
        $relativePath = sprintf(
            '%s/%d/%s/%s/%s.%s',
            $folder->getSlug(),
            $owner->getId(),
            $now->format('Y'),
            $now->format('m'),
            bin2hex(random_bytes(16)),
            $extension,
        );
        $absoluteDirectory = $this->storagePath.'/'.dirname($relativePath);
        $this->filesystem->mkdir($absoluteDirectory);
        $file->move($absoluteDirectory, basename($relativePath));

        return [
            'path' => $relativePath,
            'mimeType' => $mimeType,
            'fileSize' => $fileSize,
        ];
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->storagePath.'/'.$relativePath;
    }

    public function remove(Media $media): void
    {
        $this->filesystem->remove($this->absolutePath($media->getStoragePath()));
    }
}
