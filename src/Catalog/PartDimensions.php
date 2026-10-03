<?php

declare(strict_types=1);

namespace Studbook\Catalog;

/**
 * Parses stud dimensions from catalogue part names of common families, e.g.
 * "Brick 2 x 4" → 2 × 4, 3 plates high; "Plate 1 x 6" → 1 × 6, 1 plate;
 * "Brick 1 x 2 x 5" → 15 plates; "Brick Curved 2 x 2 x 2/3" → 2 plates.
 *
 * Height is measured in plates (a brick is 3 plates). Anything unusual
 * (fractional widths, unknown families) yields nulls rather than a guess.
 */
final class PartDimensions
{
    /** Family => default height in plates when the name gives none (null = unknown). */
    private const FAMILIES = [
        'Brick' => 3,
        'Plate' => 1,
        'Tile' => 1,
        'Slope' => null,
        'Wedge' => null,
        'Panel' => null,
    ];

    /** @return array{width: ?int, length: ?int, height_plates: ?int} */
    public static function parse(string $name): array
    {
        $none = ['width' => null, 'length' => null, 'height_plates' => null];
        if (!preg_match('/^(Brick|Plate|Tile|Slope|Wedge|Panel)\b/', $name, $family)) {
            return $none;
        }
        $number = '\d+(?:\.\d+)?';
        $height = '\d+\s+\d+\/\d+|\d+\/\d+|\d+(?:\.\d+)?';
        $pattern = '/(?<![\d.\/])(' . $number . ')\s*x\s*(' . $number . ')(?:\s*x\s*(' . $height . '))?(?![\d\/])/';
        if (!preg_match($pattern, $name, $m)) {
            return $none;
        }
        if (!ctype_digit($m[1]) || !ctype_digit($m[2])) {
            return $none;
        }
        $width = (int) $m[1];
        $length = (int) $m[2];
        if ($width < 1 || $length < 1 || $width > 255 || $length > 255) {
            return $none;
        }

        $heightPlates = self::FAMILIES[$family[1]];
        if (str_starts_with($name, 'Brick Sloped')) {
            $heightPlates = null;
        }
        if (isset($m[3]) && $m[3] !== '') {
            $bricks = self::fraction($m[3]);
            $heightPlates = $bricks === null ? null : self::toPlates($bricks);
        }

        return ['width' => $width, 'length' => $length, 'height_plates' => $heightPlates];
    }

    /** Parses "3", "2/3", "1 1/3" (bricks). */
    private static function fraction(string $value): ?float
    {
        $value = trim($value);
        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $value, $m)) {
            return (int) $m[3] === 0 ? null : (int) $m[1] + (int) $m[2] / (int) $m[3];
        }
        if (preg_match('/^(\d+)\/(\d+)$/', $value, $m)) {
            return (int) $m[2] === 0 ? null : (int) $m[1] / (int) $m[2];
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /** Converts bricks to plates; only whole plate counts are kept. */
    private static function toPlates(float $bricks): ?int
    {
        $plates = $bricks * 3;
        $rounded = (int) round($plates);

        return abs($plates - $rounded) < 0.01 && $rounded > 0 ? $rounded : null;
    }
}
