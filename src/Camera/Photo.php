<?php

declare(strict_types=1);

namespace Studbook\Camera;

/**
 * Validates an uploaded photo and writes a normalised copy for OCR or
 * recognition: upright (EXIF orientation), scaled down, JPEG or PNG. The
 * original upload is never kept.
 */
final class Photo
{
    public const MAX_BYTES = 15_000_000;
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * Checks an upload from {@see \Studbook\Http\Request::file()} and returns its path.
     *
     * @param array{tmp_name: string, name: string, size: int, error: int}|null $file
     */
    public static function uploaded(?array $file): string
    {
        if ($file === null) {
            throw new PhotoException('missing');
        }
        $tooLarge = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        if ($tooLarge || $file['size'] > self::MAX_BYTES) {
            throw new PhotoException('too_large');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_file($file['tmp_name'])) {
            throw new PhotoException('upload_failed');
        }
        $info = @getimagesize($file['tmp_name']);
        if (!is_array($info) || !in_array($info[2], self::TYPES, true)) {
            throw new PhotoException('not_an_image');
        }

        return $file['tmp_name'];
    }

    /**
     * Writes an upright copy scaled to at most `$maxSide` pixels; greyscale with
     * stretched contrast for OCR. Returns the written file (JPEG, or PNG for OCR).
     */
    public static function normalise(string $source, string $target, int $maxSide, bool $forOcr): string
    {
        if (!extension_loaded('gd')) {
            if (!copy($source, $target)) {
                throw new PhotoException('upload_failed');
            }

            return $target;
        }
        $info = @getimagesize($source);
        $image = match ($info[2] ?? null) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            default => false,
        };
        if ($image === false) {
            throw new PhotoException('not_an_image');
        }
        if (($info[2] ?? null) === IMAGETYPE_JPEG) {
            $image = self::upright($image, $source);
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, $maxSide / max($width, $height));
        if ($scale < 1.0) {
            $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new PhotoException('upload_failed');
        }
        if ($forOcr) {
            imagefilter($image, IMG_FILTER_GRAYSCALE);
            imagefilter($image, IMG_FILTER_CONTRAST, -25);
            $ok = imagepng($image, $target);
        } else {
            $ok = imagejpeg($image, $target, 85);
        }
        imagedestroy($image);
        if (!$ok) {
            throw new PhotoException('upload_failed');
        }

        return $target;
    }

    /** Applies the EXIF orientation phones write instead of rotating the pixels. */
    private static function upright(\GdImage $image, string $source): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($source);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }
}
