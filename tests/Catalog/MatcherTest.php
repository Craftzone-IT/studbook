<?php

declare(strict_types=1);

namespace Studbook\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Studbook\Catalog\ColorMatcher;
use Studbook\Catalog\PartMatcher;

final class MatcherTest extends TestCase
{
    public function testColoursMatchByNormalisedName(): void
    {
        $matcher = new ColorMatcher([
            0 => ['name' => '(Not Applicable)', 'rgb' => null],
            11 => ['name' => 'Black', 'rgb' => '212121'],
            86 => ['name' => 'Light Bluish Gray', 'rgb' => 'AFB5C7'],
            12 => ['name' => 'Trans-Clear', 'rgb' => 'EEEEEE'],
        ]);

        self::assertSame(['bl_id' => 11, 'bl_name' => 'Black'], $matcher->match('Black'));
        self::assertSame(86, $matcher->match('Light Bluish Grey')['bl_id'] ?? null);
        self::assertSame(12, $matcher->match('Trans Clear')['bl_id'] ?? null);
        self::assertSame(0, $matcher->match('[No Color/Any Color]')['bl_id'] ?? null);
        self::assertNull($matcher->match('Imaginary Sparkle'));
    }

    public function testAmbiguousColourNamesUseRgbOrStayUnmatched(): void
    {
        $matcher = new ColorMatcher([
            1 => ['name' => 'Duplicate', 'rgb' => '111111'],
            2 => ['name' => 'Duplicate', 'rgb' => '222222'],
        ]);

        self::assertSame(2, $matcher->match('Duplicate', '222222')['bl_id'] ?? null);
        self::assertNull($matcher->match('Duplicate', '333333'));
    }

    public function testPartsMatchExactlyThenViaAlternates(): void
    {
        $matcher = new PartMatcher([
            '3001' => ['name' => 'Brick 2 x 4', 'category' => 'Brick', 'alternates' => ['3001old']],
            '15573' => ['name' => 'Plate, Modified', 'category' => null, 'alternates' => ['3794b', '3794']],
            '9999a' => ['name' => 'A', 'category' => null, 'alternates' => ['shared']],
            '9999b' => ['name' => 'B', 'category' => null, 'alternates' => ['shared']],
        ]);

        self::assertSame(['bl_num' => '3001', 'method' => PartMatcher::EXACT], $matcher->match('3001'));
        self::assertSame(['bl_num' => '15573', 'method' => PartMatcher::ALTERNATE], $matcher->match('3794b'));
        self::assertNull($matcher->match('3001pr0001'));
        self::assertNull($matcher->match('shared'), 'ambiguous alternates are not guessed');
        self::assertFalse($matcher->isEmpty());
        self::assertTrue((new PartMatcher([]))->isEmpty());
    }
}
