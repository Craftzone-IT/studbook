<?php

declare(strict_types=1);

namespace Studbook\Build;

use PDO;

/**
 * The parts available for building, as temporary tables of this connection:
 *
 * - `tmp_pool (g, color_id, loose, sets)`: free loose parts and free parts of
 *   unlocked sets, per equivalence group `g` and colour;
 * - `tmp_member (part, color_id, g)`: every part that belongs to a pooled
 *   group, so inventories can be joined through their (part, colour) index.
 *
 * With `$anyColor`, `tmp_pool_any (g, loose, sets)` and `tmp_member_any
 * (part, g)` hold the same without colours. "Free" means not reserved by a
 * build (`allocation`).
 */
final class Pool
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(BuildOptions $options): void
    {
        (new PartEquivalence($this->pdo))->ensure();
        $collections = $options->collections();
        $sets = $this->lendableSets($options);
        $in = static fn (array $ids): string => $ids === [] ? 'NULL' : implode(',', array_map('intval', $ids));
        $group = PartEquivalence::groupExpression($options->mode, 'c', 'r.part');
        $member = match ($options->mode) {
            'variant' => 'variant',
            'print' => 'print',
            default => null,
        };

        foreach (['tmp_pool_raw', 'tmp_pool', 'tmp_member', 'tmp_pool_any', 'tmp_member_any'] as $table) {
            $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$table}");
        }
        $this->pdo->exec(
            'CREATE TEMPORARY TABLE tmp_pool_raw (
                part VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, color_id INT NOT NULL,
                loose INT NOT NULL, sets INT NOT NULL, PRIMARY KEY (part, color_id))'
        );
        $this->pdo->exec(sprintf(
            'INSERT INTO tmp_pool_raw (part, color_id, loose, sets)
             SELECT part, color_id, SUM(loose), SUM(sets) FROM (
                 SELECT l.part, l.color_id, GREATEST(CAST(l.qty AS SIGNED) - COALESCE(a.qty, 0), 0) AS loose, 0 AS sets
                 FROM loose_lot l
                 LEFT JOIN (SELECT loose_lot_id, SUM(qty) AS qty FROM allocation
                            WHERE loose_lot_id IS NOT NULL GROUP BY loose_lot_id) a ON a.loose_lot_id = l.id
                 WHERE l.collection_id IN (%1$s)
                 UNION ALL
                 SELECT i.part, i.color_id, 0, i.quantity FROM owned_set o
                 JOIN cat_inventory i ON i.set_num = o.set_num AND i.is_spare = 0
                 WHERE o.id IN (%2$s)
                 UNION ALL
                 SELECT part, color_id, 0, qty FROM owned_set_delta WHERE owned_set_id IN (%2$s)
                 UNION ALL
                 SELECT part, color_id, 0, -CAST(qty AS SIGNED) FROM allocation WHERE owned_set_id IN (%2$s)
             ) x GROUP BY part, color_id',
            $in($collections),
            $in($sets)
        ));

        $this->pdo->exec(
            'CREATE TEMPORARY TABLE tmp_pool (
                g VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, color_id INT NOT NULL,
                loose INT NOT NULL, sets INT NOT NULL, PRIMARY KEY (g, color_id))'
        );
        $this->pdo->exec(
            "INSERT INTO tmp_pool (g, color_id, loose, sets)
             SELECT {$group} AS g, r.color_id, SUM(r.loose), GREATEST(SUM(r.sets), 0)
             FROM tmp_pool_raw r LEFT JOIN cat_part_canon c ON c.part = r.part
             GROUP BY g, r.color_id HAVING SUM(r.loose) + GREATEST(SUM(r.sets), 0) > 0"
        );
        $this->pdo->exec(
            'CREATE TEMPORARY TABLE tmp_member (
                part VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, color_id INT NOT NULL,
                g VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, PRIMARY KEY (part, color_id))'
        );
        // A temporary table cannot be opened twice in one statement, hence two inserts.
        $this->pdo->exec('INSERT IGNORE INTO tmp_member (part, color_id, g) SELECT g, color_id, g FROM tmp_pool');
        if ($member !== null) {
            $this->pdo->exec(
                "INSERT IGNORE INTO tmp_member (part, color_id, g)
                 SELECT c.part, p.color_id, p.g FROM tmp_pool p JOIN cat_part_canon c ON c.{$member} = p.g"
            );
        }

        if ($options->anyColor) {
            $this->pdo->exec(
                'CREATE TEMPORARY TABLE tmp_pool_any (
                    g VARCHAR(64) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY, loose INT NOT NULL, sets INT NOT NULL)'
            );
            $this->pdo->exec(
                'INSERT INTO tmp_pool_any (g, loose, sets) SELECT g, SUM(loose), SUM(sets) FROM tmp_pool GROUP BY g'
            );
            $this->pdo->exec(
                'CREATE TEMPORARY TABLE tmp_member_any (
                    part VARCHAR(64) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
                    g VARCHAR(64) COLLATE utf8mb4_bin NOT NULL)'
            );
            $this->pdo->exec('INSERT IGNORE INTO tmp_member_any (part, g) SELECT part, g FROM tmp_member');
        }
    }

    /** @return list<int> unlocked sets of the collections whose parts may be used */
    public function lendableSets(BuildOptions $options): array
    {
        $ids = $options->collections();
        $stmt = $this->pdo->prepare(sprintf(
            "SELECT o.id FROM owned_set o JOIN collection c ON c.id = o.collection_id
             WHERE o.lock_mode = 'lendable' AND c.archived_at IS NULL AND o.collection_id IN (%s)
             ORDER BY o.id",
            implode(',', array_fill(0, count($ids), '?'))
        ));
        $stmt->execute($ids);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
