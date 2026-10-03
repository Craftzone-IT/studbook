<?php

declare(strict_types=1);

namespace Studbook\Tests\Owned;

use Studbook\Owned\Batch;
use Studbook\Owned\BatchConflict;

final class BatchServiceTest extends OwnedTestCase
{
    public function testEveryWriteIsStampedAndJournaled(): void
    {
        [$collectionId, $batchId] = $this->owned->createCollection('Mine', true, 'Inbox');

        $collection = $this->queries->collection($collectionId);
        self::assertSame($batchId, (int) $collection['batch_id']);
        $changes = $this->pdo->query("SELECT COUNT(*) FROM batch_change WHERE batch_id = {$batchId}")->fetchColumn();
        self::assertSame(2, (int) $changes);
        self::assertSame('batch.collection_created', $this->batches->find($batchId)['description_key']);
    }

    public function testRevertUndoesInsertsUpdatesAndDeletes(): void
    {
        [$collectionId] = $this->owned->createCollection('Mine', false, 'Inbox');
        $inbox = $this->queries->inboxId($collectionId);
        $this->owned->addLot($inbox, '3001', 4, 5);
        $snapshot = $this->all('SELECT * FROM loose_lot ORDER BY id');

        [, $batchId] = $this->batches->run('test', [], static function (Batch $b) use ($snapshot, $inbox): void {
            $b->update('loose_lot', (int) $snapshot[0]['id'], ['qty' => 99]);
            $b->update('loose_lot', (int) $snapshot[0]['id'], ['qty' => 100]);
            $b->insert('loose_lot', [
                'collection_id' => $snapshot[0]['collection_id'],
                'storage_id' => $inbox,
                'part' => '3024',
                'color_id' => 71,
                'qty' => 1,
                'updated_at' => '2026-01-01 00:00:00',
            ]);
            $b->delete('loose_lot', (int) $snapshot[0]['id']);
        });
        self::assertSame(1, $this->rowCount('loose_lot'));

        $this->batches->revert($batchId);

        self::assertSame($snapshot, $this->all('SELECT * FROM loose_lot ORDER BY id'));
        self::assertNotNull($this->batches->find($batchId)['reverted_at']);
    }

    public function testRevertRefusesWhenALaterBatchChangedTheSameRows(): void
    {
        [$collectionId] = $this->owned->createCollection('Mine', false, 'Inbox');
        $inbox = $this->queries->inboxId($collectionId);
        $first = $this->owned->addLot($inbox, '3001', 4, 5);
        $this->owned->addLot($inbox, '3001', 4, 2);

        try {
            $this->batches->revert($first);
            self::fail('Expected a conflict');
        } catch (BatchConflict $e) {
            self::assertSame('changed_later', $e->getMessage());
        }
        self::assertSame(7, (int) $this->all('SELECT qty FROM loose_lot')[0]['qty'], 'nothing was changed');
    }

    public function testBatchCannotBeRevertedTwice(): void
    {
        [, $batchId] = $this->owned->createCollection('Mine', false, 'Inbox');
        $this->batches->revert($batchId);

        $this->expectException(BatchConflict::class);
        $this->batches->revert($batchId);
    }

    public function testFailedWorkLeavesNothingBehind(): void
    {
        try {
            $this->batches->run('test', [], static function (Batch $b): void {
                $b->insert('collection', ['name' => 'X', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->rowCount('collection'));
        self::assertSame(0, $this->rowCount('batch'));
    }

    public function testBatchWithoutChangesIsRemoved(): void
    {
        $this->batches->run('test', [], static fn (): null => null);

        self::assertSame(0, $this->rowCount('batch'));
    }

    public function testOnlyKnownTablesAndColumnsAreWritable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->batches->run('test', [], static function (Batch $b): void {
            $b->insert('user', ['username' => 'x']);
        });
    }
}
