<?php

declare(strict_types=1);

namespace Studbook\Catalog;

use PDO;

/** Buffers rows and writes them with multi-row INSERT statements. */
final class BulkInserter
{
    /** @var list<list<mixed>> */
    private array $rows = [];
    private int $total = 0;

    /** @param list<string> $columns */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table,
        private readonly array $columns,
        private readonly string $verb = 'INSERT',
        private readonly string $suffix = '',
        private readonly int $batchSize = 1000,
    ) {
    }

    /** @param list<mixed> $row */
    public function add(array $row): void
    {
        $this->rows[] = $row;
        if (count($this->rows) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function flush(): int
    {
        if ($this->rows !== []) {
            $placeholders = '(' . implode(',', array_fill(0, count($this->columns), '?')) . ')';
            $sql = sprintf(
                '%s INTO %s (%s) VALUES %s %s',
                $this->verb,
                $this->table,
                implode(',', $this->columns),
                implode(',', array_fill(0, count($this->rows), $placeholders)),
                $this->suffix
            );
            $this->pdo->prepare($sql)->execute(array_merge(...$this->rows));
            $this->total += count($this->rows);
            $this->rows = [];
        }

        return $this->total;
    }
}
