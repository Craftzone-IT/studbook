<?php

declare(strict_types=1);

namespace Studbook\Tests\Database;

use PHPUnit\Framework\TestCase;
use Studbook\Database\Migrator;

final class MigratorSplitTest extends TestCase
{
    public function testSplitsOnTrailingSemicolonAndSkipsComments(): void
    {
        $sql = "-- header\nCREATE TABLE a (\n  id INT -- inline\n);\n\nINSERT INTO a VALUES (1);\nSELECT 1";

        self::assertSame(
            ["CREATE TABLE a (\n  id INT -- inline\n);", 'INSERT INTO a VALUES (1);', 'SELECT 1'],
            Migrator::splitStatements($sql)
        );
    }

    public function testMigrationFilesAreNumberedWithoutGaps(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [];
        $numbers = array_map(static fn (string $f): int => (int) substr(basename($f), 0, 4), $files);
        sort($numbers);

        self::assertNotEmpty($numbers);
        self::assertSame(range(1, count($numbers)), $numbers);
        foreach ($files as $file) {
            self::assertMatchesRegularExpression('/^\d{4}_[a-z0-9_]+\.sql$/', basename($file));
        }
    }
}
