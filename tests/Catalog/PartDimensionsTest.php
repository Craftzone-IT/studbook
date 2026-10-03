<?php

declare(strict_types=1);

namespace Studbook\Tests\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Studbook\Catalog\PartDimensions;

final class PartDimensionsTest extends TestCase
{
    /** @return iterable<string, array{string, ?int, ?int, ?int}> */
    public static function names(): iterable
    {
        yield 'brick' => ['Brick 2 x 4', 2, 4, 3];
        yield 'brick with height' => ['Brick 1 x 2 x 5', 1, 2, 15];
        yield 'brick with print' => ['Brick 1 x 6 with Red Stripes Print', 1, 6, 3];
        yield 'fractional height' => ['Brick Curved 2 x 2 x 2/3 with Print', 2, 2, 2];
        yield 'mixed fraction height' => ['Brick Round Curved 1 x 1 x 1 1/3 Quarter Dome', 1, 1, 4];
        yield 'plate' => ['Plate 2 x 2', 2, 2, 1];
        yield 'tile' => ['Tile 1 x 2 with Groove', 1, 2, 1];
        yield 'round tile' => ['Tile Round 2 x 2', 2, 2, 1];
        yield 'panel' => ['Panel 1 x 2 x 1 [Rounded Corners]', 1, 2, 3];
        yield 'sloped brick has unknown height' => ['Brick Sloped 45° 2 x 1', 2, 1, null];
        yield 'slope' => ['Slope 45° 2 x 2', 2, 2, null];
        yield 'fractional width' => ['Brick Round 1.5 x 1.5 Dome Top', null, null, null];
        yield 'other family' => ['Minifig Head', null, null, null];
        yield 'not at start' => ['Technic Brick 1 x 2', null, null, null];
        yield 'no size' => ['Brick Special', null, null, null];
    }

    #[DataProvider('names')]
    public function testParse(string $name, ?int $width, ?int $length, ?int $height): void
    {
        self::assertSame(
            ['width' => $width, 'length' => $length, 'height_plates' => $height],
            PartDimensions::parse($name)
        );
    }
}
