<?php

namespace App\Tests\Service;

use App\Entity\MediaFolder;
use App\Entity\User;
use App\Service\ImageUploadProcessor;
use App\Service\MediaStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MediaStorageTest extends TestCase
{
    private string $directory;
    private MediaStorage $storage;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/wave-image-test-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory);
        $this->storage = new MediaStorage($this->directory.'/media', $filesystem, new ImageUploadProcessor());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public static function imageSizes(): iterable
    {
        yield 'landscape JPEG' => ['jpeg', 1200, 800, 600, 400];
        yield 'portrait PNG' => ['png', 800, 1200, 600, 900];
        yield 'panorama WebP' => ['webp', 1200, 300, 600, 150];
        yield 'small JPEG' => ['jpeg', 120, 80, 120, 80];
        yield 'small PNG' => ['png', 80, 120, 80, 120];
        yield 'small WebP' => ['webp', 120, 80, 120, 80];
        yield 'at maximum width' => ['png', 600, 400, 600, 400];
        yield 'fractional height' => ['png', 1000, 333, 600, 200];
    }

    #[DataProvider('imageSizes')]
    public function testImagesKeepTheirWholeFrameAndAspectRatio(
        string $format, int $width, int $height, int $expectedWidth, int $expectedHeight,
    ): void {
        $source = $this->quadrants($width, $height);
        $path = $this->directory.'/source.'.$format;
        match ($format) {
            'jpeg' => imagejpeg($source, $path, 95),
            'png' => imagepng($source, $path),
            'webp' => imagewebp($source, $path, IMG_WEBP_LOSSLESS),
        };
        $stored = $this->store($path);
        $outputPath = $this->storage->absolutePath($stored['path']);
        $info = getimagesize($outputPath);
        self::assertSame([$expectedWidth, $expectedHeight, IMAGETYPE_WEBP], array_slice($info, 0, 3));
        self::assertSame('image/webp', $stored['mimeType']);
        self::assertSame(filesize($outputPath), $stored['fileSize']);
        self::assertStringEndsWith('.webp', $stored['path']);
        $output = imagecreatefromwebp($outputPath);
        $this->assertQuadrants($output, ['red', 'green', 'blue', 'yellow']);

        // Without resizing, WebP must preserve every decoded RGB pixel, including
        // JPEG's existing compression artifacts, with no further encoding loss.
        if ($width <= 600) {
            $decoded = imagecreatefromstring(file_get_contents($path));
            for ($y = 0; $y < $height; ++$y) {
                for ($x = 0; $x < $width; ++$x) {
                    self::assertSame(imagecolorat($decoded, $x, $y), imagecolorat($output, $x, $y));
                }
            }
        }
    }

    public function testPaletteTransparencyAndPartialAlphaSurviveConversionAndResizing(): void
    {
        foreach ([80, 800] as $width) {
            $source = imagecreate($width, 400);
            $transparent = imagecolorallocatealpha($source, 0, 0, 0, 127);
            $partial = imagecolorallocatealpha($source, 120, 80, 40, 63);
            imagecolortransparent($source, $transparent);
            imagefilledrectangle($source, (int) ($width / 2), 0, $width - 1, 399, $partial);
            $path = $this->directory.'/alpha.png';
            imagepng($source, $path);
            $stored = $this->store($path);
            $output = imagecreatefromwebp($this->storage->absolutePath($stored['path']));
            $left = imagecolorsforindex($output, imagecolorat($output, 1, 1));
            $right = imagecolorsforindex($output, imagecolorat($output, imagesx($output) - 2, 1));
            self::assertSame(127, $left['alpha']);
            self::assertSame(['red' => 120, 'green' => 80, 'blue' => 40, 'alpha' => 63], $right);
        }
    }

    public static function orientations(): iterable
    {
        yield [1, ['red', 'green', 'blue', 'yellow']];
        yield [2, ['green', 'red', 'yellow', 'blue']];
        yield [3, ['yellow', 'blue', 'green', 'red']];
        yield [4, ['blue', 'yellow', 'red', 'green']];
        yield [5, ['red', 'blue', 'green', 'yellow']];
        yield [6, ['blue', 'red', 'yellow', 'green']];
        yield [7, ['yellow', 'green', 'blue', 'red']];
        yield [8, ['green', 'yellow', 'red', 'blue']];
    }

    #[DataProvider('orientations')]
    public function testJpegExifOrientationIsAppliedBeforeResizing(int $orientation, array $colors): void
    {
        $path = $this->directory.'/phone.jpg';
        imagejpeg($this->quadrants(1200, 800), $path, 95);
        $jpeg = file_get_contents($path);
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVVV', 0x112, 3, 1, $orientation, 0);
        file_put_contents($path, substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
        $stored = $this->store($path);
        $output = imagecreatefromwebp($this->storage->absolutePath($stored['path']));
        self::assertSame(600, imagesx($output));
        self::assertSame($orientation >= 5 ? 900 : 400, imagesy($output));
        $this->assertQuadrants($output, $colors);
    }

    public function testBrokenImagesAreRejectedWithoutLeavingAnOutputFile(): void
    {
        $path = $this->directory.'/broken.png';
        imagepng($this->quadrants(80, 40), $path);
        file_put_contents($path, substr(file_get_contents($path), 0, 33));
        self::assertNotFalse(getimagesize($path));
        try {
            $this->store($path);
            self::fail('A MIME-valid but undecodable upload must be rejected.');
        } catch (\InvalidArgumentException) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory));
            foreach ($files as $file) {
                self::assertFalse($file->isFile() && $file->getExtension() === 'webp');
            }
        }
    }

    public static function unsupportedDimensions(): iterable
    {
        yield 'pixel limit' => [100000, 100000, 'processing limits'];
        yield 'WebP height limit' => [600, 20000, 'too tall'];
        yield 'zero width' => [0, 40, 'processing limits'];
    }

    #[DataProvider('unsupportedDimensions')]
    public function testImpossibleImagesAreRejectedBeforeDecoding(int $width, int $height, string $reason): void
    {
        $path = $this->directory.'/invalid-dimensions.png';
        imagepng($this->quadrants(80, 40), $path);
        file_put_contents($path, substr_replace(file_get_contents($path), pack('NN', $width, $height), 16, 8));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);
        $this->store($path);
    }

    public function testExcessiveDecodedMemoryIsRejectedBeforeAllocation(): void
    {
        $path = $this->directory.'/huge.png';
        imagepng($this->quadrants(80, 40), $path);
        $bytes = file_get_contents($path);
        file_put_contents($path, substr_replace($bytes, pack('NN', 8000, 6000), 16, 8));
        $previousLimit = ini_set('memory_limit', '128M');
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('processing memory');
            $this->store($path);
        } finally {
            ini_set('memory_limit', $previousLimit);
        }
    }

    private function store(string $path): array
    {
        $owner = new User('media-test@example.test', 'ROLE_CREATOR');
        (new \ReflectionProperty(User::class, 'id'))->setValue($owner, 42);

        return $this->storage->store(
            new UploadedFile($path, basename($path), null, null, true),
            new MediaFolder('creator-portfolio', 'Portfolio'),
            $owner,
        );
    }

    private function quadrants(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        foreach ([[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 255, 0]] as $index => $rgb) {
            $x = ($index % 2) * (int) ($width / 2);
            $y = (int) ($index / 2) * (int) ($height / 2);
            imagefilledrectangle($image, $x, $y, $x + (int) ceil($width / 2) - 1, $y + (int) ceil($height / 2) - 1, imagecolorallocate($image, ...$rgb));
        }

        return $image;
    }

    private function assertQuadrants(\GdImage $image, array $expected): void
    {
        $palette = ['red' => [255, 0, 0], 'green' => [0, 255, 0], 'blue' => [0, 0, 255], 'yellow' => [255, 255, 0]];
        foreach ($expected as $index => $color) {
            $pixel = imagecolorsforindex($image, imagecolorat($image, (int) (imagesx($image) * ($index % 2 ? 0.75 : 0.25)), (int) (imagesy($image) * ($index < 2 ? 0.25 : 0.75))));
            foreach (['red', 'green', 'blue'] as $channel => $name) {
                self::assertEqualsWithDelta($palette[$color][$channel], $pixel[$name], 2);
            }
        }
    }
}
