<?php

declare(strict_types=1);

namespace Studbook\Tests\Owned;

use Studbook\Owned\PlacementAdvisor;
use Studbook\SettingRepository;

final class PlacementAdvisorTest extends PlacementTestCase
{
    public function testExactLabel(): void
    {
        $advice = $this->advisor->advise($this->collection, '3070b');

        self::assertSame($this->boxes['tiles'], $advice[0]['box_id']);
        self::assertSame('01 Tiles', $advice[0]['name']);
        self::assertSame('large', $advice[0]['type']);
        self::assertSame('label', $advice[0]['reason']);
        self::assertSame([], $advice[0]['detail']);
    }

    public function testSameMouldGroupOnALabel(): void
    {
        $advice = $this->advisor->advise($this->collection, '3794b');

        self::assertSame($this->boxes['small_plates'], $advice[0]['box_id']);
        self::assertSame('label_variant', $advice[0]['reason']);
        self::assertSame('3794a', $advice[0]['detail']['part']);
        self::assertSame('3794a Plate Special 1 x 2 with 1 Stud without Groove', $advice[0]['detail']['part_label']);
    }

    public function testSameBrickLinkNumberOnALabel(): void
    {
        $this->pdo->exec("INSERT INTO cat_part (rb_num, name, category_id, bl_num, width, length)
            VALUES ('35382', 'Brick 1 x 1', 11, '3005', 1, 1)");

        $advice = $this->advisor->advise($this->collection, '35382');

        self::assertSame($this->boxes['small_bricks'], $advice[0]['box_id']);
        self::assertSame('label_variant', $advice[0]['reason']);
        self::assertSame('3005', $advice[0]['detail']['part']);
    }

    public function testPrintBoxAndUnprintedParentOnALabel(): void
    {
        $advice = $this->advisor->advise($this->collection, '3001pr0001');
        self::assertSame($this->boxes['large_bricks'], $advice[0]['box_id'], 'no print box: unprinted part decides');
        self::assertSame('label_unprinted', $advice[0]['reason']);
        self::assertSame('3001', $advice[0]['detail']['part']);

        $settings = new SettingRepository($this->pdo);
        $settings->set(PlacementAdvisor::printBoxKey($this->collection), (string) $this->boxes['misc']);
        $advice = $this->advisor->advise($this->collection, '3001pr0001');
        self::assertSame(['misc', 'large_bricks'], array_slice($this->advised('3001pr0001'), 0, 2));
        self::assertSame('print_box', $advice[0]['reason']);

        self::assertNotContains('misc', $this->advised('3001'), 'the print box is only for prints');

        $settings->delete(PlacementAdvisor::printBoxKey($this->collection));
        self::assertSame('large_bricks', $this->advised('3001pr0001')[0]);
    }

    public function testPrintGroupWithoutDirectRelationCounts(): void
    {
        $this->pdo->exec("INSERT INTO cat_part (rb_num, name, category_id, bl_num) VALUES
            ('3001pr0002', 'Brick 2 x 4 with Other Print', 11, NULL)");
        $this->pdo->exec("INSERT INTO cat_part_rel (rel_type, child, parent) VALUES ('T', '3001pr0002', '3001pr0001')");
        $this->pdo->exec("INSERT INTO cat_part_canon (part, variant, print)
            VALUES ('3001pr0002', '3001pr0002', '3001')");

        $advice = $this->advisor->advise($this->collection, '3001pr0002');

        self::assertSame($this->boxes['large_bricks'], $advice[0]['box_id']);
        self::assertSame('label_unprinted', $advice[0]['reason']);
    }

    public function testBoxesAlreadyHoldingThePart(): void
    {
        $this->owned->addLot($this->boxes['misc'], '4070', 4, 3);
        $this->owned->addLot($this->boxes['misc'], '4070', 0, 9);
        $this->owned->addLot($this->inbox, '4070', 4, 50);
        $this->owned->setLabels($this->boxes['misc'], []); // undo the automatic label

        $advice = $this->advisor->advise($this->collection, '4070');

        self::assertSame(['misc', 'studs_jar'], array_slice($this->advised('4070'), 0, 2));
        self::assertSame('stored', $advice[0]['reason']);
        self::assertSame(['qty' => 12], $advice[0]['detail'], 'all colours count');
        self::assertSame('similar', $advice[1]['reason']);
    }

    /** The expected results of the spec for a typical box layout. */
    public function testSimilarPartsBySize(): void
    {
        $tile = $this->advisor->advise($this->collection, '26603');
        self::assertSame($this->boxes['tiles'], $tile[0]['box_id']);
        self::assertSame('similar', $tile[0]['reason']);
        self::assertSame('63864', $tile[0]['detail']['part'], 'nearest label part (1 x 3), first in label order');

        self::assertSame(['large_plates', 'long_plates', 'small_plates'], $this->advised('3033'));
        self::assertSame('studs_jar', $this->advised('4070')[0]);
        self::assertSame('slopes', $this->advised('30363')[0]);
        self::assertSame(['small_bricks', 'large_bricks'], $this->advised('2453b'));
        self::assertSame(['technic'], $this->advised('32054'));
    }

    public function testTiesAreOrderedByLabelCountThenName(): void
    {
        self::assertSame(['clip_jar', 'small_plates'], $this->advised('32028'), 'distance 0 each; two labels win');

        $this->owned->setLabels($this->boxes['clip_jar'], ['11476']);
        self::assertSame(['small_plates', 'clip_jar'], $this->advised('32028'), 'same distance and count: by name');
    }

    public function testSmallerPartNextToLargerLabelsDoesNotOverflow(): void
    {
        // 3024 (1 x 1) against 3030 (4 x 10): label minus part and part minus label are both computed.
        $advice = $this->advisor->advise($this->collection, '3024');

        self::assertSame($this->boxes['small_plates'], $advice[0]['box_id']);
        self::assertSame(['small_plates', 'long_plates', 'large_plates'], $this->advised('3024'));
    }

    public function testPartsWithoutDimensionsRankByLabelCountAndName(): void
    {
        $this->pdo->exec("INSERT INTO cat_part (rb_num, name, category_id, bl_num) VALUES
            ('4274', 'Technic Pin 1/2', 53, '4274')");
        $this->owned->setLabels($this->boxes['misc'], ['32054', '2780']);
        self::assertSame(['misc', 'technic'], $this->advised('4274'), 'more labels of the category win');

        $this->owned->setLabels($this->boxes['misc'], []);
        $this->owned->setLabels($this->boxes['slopes'], ['3039', '2780']);
        self::assertSame(['slopes', 'technic'], $this->advised('4274'), 'same count: by name');

        $advice = $this->advisor->advise($this->collection, '4274');
        self::assertSame('2780', $advice[1]['detail']['part']);
    }

    public function testEarlierStepsWinAndBoxesAppearOnce(): void
    {
        $this->owned->setLabels($this->boxes['misc'], ['3794b']);

        $advice = $this->advisor->advise($this->collection, '3794b');

        self::assertSame(['misc', 'small_plates', 'clip_jar'], $this->advised('3794b'));
        self::assertSame(['label', 'label_variant', 'similar'], array_column($advice, 'reason'));
        self::assertCount(2, $this->advisor->advise($this->collection, '3794b', 2));
    }

    public function testInboxAndOtherCollectionsAreNeverSuggested(): void
    {
        $this->owned->setLabels($this->inbox, ['26603']);
        [$other] = $this->owned->createCollection('Other', false, 'Inbox');
        [$otherBox] = $this->owned->createBox($other, '00 Tiles 2 x 3', 'large');
        $this->owned->setLabels($otherBox, ['26603']);

        self::assertSame('tiles', $this->advised('26603')[0]);
        $advised = array_column($this->advisor->advise($this->collection, '26603'), 'box_id');
        self::assertNotContains($this->inbox, $advised);
        self::assertSame([$otherBox], array_column($this->advisor->advise($other, '26603'), 'box_id'));
    }

    public function testNoBox(): void
    {
        self::assertSame([], $this->advisor->advise($this->collection, '3626c'));
        self::assertSame([], $this->advisor->advise($this->collection, 'not-a-part'));
    }
}
