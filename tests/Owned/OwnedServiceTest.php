<?php

declare(strict_types=1);

namespace Studbook\Tests\Owned;

final class OwnedServiceTest extends OwnedTestCase
{
    public function testNewCollectionGetsAnInbox(): void
    {
        [$id] = $this->owned->createCollection('  Kid’s   bricks ', true, 'Inbox');

        self::assertSame('Kid’s bricks', $this->queries->collection($id)['name']);
        $boxes = $this->queries->boxes($id);
        self::assertCount(1, $boxes);
        self::assertSame('inbox', $boxes[0]['type']);
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->owned->createCollection('   ', false, 'Inbox');
    }

    public function testLotsMergeTakeOutAndMove(): void
    {
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $inbox = $this->queries->inboxId($id);
        [$box] = $this->owned->createBox($id, 'Bricks', 'large');

        $this->owned->addLot($inbox, '3001', 4, 5);
        $this->owned->addLot($inbox, '3001', 4, 3);
        self::assertSame(1, $this->rowCount('loose_lot'));
        $lot = $this->queries->lots($inbox)[0];
        self::assertSame(8, (int) $lot['qty']);
        self::assertSame('Red', $lot['color_name']);

        $this->owned->moveLot((int) $lot['id'], $box, 6);
        self::assertSame(2, (int) $this->queries->lots($inbox)[0]['qty']);
        self::assertSame(6, (int) $this->queries->lots($box)[0]['qty']);

        $this->owned->takeOut((int) $lot['id'], 50);
        self::assertSame([], $this->queries->lots($inbox), 'taking out more than there is empties the lot');

        $this->owned->addLot($inbox, '3001', 4, 1);
        $this->owned->moveLot((int) $this->queries->lots($inbox)[0]['id'], $box, 1);
        self::assertSame(7, (int) $this->queries->lots($box)[0]['qty'], 'moved parts merge into the target lot');
    }

    public function testLotsAndBoxesMoveToAnotherCollection(): void
    {
        [$a] = $this->owned->createCollection('A', false, 'Inbox');
        [$b] = $this->owned->createCollection('B', false, 'Inbox');
        $inboxA = $this->queries->inboxId($a);
        $inboxB = $this->queries->inboxId($b);
        $this->owned->addLot($inboxA, '3001', 4, 3);
        $this->owned->addLot($inboxB, '3001', 4, 2);

        $lot = (int) $this->queries->lots($inboxA)[0]['id'];
        $this->owned->moveLot($lot, $inboxB, 1);
        $moved = $this->queries->lots($inboxB)[0];
        self::assertSame(3, (int) $moved['qty'], 'merges into the lot of the other collection');
        self::assertSame($b, (int) $moved['collection_id']);

        [$box] = $this->owned->createBox($a, 'Bricks', 'large');
        $this->owned->addLot($box, '3024', 71, 10);
        $this->owned->setLabels($box, ['3024']);
        $batch = $this->owned->moveBox($box, $b);
        self::assertSame($b, (int) $this->queries->box($box)['collection_id']);
        self::assertSame($b, (int) $this->queries->lots($box)[0]['collection_id']);
        self::assertSame(['3024'], $this->queries->labels($box), 'labels travel with the box');

        $this->batches->revert($batch);
        self::assertSame($a, (int) $this->queries->box($box)['collection_id']);
        self::assertSame($a, (int) $this->queries->lots($box)[0]['collection_id']);

        $this->expectException(\DomainException::class);
        $this->owned->moveBox($inboxA, $b);
    }

    public function testDeletingABoxMovesItsContentsToTheInbox(): void
    {
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $inbox = $this->queries->inboxId($id);
        [$box] = $this->owned->createBox($id, 'Bricks', 'large');
        $this->owned->addLot($box, '3001', 4, 4);
        $this->owned->addLot($inbox, '3001', 4, 1);
        $this->owned->setLabels($box, ['3001', '3024']);

        $batch = $this->owned->deleteBox($box);

        self::assertNull($this->queries->box($box));
        self::assertSame(5, (int) $this->queries->lots($inbox)[0]['qty']);
        self::assertSame(0, $this->rowCount('storage_label'));

        $this->batches->revert($batch);
        self::assertSame(4, (int) $this->queries->lots($box)[0]['qty']);
        self::assertSame(1, (int) $this->queries->lots($inbox)[0]['qty']);
        self::assertSame(['3001', '3024'], $this->queries->labels($box));
    }

    public function testInboxCannotBeDeleted(): void
    {
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');

        $this->expectException(\DomainException::class);
        $this->owned->deleteBox($this->queries->inboxId($id));
    }

    public function testLabelsAreReplacedAndOrdered(): void
    {
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        [$box] = $this->owned->createBox($id, 'Bricks', 'large');

        $this->owned->setLabels($box, ['3024', '3001']);
        self::assertSame(['3024', '3001'], $this->queries->labels($box));

        $this->owned->setLabels($box, ['3001', '3794b']);
        self::assertSame(['3001', '3794b'], $this->queries->labels($box));
    }

    public function testDeletingACollectionIsRevertible(): void
    {
        [$id] = $this->owned->createCollection('Mine', true, 'Inbox');
        [$box] = $this->owned->createBox($id, 'Bricks', 'large');
        $this->owned->addLot($box, '3001', 4, 4);
        $this->owned->setLabels($box, ['3001']);
        $before = [
            $this->all('SELECT * FROM collection'),
            $this->all('SELECT * FROM storage ORDER BY id'),
            $this->all('SELECT * FROM loose_lot'),
            $this->all('SELECT * FROM storage_label'),
        ];

        $batch = $this->owned->deleteCollection($id);
        self::assertSame(0, $this->rowCount('collection') + $this->rowCount('storage') + $this->rowCount('loose_lot'));

        $this->batches->revert($batch);
        self::assertSame($before, [
            $this->all('SELECT * FROM collection'),
            $this->all('SELECT * FROM storage ORDER BY id'),
            $this->all('SELECT * FROM loose_lot'),
            $this->all('SELECT * FROM storage_label'),
        ]);
    }

    public function testHomeCardNumbers(): void
    {
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        [$box] = $this->owned->createBox($id, 'Bricks', 'large');
        $this->owned->addLot($box, '3001', 4, 4);
        $this->owned->addLot($box, '3024', 71, 10);
        [$archived] = $this->owned->createCollection('Old', false, 'Inbox');
        $this->owned->setArchived($archived, true);

        $cards = $this->queries->collections();
        self::assertSame(['Mine', 'Old'], array_column($cards, 'name'), 'archived collections come last');
        $card = $cards[0];
        $numbers = array_map('intval', [$card['sets'], $card['loose_parts'], $card['lots'], $card['boxes']]);
        self::assertSame([0, 14, 2, 2], $numbers);
        self::assertNotNull($cards[1]['archived_at']);
    }

    public function testBatchDescriptionsUseBrickLinkNumbers(): void
    {
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $batch = $this->owned->addLot($this->queries->inboxId($id), '3794b', 4, 2);

        self::assertSame('15573', $this->batches->find($batch)['description_params']['part']);
    }
}
