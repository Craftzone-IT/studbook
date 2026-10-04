<?php

declare(strict_types=1);

namespace Studbook\Tests\Owned;

/** Putting parts into a box writes their part number on the box (not on the Inbox). */
final class AutoLabelTest extends OwnedTestCase
{
    private int $inbox;
    private int $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO cat_part (rb_num, name, category_id, bl_num, popularity) VALUES
            ('3003', 'Brick 2 x 2', 11, '3003', 900), ('6223', 'Brick 2 x 2 without Inside Ridges', 11, '3003', 90)");
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $this->inbox = (int) $this->queries->inboxId($id);
        [$this->box] = $this->owned->createBox($id, 'Bricks', 'large');
    }

    public function testAddingAPartLabelsTheBoxOnceAndUndoRemovesIt(): void
    {
        $this->owned->setLabels($this->box, ['3024']);
        $batch = $this->owned->addLot($this->box, '3001', 4, 2);
        self::assertSame(['3024', '3001'], $this->queries->labels($this->box), 'appended after the written ones');

        $this->owned->addLot($this->box, '3001', 0, 1);
        self::assertSame(['3024', '3001'], $this->queries->labels($this->box), 'no duplicate for another colour');

        $this->owned->addLot($this->inbox, '3001', 4, 1);
        self::assertSame([], $this->queries->labels($this->inbox), 'the Inbox stays without part numbers');

        $last = $this->owned->addLot($this->box, '3794b', 4, 1);
        $this->batches->revert($last);
        self::assertSame(['3024', '3001'], $this->queries->labels($this->box), 'undo takes the label back too');
        self::assertNotSame(0, $batch);
    }

    public function testOneLabelPerBrickLinkNumber(): void
    {
        $this->owned->addLot($this->box, '6223', 33, 2);
        self::assertSame(['3003'], $this->queries->labels($this->box), 'the usual part for BrickLink 3003');
        $this->owned->addLot($this->box, '3003', 4, 1);
        self::assertSame(['3003'], $this->queries->labels($this->box));
    }

    public function testEntrySessionsAndMovesLabelToo(): void
    {
        $session = $this->owned->addLotInSession($this->box, '3001', 4, 1, null);
        $this->owned->addLotInSession($this->box, '3024', 71, 1, $session['batch']);
        self::assertSame(['3001', '3024'], $this->queries->labels($this->box));
        $this->batches->revert($session['batch']);
        self::assertSame([], $this->queries->labels($this->box), 'undoing the session removes them');

        $this->owned->addLot($this->inbox, '3794b', 4, 3);
        $lot = (int) $this->queries->lots($this->inbox)[0]['id'];
        $this->owned->moveLot($lot, $this->box, 3);
        self::assertSame(['3794b'], $this->queries->labels($this->box), 'sorting out of the Inbox labels the box');
    }
}
