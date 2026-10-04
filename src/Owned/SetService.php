<?php

declare(strict_types=1);

namespace Studbook\Owned;

use Studbook\Catalog\CatalogRepository;

/**
 * Owned sets: a set is one unit, stored as a reference to the official
 * inventory plus deltas (missing parts negative, extra parts positive).
 * Each public method that writes is one batch (one undo unit).
 */
final class SetService
{
    public const STATES = ['sealed', 'built', 'disassembled'];
    public const LOCK_MODES = ['locked', 'lendable'];
    public const MAX_COPIES = 20;

    private readonly LotWriter $lots;

    public function __construct(
        private readonly BatchService $batches,
        private readonly OwnedQueries $queries,
        private readonly CatalogRepository $catalog,
    ) {
        $this->lots = new LotWriter($queries);
    }

    /**
     * Adds one or more copies of a catalogue set to a collection.
     *
     * @return array{ids: list<int>, batch: int}
     */
    public function addSet(
        int $collectionId,
        string $setNum,
        string $state,
        string $lockMode,
        ?int $storageId,
        int $copies = 1
    ): array {
        $collection = $this->queries->collection($collectionId) ?? throw new \DomainException('not_found');
        $set = $this->catalog->set($setNum) ?? throw new \DomainException('set_unknown');
        $this->checkOptions($state, $lockMode);
        $this->checkBox($storageId, $collectionId);
        if ($copies < 1 || $copies > self::MAX_COPIES) {
            throw new \DomainException('invalid_quantity');
        }

        $work = static function (Batch $b) use ($collectionId, $set, $state, $lockMode, $storageId, $copies): array {
            $now = LotWriter::now();
            $ids = [];
            for ($i = 0; $i < $copies; $i++) {
                $ids[] = $b->insert('owned_set', [
                    'collection_id' => $collectionId,
                    'set_num' => $set['set_num'],
                    'state' => $state,
                    'lock_mode' => $lockMode,
                    'storage_id' => $storageId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $ids;
        };
        [$ids, $batch] = $this->batches->run('batch.set_added', [
            'qty' => $copies,
            'set' => $set['set_num'],
            'name' => $set['name'],
            'collection' => $collection['name'],
        ], $work);

        return ['ids' => $ids, 'batch' => $batch];
    }

    public function updateSet(int $id, string $state, string $lockMode, ?int $storageId): int
    {
        $owned = $this->require($id);
        $this->checkOptions($state, $lockMode);
        $this->checkBox($storageId, (int) $owned['collection_id']);

        $work = static function (Batch $b) use ($id, $state, $lockMode, $storageId): void {
            $b->update('owned_set', $id, [
                'state' => $state,
                'lock_mode' => $lockMode,
                'storage_id' => $storageId,
                'updated_at' => LotWriter::now(),
            ]);
        };

        return $this->batches->run('batch.set_updated', ['set' => $owned['set_num']], $work)[1];
    }

    /** Removes a set with its recorded differences, as one revertible batch. */
    public function removeSet(int $id): int
    {
        $owned = $this->require($id);

        $work = function (Batch $b) use ($id): void {
            $this->deleteSet($b, $id);
        };

        return $this->batches->run('batch.set_removed', [
            'set' => $owned['set_num'],
            'name' => (string) ($owned['name'] ?? ''),
        ], $work)[1];
    }

    /** Moves a set to another collection; it leaves its box, which belongs to the old collection. */
    public function moveSet(int $id, int $collectionId): int
    {
        $owned = $this->require($id);
        $collection = $this->queries->collection($collectionId) ?? throw new \DomainException('not_found');
        if ((int) $owned['collection_id'] === $collectionId) {
            throw new \DomainException('same_collection');
        }

        $work = static function (Batch $b) use ($id, $collectionId): void {
            $b->update('owned_set', $id, [
                'collection_id' => $collectionId,
                'storage_id' => null,
                'updated_at' => LotWriter::now(),
            ]);
        };

        return $this->batches->run('batch.set_moved', [
            'set' => $owned['set_num'],
            'collection' => $collection['name'],
        ], $work)[1];
    }

    /**
     * Records a difference from the official inventory: a negative change for
     * missing parts, a positive one for extra parts. Changes to the same part
     * and colour add up; a set cannot miss more than its official quantity.
     */
    public function changeDelta(int $id, string $part, int $colorId, int $change): int
    {
        $owned = $this->require($id);
        if ($change === 0 || abs($change) > 100000) {
            throw new \DomainException('invalid_quantity');
        }
        $official = 0;
        foreach ($this->catalog->setInventory((string) $owned['set_num']) as $row) {
            if ($row['part'] === $part && $row['color_id'] === $colorId) {
                $official = $row['qty'];
            }
        }
        $existing = $this->queries->select(
            'SELECT id, qty FROM owned_set_delta WHERE owned_set_id = ? AND part = ? AND color_id = ?',
            [$id, $part, $colorId]
        );
        $current = $existing !== [] ? (int) $existing[0]['qty'] : 0;
        $new = $current + $change;
        if ($new < -$official) {
            throw new \DomainException('too_many_missing');
        }

        $work = static function (Batch $b) use ($id, $existing, $part, $colorId, $new): void {
            $now = LotWriter::now();
            if ($existing !== [] && $new === 0) {
                $b->delete('owned_set_delta', (int) $existing[0]['id']);
            } elseif ($existing !== []) {
                $b->update('owned_set_delta', (int) $existing[0]['id'], ['qty' => $new, 'updated_at' => $now]);
            } elseif ($new !== 0) {
                $b->insert('owned_set_delta', [
                    'owned_set_id' => $id,
                    'part' => $part,
                    'color_id' => $colorId,
                    'qty' => $new,
                    'updated_at' => $now,
                ]);
            }
            $b->update('owned_set', $id, ['updated_at' => $now]);
        };
        $key = $change < 0 ? 'batch.set_missing' : 'batch.set_extra';

        return $this->batches->run($key, [
            'qty' => abs($change),
            'part' => $this->queries->partDisplay($part),
            'set' => $owned['set_num'],
        ], $work)[1];
    }

    public function removeDelta(int $deltaId): int
    {
        $rows = $this->queries->select('SELECT * FROM owned_set_delta WHERE id = ?', [$deltaId]);
        if ($rows === []) {
            throw new \DomainException('not_found');
        }
        $owned = $this->require((int) $rows[0]['owned_set_id']);

        $work = static function (Batch $b) use ($deltaId, $owned): void {
            $b->delete('owned_set_delta', $deltaId);
            $b->update('owned_set', (int) $owned['id'], ['updated_at' => LotWriter::now()]);
        };

        return $this->batches->run('batch.set_delta_removed', [
            'part' => $this->queries->partDisplay((string) $rows[0]['part']),
            'set' => $owned['set_num'],
        ], $work)[1];
    }

    /**
     * What the set actually contains: official inventory (without spares) plus deltas.
     *
     * @return list<array{part: string, color_id: int, official: int, spare: int, delta: int, qty: int}>
     */
    public function contents(int $id): array
    {
        $owned = $this->require($id);
        $rows = [];
        // Keys are prefixed: PHP would turn numeric part numbers into integers.
        foreach ($this->catalog->setInventory((string) $owned['set_num']) as $row) {
            $rows['p:' . $row['part'] . '|' . $row['color_id']] = [
                'part' => $row['part'],
                'color_id' => $row['color_id'],
                'official' => $row['qty'],
                'spare' => $row['spare'],
                'delta' => 0,
                'qty' => $row['qty'],
            ];
        }
        $deltas = $this->queries->select(
            'SELECT part, color_id, qty FROM owned_set_delta WHERE owned_set_id = ?',
            [$id]
        );
        foreach ($deltas as $delta) {
            $key = 'p:' . $delta['part'] . '|' . $delta['color_id'];
            $rows[$key] ??= [
                'part' => (string) $delta['part'],
                'color_id' => (int) $delta['color_id'],
                'official' => 0,
                'spare' => 0,
                'delta' => 0,
                'qty' => 0,
            ];
            $rows[$key]['delta'] = (int) $delta['qty'];
            $rows[$key]['qty'] = max(0, $rows[$key]['official'] + (int) $delta['qty']);
        }

        return array_values($rows);
    }

    /**
     * Breaks a set up into loose parts, as one batch: each part goes into the
     * first box labelled for it (when `$useLabels`), otherwise into
     * `$defaultBoxId`. The set and its deltas are removed.
     *
     * @return array{batch: int, lots: int, parts: int, boxes: int}
     */
    public function breakUp(int $id, int $defaultBoxId, bool $useLabels, bool $includeSpares): array
    {
        $owned = $this->require($id);
        $collectionId = (int) $owned['collection_id'];
        $this->checkBox($defaultBoxId, $collectionId);
        $targets = $useLabels ? $this->labelTargets($collectionId) : [];
        $contents = $this->contents($id);

        $stats = ['lots' => 0, 'parts' => 0, 'boxes' => []];
        $work = function (Batch $b) use (
            $id,
            $contents,
            $includeSpares,
            $targets,
            $defaultBoxId,
            $collectionId,
            &$stats
        ): void {
            foreach ($contents as $row) {
                $qty = $row['qty'] + ($includeSpares ? $row['spare'] : 0);
                if ($qty <= 0) {
                    continue;
                }
                $box = $targets['p:' . $row['part']] ?? $defaultBoxId;
                $this->lots->put($b, $collectionId, $box, $row['part'], $row['color_id'], $qty);
                $stats['lots']++;
                $stats['parts'] += $qty;
                $stats['boxes'][$box] = true;
            }
            $this->deleteSet($b, $id);
        };
        $batch = $this->batches->run('batch.set_broken_up', [
            'set' => $owned['set_num'],
            'name' => (string) ($owned['name'] ?? ''),
        ], $work)[1];

        return [
            'batch' => $batch,
            'lots' => $stats['lots'],
            'parts' => $stats['parts'],
            'boxes' => count($stats['boxes']),
        ];
    }

    private function deleteSet(Batch $b, int $id): void
    {
        foreach ($this->queries->select('SELECT id FROM owned_set_delta WHERE owned_set_id = ?', [$id]) as $row) {
            $b->delete('owned_set_delta', (int) $row['id']);
        }
        $b->delete('owned_set', $id);
    }

    /** @return array<string, int> 'p:' . part => first box of the collection labelled for it */
    private function labelTargets(int $collectionId): array
    {
        $rows = $this->queries->select(
            'SELECT sl.part, b.id FROM storage_label sl JOIN storage b ON b.id = sl.storage_id
             WHERE b.collection_id = ? ORDER BY b.name, b.id',
            [$collectionId]
        );
        $targets = [];
        foreach ($rows as $row) {
            $targets['p:' . $row['part']] ??= (int) $row['id'];
        }

        return $targets;
    }

    /** @return array<string, mixed> */
    private function require(int $id): array
    {
        return $this->queries->ownedSet($id) ?? throw new \DomainException('not_found');
    }

    private function checkOptions(string $state, string $lockMode): void
    {
        if (!in_array($state, self::STATES, true) || !in_array($lockMode, self::LOCK_MODES, true)) {
            throw new \DomainException('invalid_option');
        }
    }

    private function checkBox(?int $storageId, int $collectionId): void
    {
        if ($storageId === null) {
            return;
        }
        $box = $this->queries->box($storageId);
        if ($box === null || (int) $box['collection_id'] !== $collectionId) {
            throw new \DomainException('box_other_collection');
        }
    }
}
