<?php

namespace App\Tests\Service;

use App\Api\MediaImageResource;
use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\User;
use App\Media\ImageVariants;
use App\Service\ImageUploadProcessor;
use App\Service\MediaThumbnails;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class MediaThumbnailsTest extends TestCase
{
    private string $directory;
    private MediaThumbnails $thumbnails;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/wave-thumbnails-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory);
        $this->thumbnails = new MediaThumbnails($this->directory, $filesystem, new ImageUploadProcessor());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testExistingLargeImagesHaveUncroppedCachedVariantsWithoutChangingTheirSource(): void
    {
        $image = imagecreatetruecolor(1200, 800);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 70, 90, 130, 63));
        imagepng($image, $this->directory.'/original.png');
        $sourceHash = hash_file('sha256', $this->directory.'/original.png');
        $media = $this->media('original.png');
        $dimensions = $this->thumbnails->warm($media->getStoragePath());
        self::assertSame(['width' => 1200, 'height' => 800], $dimensions);
        foreach (ImageVariants::WIDTHS as $width) {
            $path = $this->thumbnails->path($media, $width);
            self::assertSame([$width, (int) round(800 * $width / 1200), IMAGETYPE_WEBP], array_slice(getimagesize($path), 0, 3));
            $decoded = imagecreatefromwebp($path);
            self::assertSame(63, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha']);
            touch($path, 946684800);
        }
        $this->thumbnails->warm($media->getStoragePath());
        foreach (ImageVariants::WIDTHS as $width) {
            $path = $this->thumbnails->path($media, $width);
            clearstatcache(true, $path);
            self::assertSame(946684800, filemtime($path), 'Existing variants must not be regenerated.');
        }
        self::assertSame($sourceHash, hash_file('sha256', $this->directory.'/original.png'));
        $path = $this->thumbnails->path($media, 320);
        unlink($path);
        self::assertFileExists($this->thumbnails->path($media, 320));
        self::assertSame([], glob(dirname($path).'/.generating-*'));
        $this->thumbnails->warm($media->getStoragePath(), true);
        clearstatcache(true, $path);
        self::assertGreaterThan(946684800, filemtime($path));
    }

    public function testSmallImagesAreNotUpscaledAndResponsiveDescriptorsMatchActualWidths(): void
    {
        imagepng(imagecreatetruecolor(80, 120), $this->directory.'/small.png');
        $media = $this->media('small.png');
        $dimensions = $this->thumbnails->warm($media->getStoragePath());
        $media->setDimensions($dimensions['width'], $dimensions['height']);
        foreach (ImageVariants::WIDTHS as $width) {
            self::assertSame([80, 120], array_slice(getimagesize($this->thumbnails->path($media, $width)), 0, 2));
        }
        $resource = MediaImageResource::fromEntity($media);
        self::assertSame('/api/media/42/thumbnail/v2/320', $resource['src']);
        self::assertSame('/api/media/42/thumbnail/v2/96 80w', $resource['srcset']);
        self::assertSame(80, $resource['width']);
        self::assertSame(120, $resource['height']);
    }

    public function testDeletingMediaClearsEveryDerivativeVersionAndOnlyItsOwnFiles(): void
    {
        imagepng(imagecreatetruecolor(80, 120), $this->directory.'/delete.png');
        imagepng(imagecreatetruecolor(80, 120), $this->directory.'/keep.png');
        $delete = $this->media('delete.png');
        $keep = $this->media('keep.png');
        $this->thumbnails->warm('delete.png');
        $this->thumbnails->warm('keep.png');
        $oldDirectory = $this->directory.'/thumbnails/v0/'.hash('sha256', 'delete.png');
        (new Filesystem())->mkdir($oldDirectory);
        file_put_contents($oldDirectory.'/320.webp', 'old derivative');
        $path = $this->thumbnails->path($delete, 320);
        $keepPath = $this->thumbnails->path($keep, 320);
        $this->thumbnails->remove('delete.png');
        self::assertDirectoryDoesNotExist(dirname($path));
        self::assertDirectoryDoesNotExist($oldDirectory);
        self::assertFileExists($keepPath);
        self::assertFileExists($this->directory.'/delete.png');
    }

    public function testArbitraryThumbnailSizesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->thumbnails->path($this->media('image.png'), 10000);
    }

    public function testPhotographicDerivativesAreCompressedWithoutReplacingTheLosslessMaster(): void
    {
        $image = imagecreatetruecolor(600, 400);
        for ($y = 0; $y < 400; ++$y) {
            for ($x = 0; $x < 600; ++$x) {
                imagesetpixel($image, $x, $y, (($x * 7 + $y) % 256) << 16 | (($y * 3 + $x) % 256) << 8 | (($x + $y * 5) % 256));
            }
        }
        imagewebp($image, $this->directory.'/photo.webp', IMG_WEBP_LOSSLESS);
        $hash = hash_file('sha256', $this->directory.'/photo.webp');
        $this->thumbnails->warm('photo.webp');
        (new ImageUploadProcessor())->writeWebp($this->directory.'/photo.webp', $this->directory.'/lossless.webp', 320);
        $thumbnail = $this->thumbnails->path($this->media('photo.webp'), 320);
        self::assertLessThan(filesize($this->directory.'/lossless.webp'), filesize($thumbnail));
        self::assertSame($hash, hash_file('sha256', $this->directory.'/photo.webp'));
        self::assertSame([320, 213], array_slice(getimagesize($thumbnail), 0, 2));
    }

    private function media(string $path): Media
    {
        $media = new Media(new MediaFolder('creator-avatar', 'Avatars'), new User('thumb@example.test', 'ROLE_CREATOR'), $path, $path, 'image/png', 100);
        (new \ReflectionProperty(Media::class, 'id'))->setValue($media, 42);

        return $media;
    }
}
