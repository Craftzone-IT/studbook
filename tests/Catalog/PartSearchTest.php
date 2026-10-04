<?php

declare(strict_types=1);

namespace Studbook\Tests\Catalog;

use Studbook\Catalog\PartSearch;
use Studbook\Tests\DatabaseTestCase;

final class PartSearchTest extends DatabaseTestCase
{
    private PartSearch $search;
    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->pdo->exec("INSERT INTO cat_part (rb_num, name, category_id, bl_num, width, length, popularity) VALUES
            ('3001', 'Brick 2 x 4', 11, '3001', 2, 4, 900),
            ('3001pr0001', 'Brick 2 x 4 with Print', 11, NULL, 2, 4, 1),
            ('3003', 'Brick 2 x 2', 11, '3003', 2, 2, 800),
            ('3023', 'Plate 1 x 2', 14, '3023', 1, 2, 950),
            ('3794b', 'Plate Special 1 x 2 with 1 Stud', 9, '15573', 1, 2, 500),
            ('3040', 'Slope 45° 2 x 1', 3, '3040', 1, 2, 700),
            ('4073', 'Plate Round 1 x 1', 21, '4073', 1, 1, 600),
            ('3641', 'Tyre for Wheel', 29, '3641', NULL, NULL, 50)");
        $this->pdo->exec("INSERT INTO cat_color (rb_id, name, rgb, is_trans, bl_id, bl_name) VALUES
            (0, 'Black', '05131D', 0, 11, 'Black'), (4, 'Red', 'C91A09', 0, 5, 'Red'),
            (72, 'Dark Bluish Gray', '6C6E68', 0, 85, 'Dark Bluish Gray'),
            (47, 'Trans-Clear', 'FCFCFC', 1, 12, 'Trans-Clear')");
        $this->pdo->exec("INSERT INTO cat_part_color (part, color_id) VALUES
            ('3001', 4), ('3001', 0), ('3003', 0), ('3023', 72), ('3023', 4), ('3794b', 72), ('4073', 47)");
        $this->cache = sys_get_temp_dir() . '/studbook-search-' . bin2hex(random_bytes(4));
        $this->search = new PartSearch($this->pdo, $this->cache);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cache . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->cache)) {
            rmdir($this->cache);
        }
        parent::tearDown();
    }

    public function testHungarianWordsColourAndSize(): void
    {
        $result = $this->search->search('piros kocka 2x4');

        self::assertSame(['3001'], self::numbers($result), 'the printed variant has no red');
        self::assertSame(4, $result['color']['id']);
        self::assertSame([2, 4], $result['size']);
    }

    public function testMultiWordColourThroughSynonyms(): void
    {
        $result = $this->search->search('lap 1x2 sötét kékesszürke');

        self::assertSame('Dark Bluish Gray', $result['color']['name']);
        self::assertSame(['3023', '3794b'], self::numbers($result), 'more popular first');
    }

    public function testPartNumbersExactFirstThenPrefix(): void
    {
        self::assertSame(['3001', '3001pr0001'], self::numbers($this->search->search('3001')));

        $bl = $this->search->search('15573');
        self::assertSame(['3794b'], self::numbers($bl), 'BrickLink numbers are found');
        self::assertSame('15573', $bl['parts'][0]['display']);
    }

    public function testSizeMatchesEitherOrientationAndNumbersMatchNames(): void
    {
        self::assertSame(['3040'], self::numbers($this->search->search('slope 45 2x1')));
        self::assertSame(['3040'], self::numbers($this->search->search('lejto 2×1')), 'accents are optional');
    }

    public function testTyposAreCorrected(): void
    {
        $result = $this->search->search('plaet rond');

        self::assertSame(['plaet' => 'plate', 'rond' => 'round'], $result['corrections']);
        self::assertSame(['4073'], self::numbers($result));
        self::assertNotSame([], glob($this->cache . '/search-vocabulary-*.json'), 'the vocabulary is cached');
    }

    public function testHyphenatedColoursAndEmptyQueries(): void
    {
        $result = $this->search->search('trans-clear round');
        self::assertSame(47, $result['color']['id']);
        self::assertSame(['4073'], self::numbers($result));

        self::assertSame([], $this->search->search('   ')['parts']);
        self::assertSame([], $this->search->search('qqqqqq')['parts']);
    }

    public function testEditDistanceCountsSwapsAsOneEdit(): void
    {
        self::assertSame(1, PartSearch::editDistance('plaet', 'plate'));
        self::assertSame(1, PartSearch::editDistance('plaet', 'plant'), 'a tie: the more frequent word wins');
        self::assertSame(3, PartSearch::editDistance('kitten', 'sitting'));
    }

    /**
     * @param array{parts: list<array{rb_num: string}>} $result
     * @return list<string>
     */
    private static function numbers(array $result): array
    {
        return array_column($result['parts'], 'rb_num');
    }
}
