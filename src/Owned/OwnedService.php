<?php

declare(strict_types=1);

namespace Studbook\Owned;

/**
 * All user-initiated changes to collections, boxes, box labels and loose
 * lots. Each public method is one batch (one undo unit) and returns its id.
 */
final class OwnedService
{
    public const BOX_TYPES = ['large', 'small', 'jar', 'set_box', 'inbox'];
    /** Types a user can pick; the Inbox is created automatically. */
    public const USER_BOX_TYPES = ['large', 'small', 'jar', 'set_box'];
    public const MAX_NAME_LENGTH = 100;

    public function __construct(private readonly BatchService $batches, private readonly OwnedQueries $queries)
    {
    }

    /** @return array{0: int, 1: int} collection id, batch id */
    public function createCollection(string $name, bool $canLend, string $inboxName): array
    {
        $name = self::name($name);

        $work = function (Batch $b) use ($name, $canLend, $inboxName): int {
            $now = self::now();
            $id = $b->insert('collection', [
                'name' => $name,
                'can_lend' => $canLend ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $b->insert('storage', [
                'collection_id' => $id,
                'name' => self::name($inboxName),
                'type' => 'inbox',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $id;
        };

        return $this->batches->run('batch.collection_created', ['name' => $name], $work);
    }

    public function updateCollection(int $id, string $name, bool $canLend): int
    {
        $name = self::name($name);

        $work = static function (Batch $b) use ($id, $name, $canLend): void {
            $b->update('collection', $id, [
                'name' => $name,
                'can_lend' => $canLend ? 1 : 0,
                'updated_at' => self::now(),
            ]);
        };

        return $this->batches->run('batch.collection_updated', ['name' => $name], $work)[1];
    }

    public function setArchived(int $id, bool $archived): int
    {
        $collection = $this->requireCollection($id);
        $key = $archived ? 'batch.collection_archived' : 'batch.collection_restored';

        $work = static function (Batch $b) use ($id, $archived): void {
            $b->update('collection', $id, [
                'archived_at' => $archived ? self::now() : null,
                'updated_at' => self::now(),
            ]);
        };

        return $this->batches->run($key, ['name' => $collection['name']], $work)[1];
    }

    /** Deletes a collection with everything in it, as one revertible batch. */
    public function deleteCollection(int $id): int
    {
        $collection = $this->requireCollection($id);

        $work = function (Batch $b) use ($id): void {
            foreach ($this->ids('SELECT id FROM loose_lot WHERE collection_id = ?', $id) as $lot) {
                $b->delete('loose_lot', $lot);
            }
            foreach ($this->ids('SELECT id FROM owned_set WHERE collection_id = ?', $id) as $set) {
                $b->delete('owned_set', $set);
            }
            foreach ($this->ids('SELECT id FROM storage WHERE collection_id = ?', $id) as $box) {
                foreach ($this->ids('SELECT id FROM storage_label WHERE storage_id = ?', $box) as $label) {
                    $b->delete('storage_label', $label);
                }
                $b->delete('storage', $box);
            }
            $b->delete('collection', $id);
        };

        return $this->batches->run('batch.collection_deleted', ['name' => $collection['name']], $work)[1];
    }

    /** @return array{0: int, 1: int} box id, batch id */
    public function createBox(int $collectionId, string $name, string $type): array
    {
        $this->requireCollection($collectionId);
        $name = self::name($name);
        $type = in_array($type, self::USER_BOX_TYPES, true) ? $type : 'small';

        $work = static function (Batch $b) use ($collectionId, $name, $type): int {
            $now = self::now();

            return $b->insert('storage', [
                'collection_id' => $collectionId,
                'name' => $name,
                'type' => $type,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        };

        return $this->batches->run('batch.box_created', ['name' => $name], $work);
    }

    public function updateBox(int $id, string $name, string $type): int
    {
        $box = $this->requireBox($id);
        $name = self::name($name);
        // The Inbox keeps its type; other boxes cannot become an Inbox.
        if ($box['type'] === 'inbox') {
            $type = 'inbox';
        } elseif (!in_array($type, self::USER_BOX_TYPES, true)) {
            $type = (string) $box['type'];
        }

        $work = static function (Batch $b) use ($id, $name, $type): void {
            $b->update('storage', $id, ['name' => $name, 'type' => $type, 'updated_at' => self::now()]);
        };

        return $this->batches->run('batch.box_updated', ['name' => $name], $work)[1];
    }

    /** Deletes a box; its contents move to the collection's Inbox. The Inbox itself cannot be deleted. */
    public function deleteBox(int $id): int
    {
        $box = $this->requireBox($id);
        if ($box['type'] === 'inbox') {
            throw new \DomainException('inbox_not_deletable');
        }
        $inbox = $this->queries->inboxId((int) $box['collection_id']);
        if ($inbox === null) {
            throw new \DomainException('inbox_missing');
        }

        $work = function (Batch $b) use ($id, $inbox): void {
            foreach ($this->queries->lots($id) as $lot) {
                $this->putLot(
                    $b,
                    (int) $lot['collection_id'],
                    $inbox,
                    (string) $lot['part'],
                    (int) $lot['color_id'],
                    (int) $lot['qty']
                );
                $b->delete('loose_lot', (int) $lot['id']);
            }
            foreach ($this->ids('SELECT id FROM storage_label WHERE storage_id = ?', $id) as $label) {
                $b->delete('storage_label', $label);
            }
            $b->delete('storage', $id);
        };

        return $this->batches->run('batch.box_deleted', ['name' => $box['name']], $work)[1];
    }

    /**
     * Replaces the part numbers written on a box (Rebrickable numbers, already validated).
     *
     * @param list<string> $parts
     */
    public function setLabels(int $storageId, array $parts): int
    {
        $box = $this->requireBox($storageId);
        $parts = array_values(array_unique($parts));

        $work = function (Batch $b) use ($storageId, $parts): void {
            // Keys are prefixed: PHP would turn numeric part numbers such as "3001" into integers.
            $existing = [];
            $rows = $this->rows('SELECT id, part, position FROM storage_label WHERE storage_id = ?', $storageId);
            foreach ($rows as $row) {
                $existing['p:' . $row['part']] = $row;
            }
            $wanted = array_flip(array_map(static fn (string $p): string => 'p:' . $p, $parts));
            foreach ($existing as $key => $row) {
                if (!isset($wanted[$key])) {
                    $b->delete('storage_label', (int) $row['id']);
                }
            }
            foreach ($parts as $position => $part) {
                $row = $existing['p:' . $part] ?? null;
                if ($row === null) {
                    $b->insert('storage_label', [
                        'storage_id' => $storageId,
                        'part' => $part,
                        'position' => $position,
                    ]);
                } elseif ((int) $row['position'] !== $position) {
                    $b->update('storage_label', (int) $row['id'], ['position' => $position]);
                }
            }
            $b->update('storage', $storageId, ['updated_at' => self::now()]);
        };

        return $this->batches->run('batch.labels_updated', ['name' => $box['name']], $work)[1];
    }

    /** Adds parts to a box, merging with an existing lot of the same part and colour. */
    public function addLot(int $storageId, string $part, int $colorId, int $qty): int
    {
        $box = $this->requireBox($storageId);
        $qty = self::quantity($qty);

        $work = function (Batch $b) use ($box, $part, $colorId, $qty): void {
            $this->putLot($b, (int) $box['collection_id'], (int) $box['id'], $part, $colorId, $qty);
        };

        $params = ['qty' => $qty, 'part' => $this->queries->partDisplay($part), 'box' => $box['name']];

        return $this->batches->run('batch.parts_added', $params, $work)[1];
    }

    /** Takes parts out of a lot; the lot disappears when it reaches zero. */
    public function takeOut(int $lotId, int $qty): int
    {
        $lot = $this->requireLot($lotId);
        $qty = min(self::quantity($qty), (int) $lot['qty']);

        $work = function (Batch $b) use ($lot, $qty): void {
            $this->reduceLot($b, $lot, $qty);
        };

        $params = ['qty' => $qty, 'part' => $this->queries->partDisplay((string) $lot['part'])];

        return $this->batches->run('batch.parts_taken', $params, $work)[1];
    }

    /** Moves (part of) a lot to another box of the same collection. */
    public function moveLot(int $lotId, int $targetStorageId, int $qty): int
    {
        $lot = $this->requireLot($lotId);
        $target = $this->requireBox($targetStorageId);
        if ((int) $target['collection_id'] !== (int) $lot['collection_id']) {
            throw new \DomainException('other_collection');
        }
        if ((int) $target['id'] === (int) $lot['storage_id']) {
            throw new \DomainException('same_box');
        }
        $qty = min(self::quantity($qty), (int) $lot['qty']);

        $work = function (Batch $b) use ($lot, $target, $qty): void {
            $this->reduceLot($b, $lot, $qty);
            $this->putLot(
                $b,
                (int) $lot['collection_id'],
                (int) $target['id'],
                (string) $lot['part'],
                (int) $lot['color_id'],
                $qty
            );
        };

        $params = [
            'qty' => $qty,
            'part' => $this->queries->partDisplay((string) $lot['part']),
            'box' => $target['name'],
        ];

        return $this->batches->run('batch.parts_moved', $params, $work)[1];
    }

    private function putLot(Batch $b, int $collectionId, int $storageId, string $part, int $colorId, int $qty): void
    {
        $existing = $this->rows(
            'SELECT id, qty FROM loose_lot
             WHERE storage_id = ? AND part = ? AND color_id = ? AND source_set_id IS NULL LIMIT 1',
            $storageId,
            $part,
            $colorId
        );
        if ($existing !== []) {
            $b->update('loose_lot', (int) $existing[0]['id'], [
                'qty' => (int) $existing[0]['qty'] + $qty,
                'updated_at' => self::now(),
            ]);
        } else {
            $b->insert('loose_lot', [
                'collection_id' => $collectionId,
                'storage_id' => $storageId,
                'part' => $part,
                'color_id' => $colorId,
                'qty' => $qty,
                'updated_at' => self::now(),
            ]);
        }
        $b->update('storage', $storageId, ['updated_at' => self::now()]);
    }

    /** @param array<string, mixed> $lot */
    private function reduceLot(Batch $b, array $lot, int $qty): void
    {
        $left = (int) $lot['qty'] - $qty;
        if ($left <= 0) {
            $b->delete('loose_lot', (int) $lot['id']);
        } else {
            $b->update('loose_lot', (int) $lot['id'], ['qty' => $left, 'updated_at' => self::now()]);
        }
    }

    /** @return array<string, mixed> */
    private function requireCollection(int $id): array
    {
        return $this->queries->collection($id) ?? throw new \DomainException('not_found');
    }

    /** @return array<string, mixed> */
    private function requireBox(int $id): array
    {
        return $this->queries->box($id) ?? throw new \DomainException('not_found');
    }

    /** @return array<string, mixed> */
    private function requireLot(int $id): array
    {
        return $this->queries->lot($id) ?? throw new \DomainException('not_found');
    }

    /** @return list<int> */
    private function ids(string $sql, int|string ...$params): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->rows($sql, ...$params));
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, int|string ...$params): array
    {
        return $this->queries->select($sql, $params);
    }

    public static function name(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            throw new \DomainException('name_required');
        }

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    private static function quantity(int $qty): int
    {
        if ($qty < 1 || $qty > 100000) {
            throw new \DomainException('invalid_quantity');
        }

        return $qty;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
