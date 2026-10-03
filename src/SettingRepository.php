<?php

declare(strict_types=1);

namespace Studbook;

use PDO;

/** Key/value instance settings stored in the `setting` table. */
final class SettingRepository
{
    public const UI_LANGUAGE = 'ui_language';

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $this->cache ??= $this->loadAll();

        return $this->cache[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $this->pdo->prepare(
            'INSERT INTO setting (`key`, value, updated_at) VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)'
        )->execute([$key, $value]);
        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
    }

    /** @return array<string, string> */
    private function loadAll(): array
    {
        $rows = $this->pdo->query('SELECT `key`, value FROM setting')->fetchAll(PDO::FETCH_KEY_PAIR);

        return array_map('strval', $rows);
    }
}
