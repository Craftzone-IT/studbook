<?php

declare(strict_types=1);

namespace Studbook\Tests\Camera;

use PHPUnit\Framework\TestCase;
use Studbook\Camera\Photo;
use Studbook\Camera\PhotoException;

final class PhotoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD is not available.');
        }
        $this->dir = sys_get_temp_dir() . '/studbook-photo-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/sub/*') ?: []);
        @rmdir($this->dir . '/sub');
        array_map('unlink', array_filter(glob($this->dir . '/*') ?: [], 'is_file'));
        @rmdir($this->dir);
    }

    public function testUploadsAreChecked(): void
    {
        $jpeg = $this->image(40, 30);
        self::assertSame($jpeg, Photo::uploaded(self::file($jpeg)));

        $text = $this->dir . '/note.txt';
        file_put_contents($text, 'not a picture');
        $cases = [
            'missing' => null,
            'too_large' => self::file($jpeg, UPLOAD_ERR_INI_SIZE),
            'upload_failed' => self::file($jpeg, UPLOAD_ERR_PARTIAL),
            'not_an_image' => self::file($text),
        ];
        foreach ($cases as $expected => $file) {
            try {
                Photo::uploaded($file);
                self::fail($expected . ' expected');
            } catch (PhotoException $e) {
                self::assertSame($expected, $e->getMessage());
            }
        }
    }

    public function testNormaliseScalesAndConverts(): void
    {
        $source = $this->image(3000, 1500);
        $jpeg = Photo::normalise($source, $this->dir . '/out.jpg', 1024, false);
        self::assertSame([1024, 512, IMAGETYPE_JPEG], array_slice(getimagesize($jpeg), 0, 3));

        $png = Photo::normalise($source, $this->dir . '/sub/ocr.png', 2400, true);
        self::assertSame([2400, 1200, IMAGETYPE_PNG], array_slice(getimagesize($png), 0, 3));
        $image = imagecreatefrompng($png);
        $rgb = imagecolorsforindex($image, imagecolorat($image, 10, 10));
        self::assertSame($rgb['red'], $rgb['blue'], 'greyscale for OCR');
    }

    private function image(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
        $path = $this->dir . '/' . $width . 'x' . $height . '.jpg';
        imagejpeg($image, $path);

        return $path;
    }

    /** @return array{tmp_name: string, name: string, size: int, error: int} */
    private static function file(string $path, int $error = UPLOAD_ERR_OK): array
    {
        return ['tmp_name' => $path, 'name' => basename($path), 'size' => (int) filesize($path), 'error' => $error];
    }
}
