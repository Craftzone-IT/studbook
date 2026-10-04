<?php

declare(strict_types=1);

namespace Studbook\Tests\Build;

use Studbook\Tests\Owned\OwnedTestCase;

/**
 * Tiny catalogue for coverage tests.
 *
 * Parts: 3001 (Brick 2 x 4) with mould variant 3001a, 3024, 3068b with print 3068bpr0001.
 * Sets: A-1 needs 4 × 3001 red, 2 × 3024 grey and 1 × 3024 grey in a minifig (plus a spare);
 * B-1 needs 1 × 3068bpr0001 black; C-1 needs 2 × 3001a red and 1 × 3001 red;
 * D-1 (to own and lend) contains 5 × 3024 grey.
 */
abstract class BuildTestCase extends OwnedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO cat_part (rb_num, name, category_id, bl_num, popularity) VALUES
            ('3001a', 'Brick 2 x 4 without Cross Supports', 11, NULL, 1),
            ('3068b', 'Tile 2 x 2', 19, '3068b', 50), ('3068bpr0001', 'Tile 2 x 2 with Print', 19, '3068bp01', 1)");
        $this->pdo->exec("UPDATE cat_part SET popularity = 100 WHERE rb_num = '3001'");
        $this->pdo->exec("INSERT INTO cat_part_rel (rel_type, child, parent) VALUES
            ('M', '3001a', '3001'), ('P', '3068bpr0001', '3068b')");
        $this->pdo->exec("INSERT INTO cat_set (set_num, name, year, theme_id, num_parts) VALUES
            ('A-1', 'Set A', 2020, 1, 7), ('B-1', 'Set B', 2021, 2, 1), ('C-1', 'Set C', 2019, 1, 3),
            ('D-1', 'Set D', 2018, 1, 5)");
        $this->pdo->exec("INSERT INTO cat_theme (id, name, parent_id) VALUES (1, 'Town', NULL), (2, 'Space', NULL)");
        $this->pdo->exec("INSERT INTO cat_inventory (set_num, part, color_id, is_spare, from_minifig, quantity) VALUES
            ('A-1', '3001', 4, 0, 0, 4), ('A-1', '3024', 71, 0, 0, 2), ('A-1', '3024', 71, 0, 1, 1),
            ('A-1', '3024', 71, 1, 0, 1), ('B-1', '3068bpr0001', 0, 0, 0, 1),
            ('C-1', '3001a', 4, 0, 0, 2), ('C-1', '3001', 4, 0, 0, 1), ('D-1', '3024', 71, 0, 0, 5)");
        $this->pdo->exec("INSERT INTO cat_part_color (part, color_id) VALUES
            ('3068b', 0), ('3068bpr0001', 0), ('3001a', 4)");
        $this->pdo->exec(
            'UPDATE cat_set s JOIN (SELECT set_num, SUM(quantity) AS n,
                SUM(CASE WHEN from_minifig = 1 THEN quantity ELSE 0 END) AS f
                FROM cat_inventory WHERE is_spare = 0 GROUP BY set_num) x ON x.set_num = s.set_num
             SET s.need_qty = x.n, s.need_fig_qty = x.f'
        );
    }
}
