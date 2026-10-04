<?php

declare(strict_types=1);

namespace Studbook\Tests\Owned;

use Studbook\Catalog\CatalogRepository;
use Studbook\Owned\BatchConflict;
use Studbook\Owned\SetService;

final class SetServiceTest extends OwnedTestCase
{
    private SetService $sets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO cat_theme (id, name, parent_id) VALUES (1, 'Classic', NULL)");
        $this->pdo->exec("INSERT INTO cat_set (set_num, name, year, theme_id, num_parts) VALUES
            ('10696-1', 'Medium Creative Brick Box', 2015, 1, 484), ('10696-2', 'Other Box', 2016, 1, 10),
            ('6000-1', 'Small Set', 1990, 1, 9)");
        $this->pdo->exec("INSERT INTO cat_inventory (set_num, part, color_id, is_spare, from_minifig, quantity) VALUES
            ('6000-1', '3001', 4, 0, 0, 4), ('6000-1', '3001', 4, 0, 1, 1), ('6000-1', '3024', 71, 0, 0, 3),
            ('6000-1', '3024', 71, 1, 0, 1)");
        $this->sets = new SetService($this->batches, $this->queries, new CatalogRepository($this->pdo));
    }

    public function testCatalogueLookups(): void
    {
        $catalog = new CatalogRepository($this->pdo);
        self::assertSame('10696-1', $catalog->set('10696')['set_num'], 'the -1 variant is found');
        self::assertSame('10696-2', $catalog->set('10696-2')['set_num']);
        self::assertNull($catalog->set('9999'));
        self::assertSame(
            ['10696-1', '10696-2'],
            array_column($catalog->findSets('10696'), 'set_num'),
            'the -1 variant first'
        );
        self::assertSame(['10696-1'], array_column($catalog->findSets('creative box'), 'set_num'));
        self::assertSame(
            [['part' => '3001', 'color_id' => 4, 'qty' => 5, 'spare' => 0],
                ['part' => '3024', 'color_id' => 71, 'qty' => 3, 'spare' => 1]],
            $catalog->setInventory('6000-1')
        );
    }

    public function testAddUpdateAndRemoveASet(): void
    {
        [$collection] = $this->owned->createCollection('Mine', false, 'Inbox');
        [$box] = $this->owned->createBox($collection, 'Set boxes', 'set_box');

        $added = $this->sets->addSet($collection, '6000', 'sealed', 'locked', $box, 2);
        self::assertCount(2, $added['ids']);
        self::assertSame(2, count($this->queries->sets($collection)));
        self::assertSame(2, count($this->queries->sets($collection, $box)), 'kept in the box');
        self::assertSame('6000-1', $this->queries->ownedSet($added['ids'][0])['set_num']);

        $this->sets->updateSet($added['ids'][0], 'built', 'lendable', null);
        $set = $this->queries->ownedSet($added['ids'][0]);
        self::assertSame(['built', 'lendable', null], [$set['state'], $set['lock_mode'], $set['storage_id']]);

        $this->sets->changeDelta($added['ids'][0], '3001', 4, -2);
        $batch = $this->sets->removeSet($added['ids'][0]);
        self::assertNull($this->queries->ownedSet($added['ids'][0]));
        self::assertSame(0, $this->rowCount('owned_set_delta'));

        $this->batches->revert($batch);
        self::assertSame('built', $this->queries->ownedSet($added['ids'][0])['state']);
        self::assertSame(1, $this->rowCount('owned_set_delta'), 'undo brings the deltas back');

        try {
            $this->batches->revert($added['batch']);
            self::fail('a later batch changed one of the copies');
        } catch (BatchConflict) {
            self::assertSame(2, $this->rowCount('owned_set'));
        }
    }

    public function testInvalidInputIsRejected(): void
    {
        [$a] = $this->owned->createCollection('A', false, 'Inbox');
        [$b] = $this->owned->createCollection('B', false, 'Inbox');
        $cases = [
            'set_unknown' => fn () => $this->sets->addSet($a, '9999', 'sealed', 'locked', null),
            'invalid_option' => fn () => $this->sets->addSet($a, '6000', 'lost', 'locked', null),
            'box_other_collection' => fn () => $this->sets->addSet(
                $a,
                '6000',
                'sealed',
                'locked',
                $this->queries->inboxId($b)
            ),
            'invalid_quantity' => fn () => $this->sets->addSet($a, '6000', 'sealed', 'locked', null, 21),
        ];
        foreach ($cases as $expected => $call) {
            try {
                $call();
                self::fail($expected . ' expected');
            } catch (\DomainException $e) {
                self::assertSame($expected, $e->getMessage());
            }
        }
    }

    public function testDeltasChangeTheContents(): void
    {
        [$collection] = $this->owned->createCollection('Mine', false, 'Inbox');
        $id = $this->sets->addSet($collection, '6000-1', 'disassembled', 'lendable', null)['ids'][0];

        $this->sets->changeDelta($id, '3001', 4, -2);
        $this->sets->changeDelta($id, '3001', 4, -1);
        $this->sets->changeDelta($id, '3001', 0, 6);
        $contents = $this->byKey($this->sets->contents($id));
        self::assertSame(['official' => 5, 'delta' => -3, 'qty' => 2], array_intersect_key(
            $contents['3001|4'],
            ['official' => 0, 'delta' => 0, 'qty' => 0]
        ));
        self::assertSame(6, $contents['3001|0']['qty'], 'extra parts outside the inventory');
        self::assertSame(3, $contents['3024|71']['qty'], 'spares are not counted');

        try {
            $this->sets->changeDelta($id, '3001', 4, -3);
            self::fail('cannot miss more than the set has');
        } catch (\DomainException $e) {
            self::assertSame('too_many_missing', $e->getMessage());
        }

        $this->sets->changeDelta($id, '3001', 4, 3);
        self::assertSame(1, $this->rowCount('owned_set_delta'), 'a delta back to zero disappears');
        $delta = (int) $this->queries->deltas($id)[0]['id'];
        $this->sets->removeDelta($delta);
        self::assertSame(0, $this->rowCount('owned_set_delta'));
    }

    public function testBreakingUpUsesLabelledBoxesAndIsOneBatch(): void
    {
        [$collection] = $this->owned->createCollection('Mine', false, 'Inbox');
        $inbox = $this->queries->inboxId($collection);
        [$plates] = $this->owned->createBox($collection, 'Plates', 'small');
        $this->owned->setLabels($plates, ['3024']);
        $this->owned->addLot($inbox, '3001', 4, 10);
        $id = $this->sets->addSet($collection, '6000', 'built', 'lendable', null)['ids'][0];
        $this->sets->changeDelta($id, '3001', 4, -1);

        $result = $this->sets->breakUp($id, $inbox, true, true);
        self::assertSame(['lots' => 2, 'parts' => 8, 'boxes' => 2], array_diff_key($result, ['batch' => 0]));
        self::assertSame(14, (int) $this->queries->lots($inbox)[0]['qty'], 'merged with the loose lot');
        self::assertSame(4, (int) $this->queries->lots($plates)[0]['qty'], 'spares included, labelled box');
        self::assertNull($this->queries->ownedSet($id));

        $this->batches->revert($result['batch']);
        self::assertSame(10, (int) $this->queries->lots($inbox)[0]['qty']);
        self::assertSame([], $this->queries->lots($plates));
        self::assertNotNull($this->queries->ownedSet($id));

        $result = $this->sets->breakUp($id, $plates, false, false);
        self::assertSame(['lots' => 2, 'parts' => 7, 'boxes' => 1], array_diff_key($result, ['batch' => 0]));
    }

    public function testMovingASetToAnotherCollection(): void
    {
        [$a] = $this->owned->createCollection('A', false, 'Inbox');
        [$b] = $this->owned->createCollection('B', false, 'Inbox');
        [$box] = $this->owned->createBox($a, 'Shelf', 'set_box');
        $id = $this->sets->addSet($a, '6000', 'sealed', 'locked', $box)['ids'][0];

        $this->sets->moveSet($id, $b);
        $set = $this->queries->ownedSet($id);
        self::assertSame([$b, null], [(int) $set['collection_id'], $set['storage_id']]);

        $this->expectException(\DomainException::class);
        $this->sets->moveSet($id, $b);
    }

    public function testBoxesCarryTheirSets(): void
    {
        [$a] = $this->owned->createCollection('A', false, 'Inbox');
        [$b] = $this->owned->createCollection('B', false, 'Inbox');
        [$box] = $this->owned->createBox($a, 'Shelf', 'set_box');
        $id = $this->sets->addSet($a, '6000', 'sealed', 'locked', $box)['ids'][0];

        $this->owned->moveBox($box, $b);
        self::assertSame($b, (int) $this->queries->ownedSet($id)['collection_id']);

        $this->owned->deleteBox($box);
        $set = $this->queries->ownedSet($id);
        self::assertNull($set['storage_id'], 'deleting the box keeps the set');

        $this->owned->deleteCollection($b);
        self::assertSame(0, $this->rowCount('owned_set'));
    }

    /**
     * @param list<array{part: string, color_id: int}> $rows
     * @return array<string, array<string, mixed>>
     */
    private function byKey(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[$row['part'] . '|' . $row['color_id']] = $row;
        }

        return $result;
    }
}
