<?php

declare(strict_types=1);

namespace Studbook\Tests\Owned;

use PDO;
use Studbook\Owned\BatchService;
use Studbook\Owned\OwnedQueries;
use Studbook\Owned\OwnedService;
use Studbook\Tests\DatabaseTestCase;

/** Migrated test database with a tiny catalogue (3001, 3024; colours 0, 4, 71). */
abstract class OwnedTestCase extends DatabaseTestCase
{
    protected BatchService $batches;
    protected OwnedQueries $queries;
    protected OwnedService $owned;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->pdo->exec("INSERT INTO cat_part (rb_num, name, category_id, bl_num) VALUES
            ('3001', 'Brick 2 x 4', 11, '3001'), ('3024', 'Plate 1 x 1', 14, '3024'),
            ('3794b', 'Plate Special 1 x 2', 9, '15573'), ('3001pr0001', 'Brick 2 x 4 with Print', 11, NULL)");
        $this->pdo->exec("INSERT INTO cat_color (rb_id, name, rgb, is_trans, bl_id, bl_name) VALUES
            (0, 'Black', '05131D', 0, 11, 'Black'), (4, 'Red', 'C91A09', 0, 5, 'Red'),
            (71, 'Light Bluish Gray', 'A0A5A9', 0, 86, 'Light Bluish Gray')");
        $this->pdo->exec("INSERT INTO cat_part_color (part, color_id, img_url) VALUES
            ('3001', 4, 'https://cdn.rebrickable.com/media/parts/3001-4.jpg'), ('3001', 0, NULL), ('3024', 71, NULL)");
        $this->batches = new BatchService($this->pdo);
        $this->queries = new OwnedQueries($this->pdo);
        $this->owned = new OwnedService($this->batches, $this->queries);
    }

    protected function rowCount(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    protected function all(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
