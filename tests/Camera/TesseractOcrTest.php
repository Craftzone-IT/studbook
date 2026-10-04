<?php

declare(strict_types=1);

namespace Studbook\Tests\Camera;

use PHPUnit\Framework\TestCase;
use Studbook\Camera\TesseractOcr;

final class TesseractOcrTest extends TestCase
{
    public const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    public function testStatusReportsAMissingBinary(): void
    {
        $status = (new TesseractOcr('/nonexistent/tesseract'))->status();
        self::assertFalse($status['available']);
        self::assertContains($status['reason'], ['not_installed', 'exec_disabled']);
    }

    public function testReadsPrintedPartNumbers(): void
    {
        $ocr = new TesseractOcr(self::binary());
        if (!$ocr->status()['available'] || !is_file(self::FONT)) {
            self::markTestSkipped('Tesseract or the test font is not installed.');
        }
        $path = self::writeList(sys_get_temp_dir() . '/studbook-ocr-' . bin2hex(random_bytes(4)) . '.png');
        try {
            $text = $ocr->read($path);
        } finally {
            unlink($path);
        }
        self::assertStringContainsString('3001', $text);
        self::assertStringContainsString('3942c', $text);
    }

    public static function binary(): string
    {
        return is_executable('/usr/bin/tesseract') ? '/usr/bin/tesseract' : 'tesseract';
    }

    /** A paper list with printed part numbers, as a PNG. */
    public static function writeList(string $path): string
    {
        $image = imagecreatetruecolor(900, 320);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 235, 220));
        $ink = imagecolorallocate($image, 20, 20, 60);
        foreach (['3001   3024', '3942c   15573'] as $i => $line) {
            imagettftext($image, 48, 0, 40, 110 + $i * 120, $ink, self::FONT, $line);
        }
        imagepng($image, $path);

        return $path;
    }
}
