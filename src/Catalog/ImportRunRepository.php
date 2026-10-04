<?php

declare(strict_types=1);

namespace Studbook\Catalog;

use PDO;

/** Rows of `import_run`: queued, running and finished catalogue imports. */
final class ImportRunRepository
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const SUCCESS = 'success';
    public const FAILED = 'failed';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Queues a run unless one is already queued; returns its id. */
    public function queue(string $trigger): int
    {
        $open = $this->pdo->query(
            "SELECT id FROM import_run WHERE status = 'queued' ORDER BY id LIMIT 1"
        )->fetchColumn();
        if ($open !== false) {
            return (int) $open;
        }
        $this->pdo->prepare(
            'INSERT INTO import_run (trigger_type, status, requested_at) VALUES (?, ?, UTC_TIMESTAMP())'
        )->execute([$trigger, self::QUEUED]);

        return (int) $this->pdo->lastInsertId();
    }

    public function nextQueued(): ?int
    {
        $id = $this->pdo->query(
            "SELECT id FROM import_run WHERE status = 'queued' ORDER BY id LIMIT 1"
        )->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Marks a run as running; `$startedBy` replaces the trigger when someone else takes over a queued run. */
    public function start(int $id, ?string $startedBy = null): void
    {
        if ($startedBy === null) {
            $this->pdo->prepare(
                'UPDATE import_run SET status = ?, started_at = UTC_TIMESTAMP() WHERE id = ?'
            )->execute([self::RUNNING, $id]);

            return;
        }
        $this->pdo->prepare(
            'UPDATE import_run SET status = ?, started_at = UTC_TIMESTAMP(), trigger_type = ? WHERE id = ?'
        )->execute([self::RUNNING, $startedBy, $id]);
    }

    /** @param array<string, mixed> $stats */
    public function finish(int $id, string $status, string $log, array $stats): void
    {
        $this->pdo->prepare(
            'UPDATE import_run SET status = ?, finished_at = UTC_TIMESTAMP(), log = ?, stats = ? WHERE id = ?'
        )->execute([$status, $log, json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    }

    /** Marks runs left in "running" by a crashed process as failed (call while holding the lock). */
    public function failAbandoned(): void
    {
        $this->pdo->prepare(
            "UPDATE import_run SET status = 'failed', finished_at = UTC_TIMESTAMP(),
             log = CONCAT(COALESCE(log, ''), ?) WHERE status = 'running'"
        )->execute(["\nThe import process stopped unexpectedly (timeout or crash)."]);
    }

    public function lastSuccessAt(): ?\DateTimeImmutable
    {
        $at = $this->pdo->query(
            "SELECT MAX(finished_at) FROM import_run WHERE status = 'success'"
        )->fetchColumn();

        return is_string($at) ? new \DateTimeImmutable($at, new \DateTimeZone('UTC')) : null;
    }

    /** @return list<array<string, mixed>> newest first */
    public function latest(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, trigger_type, status, requested_at, started_at, finished_at, log, stats
             FROM import_run ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute();
        $runs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['stats'] = is_string($row['stats']) ? (json_decode($row['stats'], true) ?: []) : [];
            $runs[] = $row;
        }

        return $runs;
    }
}
