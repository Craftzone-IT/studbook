<?php

declare(strict_types=1);

namespace Studbook\Owned;

use PDO;

/** Creates batches, runs writes inside them, and reverts them. */
final class BatchService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Runs `$work` inside a transaction with a new batch. Batches that end
     * up writing nothing are removed again.
     *
     * @template T
     * @param array<string, string|int> $params placeholders for the description
     * @param callable(Batch): T $work
     * @return array{0: T, 1: int} the callback's result and the batch id
     */
    public function run(string $descriptionKey, array $params, callable $work): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO batch (created_at, description_key, description_params) VALUES (UTC_TIMESTAMP(), ?, ?)'
            )->execute([$descriptionKey, json_encode($params, JSON_UNESCAPED_UNICODE)]);
            $batch = new Batch($this->pdo, (int) $this->pdo->lastInsertId());
            $result = $work($batch);
            $changes = $this->pdo->prepare('SELECT COUNT(*) FROM batch_change WHERE batch_id = ?');
            $changes->execute([$batch->id]);
            if ((int) $changes->fetchColumn() === 0) {
                $this->pdo->prepare('DELETE FROM batch WHERE id = ?')->execute([$batch->id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [$result, $batch->id];
    }

    /**
     * Adds more writes to an existing batch (an entry session), in one
     * transaction, and replaces its description parameters.
     *
     * @param array<string, string|int> $params
     * @param callable(Batch): void $work
     * @return bool false when the batch does not exist or was reverted (start a new one)
     */
    public function append(int $batchId, array $params, callable $work): bool
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT reverted_at FROM batch WHERE id = ? FOR UPDATE');
            $stmt->execute([$batchId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['reverted_at'] !== null) {
                $this->pdo->rollBack();

                return false;
            }
            $work(new Batch($this->pdo, $batchId));
            $this->pdo->prepare('UPDATE batch SET description_params = ? WHERE id = ?')
                ->execute([json_encode($params, JSON_UNESCAPED_UNICODE), $batchId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Most recent batches for the history page.
     *
     * @return list<array{id: int, created_at: string, description_key: string,
     *     description_params: array<string, string|int>, reverted_at: ?string, changes: int}>
     */
    public function recent(int $limit = 50): array
    {
        $rows = $this->pdo->query(sprintf(
            'SELECT b.id, b.created_at, b.description_key, b.description_params, b.reverted_at,
                (SELECT COUNT(*) FROM batch_change c WHERE c.batch_id = b.id) AS changes
             FROM batch b ORDER BY b.id DESC LIMIT %d',
            max(1, min(500, $limit))
        ))->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $params = json_decode((string) $row['description_params'], true);

            return [
                'id' => (int) $row['id'],
                'created_at' => (string) $row['created_at'],
                'description_key' => (string) $row['description_key'],
                'description_params' => is_array($params) ? $params : [],
                'reverted_at' => $row['reverted_at'] !== null ? (string) $row['reverted_at'] : null,
                'changes' => (int) $row['changes'],
            ];
        }, $rows);
    }

    /** @return array{id: int, description_key: string, description_params: array<string, string|int>, reverted_at: ?string}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, description_key, description_params, reverted_at FROM batch WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $params = json_decode((string) $row['description_params'], true);

        return [
            'id' => (int) $row['id'],
            'description_key' => (string) $row['description_key'],
            'description_params' => is_array($params) ? $params : [],
            'reverted_at' => $row['reverted_at'] !== null ? (string) $row['reverted_at'] : null,
        ];
    }

    /**
     * Undoes every change of a batch, newest first, in one transaction.
     *
     * @throws BatchConflict when a later batch changed the same rows, or the
     *         batch was already reverted
     */
    public function revert(int $batchId): void
    {
        $batch = $this->find($batchId);
        if ($batch === null || $batch['reverted_at'] !== null) {
            throw new BatchConflict('already_reverted');
        }
        $stmt = $this->pdo->prepare(
            'SELECT table_name, row_id, action, before_data FROM batch_change WHERE batch_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$batchId]);
        $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->pdo->beginTransaction();
        try {
            foreach ($changes as $change) {
                $table = (string) $change['table_name'];
                Batch::assertTable($table);
                $rowId = (int) $change['row_id'];
                $current = $this->currentBatch($table, $rowId);
                $before = $change['before_data'] !== null ? json_decode((string) $change['before_data'], true) : null;

                if ($change['action'] === 'insert' || $change['action'] === 'update') {
                    if ($current !== $batchId) {
                        throw new BatchConflict('changed_later');
                    }
                }
                if ($change['action'] === 'delete' && $current !== false) {
                    throw new BatchConflict('changed_later');
                }

                match ($change['action']) {
                    'insert' => $this->pdo->prepare(sprintf('DELETE FROM %s WHERE id = ?', $table))->execute([$rowId]),
                    'update' => $this->restore($table, $rowId, (array) $before, false),
                    'delete' => $this->restore($table, $rowId, (array) $before, true),
                    default => throw new \UnexpectedValueException('Unknown action ' . $change['action']),
                };
            }
            $this->pdo->prepare('UPDATE batch SET reverted_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$batchId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The batch that last wrote a row; false when the row does not exist.
     * Rows are restored in reverse order, so a row this batch wrote twice
     * still carries this batch's id at every step.
     */
    private function currentBatch(string $table, int $id): int|false|null
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT batch_id FROM %s WHERE id = ?', $table));
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return false;
        }

        return $value === null ? null : (int) $value;
    }

    /** @param array<string, mixed> $row */
    private function restore(string $table, int $id, array $row, bool $insert): void
    {
        $columns = array_values(array_intersect(array_keys($row), [...Batch::TABLES[$table], 'batch_id']));
        $values = array_map(static fn (string $c): mixed => $row[$c], $columns);
        if ($insert) {
            $this->pdo->prepare(sprintf(
                'INSERT INTO %s (id, %s) VALUES (?, %s)',
                $table,
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?'))
            ))->execute([$id, ...$values]);

            return;
        }
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $columns));
        $this->pdo->prepare(sprintf('UPDATE %s SET %s WHERE id = ?', $table, $set))->execute([...$values, $id]);
    }
}
