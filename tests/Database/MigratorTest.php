<?php

declare(strict_types=1);

namespace Studbook\Tests\Database;

use Studbook\Database\Migrator;
use Studbook\Tests\DatabaseTestCase;

final class MigratorTest extends DatabaseTestCase
{
    public function testAppliesMigrationsOnceAndInOrder(): void
    {
        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');

        $first = $migrator->migrate();
        self::assertNotEmpty($first);
        self::assertSame('0001_initial.sql', $first[0]);
        self::assertSame([], $migrator->migrate());

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('user', $tables);
        self::assertContains('setting', $tables);
        self::assertContains('schema_migration', $tables);
    }

    public function testRefusesEditedMigrations(): void
    {
        $dir = sys_get_temp_dir() . '/studbook-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/0001_a.sql', "CREATE TABLE t_a (id INT);\n");
        file_put_contents($dir . '/0002_b.sql', "CREATE TABLE t_b (id INT);\nINSERT INTO t_b VALUES (1);\n");
        $migrator = new Migrator($this->pdo, $dir);

        try {
            self::assertSame(['0001_a.sql', '0002_b.sql'], $migrator->migrate());
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM t_b')->fetchColumn());

            file_put_contents($dir . '/0001_a.sql', "CREATE TABLE t_a (id BIGINT);\n");
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('changed after it was applied');
            $migrator->migrate();
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }
}
