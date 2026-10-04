<?php

declare(strict_types=1);

namespace Studbook\Owned;

use PDO;

/**
 * One undo unit. All writes to owned data go through a batch, which stamps
 * each row with its id and journals the row before and after the change in
 * `batch_change`, so {@see BatchService::revert()} can undo the whole batch.
 */
final class Batch
{
    /** Owned tables and the columns a batch may write (identifiers are never taken from input). */
    public const TABLES = [
        'collection' => ['name', 'can_lend', 'archived_at', 'created_at', 'updated_at'],
        'storage' => ['collection_id', 'name', 'type', 'created_at', 'updated_at'],
        'storage_label' => ['storage_id', 'part', 'position'],
        'loose_lot' => ['collection_id', 'storage_id', 'part', 'color_id', 'qty', 'source_set_id', 'updated_at'],
        'owned_set' => ['collection_id', 'set_num', 'state', 'lock_mode', 'storage_id', 'created_at', 'updated_at'],
        'owned_set_delta' => ['owned_set_id', 'part', 'color_id', 'qty', 'updated_at'],
    ];

    public function __construct(private readonly PDO $pdo, public readonly int $id)
    {
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $data = $this->filter($table, $data) + ['batch_id' => $this->id];
        $columns = array_keys($data);
        $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        ))->execute(array_values($data));
        $id = (int) $this->pdo->lastInsertId();
        $this->journal($table, $id, 'insert', null, $this->fetch($table, $id));

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(string $table, int $id, array $data): void
    {
        $before = $this->fetch($table, $id);
        if ($before === null) {
            throw new \RuntimeException(sprintf('%s #%d does not exist.', $table, $id));
        }
        $data = $this->filter($table, $data) + ['batch_id' => $this->id];
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($data)));
        $this->pdo->prepare(sprintf('UPDATE %s SET %s WHERE id = ?', $table, $set))
            ->execute([...array_values($data), $id]);
        $this->journal($table, $id, 'update', $before, $this->fetch($table, $id));
    }

    public function delete(string $table, int $id): void
    {
        $before = $this->fetch($table, $id);
        if ($before === null) {
            return;
        }
        $this->pdo->prepare(sprintf('DELETE FROM %s WHERE id = ?', $table))->execute([$id]);
        $this->journal($table, $id, 'delete', $before, null);
    }

    /** @return array<string, mixed>|null */
    public function fetch(string $table, int $id): ?array
    {
        self::assertTable($table);
        $stmt = $this->pdo->prepare(sprintf('SELECT * FROM %s WHERE id = ?', $table));
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function assertTable(string $table): void
    {
        if (!isset(self::TABLES[$table])) {
            throw new \InvalidArgumentException(sprintf('Table %s is not an owned-data table.', $table));
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function filter(string $table, array $data): array
    {
        self::assertTable($table);
        $unknown = array_diff(array_keys($data), self::TABLES[$table]);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                sprintf('Unknown column(s) for %s: %s', $table, implode(', ', $unknown))
            );
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function journal(string $table, int $id, string $action, ?array $before, ?array $after): void
    {
        $this->pdo->prepare(
            'INSERT INTO batch_change (batch_id, table_name, row_id, action, before_data, after_data)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->id,
            $table,
            $id,
            $action,
            $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
