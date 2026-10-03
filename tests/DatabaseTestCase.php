<?php

declare(strict_types=1);

namespace Studbook\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Studbook\Database\Connection;
use Studbook\Database\Migrator;

/**
 * Base class for tests that need MySQL/MariaDB. They are skipped unless
 * STUDBOOK_TEST_DB_DSN (plus _USER and _PASSWORD) points to a dedicated,
 * disposable test database: every table in it is dropped before each test.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('STUDBOOK_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('STUDBOOK_TEST_DB_DSN is not set; database tests skipped.');
        }
        $this->pdo = Connection::create(
            $dsn,
            (string) getenv('STUDBOOK_TEST_DB_USER'),
            (string) getenv('STUDBOOK_TEST_DB_PASSWORD')
        );
        $this->dropAllTables();
    }

    protected function migrate(): void
    {
        (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
    }

    private function dropAllTables(): void
    {
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->pdo->exec('DROP TABLE `' . str_replace('`', '``', (string) $table) . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
