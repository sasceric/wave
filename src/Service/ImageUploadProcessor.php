<?php

namespace App\Service;

final class ImageUploadProcessor
{
    private const MAX_WIDTH = 600;
    private const MAX_WEBP_DIMENSION = 16383;
    private const MAX_SOURCE_PIXELS = 64_000_000;

    public function writeWebp(string $source, string $destination, int $maxWidth = self::MAX_WIDTH, ?int $quality = null): int
    {
        if ($maxWidth < 1 || $maxWidth > self::MAX_WIDTH) {
            throw new \InvalidArgumentException('Unsupported image width.');
        }
        if ($quality !== null && ($quality < 1 || $quality > 100)) {
            throw new \InvalidArgumentException('Unsupported WebP quality.');
        }
        if (!function_exists('imagewebp') || !function_exists('exif_read_data')
            || !defined('IMG_WEBP_LOSSLESS') || !(gd_info()['WebP Support'] ?? false)
            || !(gd_info()['JPEG Support'] ?? false) || !(gd_info()['PNG Support'] ?? false)) {
            throw new \RuntimeException('Image uploads require EXIF and GD with JPEG, PNG and lossless WebP support.');
        }

        ['type' => $type, 'orientation' => $orientation, 'width' => $width, 'height' => $height] = $this->inspect($source);
        $targetWidth = min($maxWidth, $width);
        $targetHeight = max(1, (int) round($height * $targetWidth / $width));
        if ($targetHeight > self::MAX_WEBP_DIMENSION) {
            throw new \InvalidArgumentException('Image is too tall for WebP.');
        }

        // Compressed upload size does not bound decoded memory. Allow for rotation,
        // resampling buffers and codec overhead before allocating a GD image.
        $memoryLimit = ini_parse_quantity(ini_get('memory_limit'));
        $requiredMemory = $width * $height * 12 + $targetWidth * $targetHeight * 4 + 16 * 1024 * 1024;
        if ($memoryLimit > 0 && $requiredMemory > $memoryLimit - memory_get_usage(true)) {
            throw new \InvalidArgumentException('Image exceeds available processing memory.');
        }

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            default => throw new \InvalidArgumentException('Unsupported image type.'),
        };
        if (!$image instanceof \GdImage) {
            throw new \InvalidArgumentException('Image cannot be decoded.');
        }

        $written = false;
        try {
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $image = $this->orient($image, $orientation);
            if ($width > $maxWidth) {
                $resized = imagecreatetruecolor($targetWidth, $targetHeight);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                if (!imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
                    throw new \RuntimeException('Unable to resize image.');
                }
                $image = $resized;
            }

            if (!imagewebp($image, $destination, $quality ?? IMG_WEBP_LOSSLESS)) {
                throw new \RuntimeException('Unable to encode WebP image.');
            }
            clearstatcache(true, $destination);
            $fileSize = is_file($destination) ? filesize($destination) : false;
            if ($fileSize === false || $fileSize === 0) {
                throw new \RuntimeException('WebP encoder produced an empty image.');
            }
            $written = true;

            return $fileSize;
        } finally {
            unset($image, $resized);
            if (!$written && is_file($destination)) {
                unlink($destination);
            }
        }
    }

    /** @return array{width: int, height: int} */
    public function dimensions(string $source): array
    {
        $info = $this->inspect($source);

        return ['width' => $info['width'], 'height' => $info['height']];
    }

    /** @return array{width: int, height: int, type: int, orientation: int} */
    private function inspect(string $source): array
    {
        $info = @getimagesize($source);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new \InvalidArgumentException('Invalid image.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_SOURCE_PIXELS) {
            throw new \InvalidArgumentException('Image dimensions exceed processing limits.');
        }
        $orientation = $info[2] === IMAGETYPE_JPEG
            ? (int) ((@exif_read_data($source))['Orientation'] ?? 1)
            : 1;
        $rotated = in_array($orientation, [5, 6, 7, 8], true);

        return ['width' => $info[$rotated ? 1 : 0], 'height' => $info[$rotated ? 0 : 1], 'type' => $info[2], 'orientation' => $orientation];
    }

    private function orient(\GdImage $image, int $orientation): \GdImage
    {
        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if (!$rotated instanceof \GdImage) {
                throw new \RuntimeException('Unable to orient image.');
            }
            $image = $rotated;
        }
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        return $image;
    }
}
