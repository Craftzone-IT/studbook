<?php

declare(strict_types=1);

namespace Studbook\Owned;

/** Writes loose lots inside a batch; shared by the box and set services. */
final class LotWriter
{
    public function __construct(private readonly OwnedQueries $queries)
    {
    }

    /** Adds parts to a box, merging with an existing lot of the same part and colour. */
    public function put(Batch $b, int $collectionId, int $storageId, string $part, int $colorId, int $qty): void
    {
        $existing = $this->queries->select(
            'SELECT id, qty FROM loose_lot
             WHERE storage_id = ? AND part = ? AND color_id = ? AND source_set_id IS NULL LIMIT 1',
            [$storageId, $part, $colorId]
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
    public function reduce(Batch $b, array $lot, int $qty): void
    {
        $left = (int) $lot['qty'] - $qty;
        if ($left <= 0) {
            $b->delete('loose_lot', (int) $lot['id']);
        } else {
            $b->update('loose_lot', (int) $lot['id'], ['qty' => $left, 'updated_at' => self::now()]);
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
