<?php

declare(strict_types=1);

namespace Studbook\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Studbook\Catalog\BrickLinkCatalog;

final class BrickLinkCatalogTest extends TestCase
{
    public function testRecognisesTabDelimitedPartsAndXmlColours(): void
    {
        $catalog = BrickLinkCatalog::load(dirname(__DIR__) . '/fixtures/bricklink');

        self::assertSame(['Parts.txt' => 'parts', 'colors.xml' => 'colors'], $catalog->files);
        self::assertSame(['3794b', '3794'], $catalog->parts['15573']['alternates']);
        self::assertSame('Plate', $catalog->parts['3024']['category']);
        self::assertSame(['name' => 'Light Bluish Gray', 'rgb' => 'AFB5C7'], $catalog->colors[86]);
        self::assertNull($catalog->colors[0]['rgb']);
        self::assertCount(1, $catalog->warnings);
        self::assertStringContainsString('readme-notes.txt', $catalog->warnings[0]);
    }

    public function testReadsTabDelimitedColoursXmlPartsAndOtherEncodings(): void
    {
        $dir = sys_get_temp_dir() . '/studbook-bl-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $colors = "Color ID\tColor Name\tRGB\tType\tParts\n1\tWhite\tFFFFFF\tSolid\t100\n";
        file_put_contents($dir . '/colors.txt', "\xFF\xFE" . mb_convert_encoding($colors, 'UTF-16LE', 'UTF-8'));
        file_put_contents($dir . '/parts.xml', '<?xml version="1.0"?><CATALOG>'
            . '<ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>3005</ITEMID>'
            . '<ITEMNAME>Brick 1 x 1 &amp;#40;old&amp;#41;</ITEMNAME></ITEM>'
            . '<ITEM><ITEMTYPE>S</ITEMTYPE><ITEMID>1000-1</ITEMID><ITEMNAME>A set</ITEMNAME></ITEM>'
            . '</CATALOG>');
        file_put_contents(
            $dir . '/sets.txt',
            "Category ID\tCategory Name\tNumber\tName\tYear Released\n1\tTown\t1000-1\tSet\t2020\n"
        );

        try {
            $catalog = BrickLinkCatalog::load($dir);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }

        self::assertSame(['name' => 'White', 'rgb' => 'FFFFFF'], $catalog->colors[1]);
        self::assertSame(['3005'], array_map('strval', array_keys($catalog->parts)));
        self::assertSame('Brick 1 x 1 (old)', $catalog->parts['3005']['name']);
        self::assertArrayNotHasKey('sets.txt', $catalog->files);
    }

    public function testMissingFolderIsAWarning(): void
    {
        $catalog = BrickLinkCatalog::load('/nonexistent/studbook');

        self::assertSame([], $catalog->parts);
        self::assertCount(1, $catalog->warnings);
    }
}
