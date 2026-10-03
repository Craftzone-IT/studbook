<?php

declare(strict_types=1);

namespace Studbook\Database;

use PDO;
use RuntimeException;

/**
 * Applies `migrations/NNNN_description.sql` files in order and records them
 * in `schema_migration`. Applied migrations must never be edited; a changed
 * checksum is reported as an error.
 *
 * Statements in a migration file are separated by a semicolon at the end of
 * a line. MySQL commits DDL implicitly, so a failing migration is not rolled
 * back: fix the database by hand, then re-run.
 */
final class Migrator
{
    private const FILE_PATTERN = '/^(\d{4})_([a-z0-9_]+)\.sql$/';

    public function __construct(private readonly PDO $pdo, private readonly string $directory)
    {
    }

    /**
     * @param callable(string): void|null $log
     * @return list<string> names of the migrations applied in this run
     */
    public function migrate(?callable $log = null): array
    {
        $log ??= static function (string $message): void {
        };
        $this->ensureTable();
        $applied = $this->applied();
        $done = [];

        foreach ($this->available() as $version => $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file);
            if (isset($applied[$version])) {
                if ($applied[$version]['checksum'] !== $checksum) {
                    throw new RuntimeException(sprintf(
                        'Migration %s was changed after it was applied. Never edit an applied migration; '
                        . 'add a new one instead.',
                        $name
                    ));
                }
                continue;
            }

            $log(sprintf('Applying %s', $name));
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException(sprintf('Cannot read %s', $file));
            }
            foreach (self::splitStatements($sql) as $statement) {
                $this->pdo->exec($statement);
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO schema_migration (version, name, checksum, applied_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
            );
            $insert->execute([$version, $name, $checksum]);
            $done[] = $name;
        }

        return $done;
    }

    /**
     * Migrations that have not been applied yet. Does not create anything.
     *
     * @return list<string> file names, in the order they would be applied
     */
    public function pending(): array
    {
        $applied = $this->tableExists() ? $this->applied() : [];
        $pending = [];
        foreach ($this->available() as $version => $file) {
            if (!isset($applied[$version])) {
                $pending[] = basename($file);
            }
        }

        return $pending;
    }

    /** @return array<int, string> version => absolute file path, sorted by version */
    public function available(): array
    {
        $files = [];
        foreach (scandir($this->directory) ?: [] as $entry) {
            if (!preg_match(self::FILE_PATTERN, $entry, $m)) {
                continue;
            }
            $version = (int) $m[1];
            if (isset($files[$version])) {
                throw new RuntimeException(sprintf('Duplicate migration number %04d', $version));
            }
            $files[$version] = $this->directory . '/' . $entry;
        }
        ksort($files);

        return $files;
    }

    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $current .= $line . "\n";
            if (str_ends_with($trimmed, ';')) {
                $statements[] = trim($current);
                $current = '';
            }
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    private function tableExists(): bool
    {
        return $this->pdo->query("SHOW TABLES LIKE 'schema_migration'")->fetchColumn() !== false;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migration (
                version INT UNSIGNED NOT NULL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<int, array{checksum: string}> */
    private function applied(): array
    {
        $rows = $this->pdo->query('SELECT version, checksum FROM schema_migration')->fetchAll(PDO::FETCH_ASSOC);
        $applied = [];
        foreach ($rows as $row) {
            $applied[(int) $row['version']] = ['checksum' => (string) $row['checksum']];
        }

        return $applied;
    }
}
