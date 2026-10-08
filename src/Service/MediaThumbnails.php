<?php

namespace App\Service;

use App\Background\JobDispatcher;
use App\Entity\Media;
use App\Media\ImageVariants;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final class MediaThumbnails
{
    public function __construct(
        private readonly string $storagePath,
        private readonly Filesystem $filesystem,
        private readonly ImageUploadProcessor $processor,
        private readonly ?JobDispatcher $jobs = null,
        #[Autowire('%wave.queue.enabled%')] private readonly bool $queued = false,
    ) {
    }

    public function path(Media $media, int $width): string
    {
        $this->assertWidth($width);
        $path = $this->directory($media->getStoragePath()) . '/' . $width . '.webp';
        if (!is_file($this->storagePath . '/' . $media->getStoragePath())) {
            throw new \InvalidArgumentException('Media source does not exist.');
        }
        if (!is_file($path) && $this->queued && $this->jobs !== null) {
            $this->jobs->enqueue('GenerateThumbnailsMessage', ['mediaId' => $media->getId()], 'thumbnail:' . ImageVariants::VERSION . ':' . $media->getId() . ':' . intdiv(time(), 300));

            return $this->storagePath . '/' . $media->getStoragePath();
        }
        if (!is_file($path)) {
            $this->generate($media->getStoragePath(), [$width]);
        }
        clearstatcache(true, $path);

        return $path;
    }

    /** @return array{width: int, height: int} */
    public function warm(string $relativePath, bool $force = false): array
    {
        $dimensions = $this->processor->dimensions($this->storagePath . '/' . $relativePath);
        $this->generate($relativePath, ImageVariants::WIDTHS, $force);

        return $dimensions;
    }

    public function complete(string $relativePath): bool
    {
        foreach (ImageVariants::WIDTHS as $width) {
            if (!is_file($this->directory($relativePath) . '/' . $width . '.webp')) {
                return false;
            }
        }

        return true;
    }

    public function remove(string $relativePath): void
    {
        // Remove all versioned derivatives when their owning media is deleted.
        foreach (glob($this->storagePath . '/thumbnails/*/' . hash('sha256', $relativePath), GLOB_ONLYDIR) ?: [] as $directory) {
            $this->filesystem->remove($directory);
        }
    }

    private function generate(string $relativePath, array $widths, bool $force = false): void
    {
        $directory = $this->directory($relativePath);
        $this->filesystem->mkdir($directory);
        $lock = fopen($directory . '/.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open thumbnail generation lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock thumbnail generation.');
            }
            foreach ($widths as $width) {
                $this->assertWidth($width);
                $destination = $directory . '/' . $width . '.webp';
                clearstatcache(true, $destination);
                if (!$force && is_file($destination)) {
                    continue;
                }
                $temporary = tempnam($directory, '.generating-');
                if ($temporary === false) {
                    throw new \RuntimeException('Unable to allocate thumbnail file.');
                }
                try {
                    $this->processor->writeWebp($this->storagePath . '/' . $relativePath, $temporary, $width, 82);
                    $this->filesystem->chmod($temporary, 0644);
                    $this->filesystem->rename($temporary, $destination, true);
                } finally {
                    $this->filesystem->remove($temporary);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function directory(string $relativePath): string
    {
        return $this->storagePath . '/thumbnails/' . ImageVariants::VERSION . '/' . hash('sha256', $relativePath);
    }

    private function assertWidth(int $width): void
    {
        if (!in_array($width, ImageVariants::WIDTHS, true)) {
            throw new \InvalidArgumentException('Unsupported thumbnail width.');
        }
    }
}
