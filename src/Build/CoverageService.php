<?php

declare(strict_types=1);

namespace Studbook\Build;

use PDO;

/**
 * "What can I build?": how much of each catalogue set the available parts
 * cover, first from loose parts, then from unlocked sets.
 *
 * Coverage of a set = Σ over (group, colour) of min(needed, available);
 * each set is evaluated on its own (sets do not compete for parts). Only
 * sets that share at least one part with the pool are computed; the scan is
 * cached per options, owned-data version and catalogue version.
 */
final class CoverageService
{
    private const CACHE_FILES_KEPT = 20;
    /** Above this many matching inventory rows the scan streams instead of aggregating in memory. */
    public const IN_MEMORY_ROWS = 750000;

    public function __construct(private readonly PDO $pdo, private readonly string $cacheDirectory)
    {
    }

    /**
     * Coverage of every set that the pool touches.
     *
     * @return array<string, array{need: int, loose: int, total: int, any: ?int}> keyed by 's:' . set number
     */
    public function scan(BuildOptions $options): array
    {
        $key = sha1((string) json_encode([$options->toArray(), $this->version()]));
        $file = $this->cacheDirectory . '/coverage-' . $key . '.json';
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                return $data;
            }
        }

        (new Pool($this->pdo))->create($options);
        $needs = $this->needs($options);
        $pool = [];
        foreach ($this->pdo->query('SELECT g, color_id, loose, sets FROM tmp_pool')->fetchAll(PDO::FETCH_NUM) as $p) {
            $pool[$p[0] . '|' . $p[1]] = [(int) $p[2], (int) $p[2] + (int) $p[3]];
        }
        $covered = $this->cover('tmp_member', 'i.color_id', 'AND i.color_id = m.color_id', $options, $pool);
        $any = [];
        if ($options->anyColor) {
            $poolAny = [];
            foreach ($this->pdo->query('SELECT g, loose, sets FROM tmp_pool_any')->fetchAll(PDO::FETCH_NUM) as $p) {
                $poolAny[$p[0] . '|0'] = [(int) $p[1], (int) $p[1] + (int) $p[2]];
            }
            $any = $this->cover('tmp_member_any', '0', '', $options, $poolAny);
        }

        $result = [];
        foreach ($covered as $key => [$loose, $total]) {
            if (!isset($needs[$key]) || $needs[$key] <= 0) {
                continue; // minifigs and other non-sets, or sets without parts
            }
            $result[$key] = [
                'need' => $needs[$key],
                'loose' => min($needs[$key], $loose),
                'total' => min($needs[$key], $total),
                'any' => $options->anyColor ? min($needs[$key], $any[$key][1] ?? 0) : null,
            ];
        }
        $this->store($file, $result);

        return $result;
    }

    /**
     * Part by part coverage of one set.
     *
     * @return list<array{g: string, part: string, parts: list<string>, color_id: int, need: int,
     *     loose: int, sets: int, missing: int}>
     */
    public function target(string $setNum, BuildOptions $options): array
    {
        (new Pool($this->pdo))->create($options);
        $group = PartEquivalence::groupExpression($options->mode, 'c', 'i.part');
        $figs = $options->minifigs ? '' : ' AND i.from_minifig = 0';
        $stmt = $this->pdo->prepare(
            "SELECT {$group} AS g, i.part, i.color_id, SUM(i.quantity) AS need
             FROM cat_inventory i LEFT JOIN cat_part_canon c ON c.part = i.part
             WHERE i.set_num = ? AND i.is_spare = 0{$figs}
             GROUP BY g, i.part, i.color_id ORDER BY i.part, i.color_id"
        );
        $stmt->execute([$setNum]);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = 'g:' . $row['g'] . '|' . $row['color_id'];
            $groups[$key] ??= [
                'g' => (string) $row['g'],
                'part' => (string) $row['part'],
                'parts' => [],
                'color_id' => (int) $row['color_id'],
                'need' => 0,
            ];
            $groups[$key]['parts'][] = (string) $row['part'];
            $groups[$key]['need'] += (int) $row['need'];
        }
        $pool = [];
        foreach ($this->pdo->query('SELECT g, color_id, loose, sets FROM tmp_pool')->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $pool['g:' . $p['g'] . '|' . $p['color_id']] = [(int) $p['loose'], (int) $p['sets']];
        }

        $rows = [];
        foreach ($groups as $key => $row) {
            [$loose, $sets] = $pool[$key] ?? [0, 0];
            $fromLoose = min($row['need'], $loose);
            $fromSets = min($row['need'] - $fromLoose, $sets);
            $rows[] = $row + [
                'loose' => $fromLoose,
                'sets' => $fromSets,
                'missing' => $row['need'] - $fromLoose - $fromSets,
            ];
        }

        return $rows;
    }

    /**
     * Sums min(needed, available) per set over the inventory rows of pooled parts.
     *
     * Small pools are joined through the covering (part, colour) index and
     * aggregated in memory; large ones stream the whole inventory in
     * primary-key order (sorted by set), so memory stays flat.
     *
     * @param array<string, array{0: int, 1: int}> $pool 'group|colour' => [loose, loose + sets]
     * @return array<string, array{0: int, 1: int}> 's:' . set number => [covered by loose, covered in total]
     */
    private function cover(string $members, string $color, string $colorJoin, BuildOptions $options, array $pool): array
    {
        if ($pool === []) {
            return [];
        }
        $figs = $options->minifigs ? '' : ' AND i.from_minifig = 0';
        $rows = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM {$members} m
             JOIN cat_inventory i ON i.part = m.part {$colorJoin} AND i.is_spare = 0{$figs}"
        )->fetchColumn();
        $sql = $rows <= self::IN_MEMORY_ROWS
            ? "SELECT i.set_num, m.g, {$color}, i.quantity FROM {$members} m
               JOIN cat_inventory i ON i.part = m.part {$colorJoin} AND i.is_spare = 0{$figs}"
            : "SELECT STRAIGHT_JOIN i.set_num, m.g, {$color}, i.quantity FROM cat_inventory i
               JOIN {$members} m ON m.part = i.part {$colorJoin}
               WHERE i.is_spare = 0{$figs} ORDER BY i.set_num";

        $result = [];
        $flush = static function (string $set, array $need) use (&$result, $pool): void {
            $loose = 0;
            $total = 0;
            foreach ($need as $key => $qty) {
                $loose += min($qty, $pool[$key][0]);
                $total += min($qty, $pool[$key][1]);
            }
            $result['s:' . $set] = [$loose, $total];
        };
        $buffered = $this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $stmt = $this->pdo->query($sql, PDO::FETCH_NUM);
            if ($rows <= self::IN_MEMORY_ROWS) {
                $needs = [];
                foreach ($stmt as [$set, $g, $c, $qty]) {
                    $needs[$set][$g . '|' . $c] = ($needs[$set][$g . '|' . $c] ?? 0) + (int) $qty;
                }
                foreach ($needs as $set => $need) {
                    $flush((string) $set, $need);
                }
            } else {
                $current = null;
                $need = [];
                foreach ($stmt as [$set, $g, $c, $qty]) {
                    if ($set !== $current) {
                        if ($current !== null) {
                            $flush($current, $need);
                        }
                        $current = (string) $set;
                        $need = [];
                    }
                    $need[$g . '|' . $c] = ($need[$g . '|' . $c] ?? 0) + (int) $qty;
                }
                if ($current !== null) {
                    $flush($current, $need);
                }
            }
            $stmt->closeCursor();
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }

        return $result;
    }

    /** @return array<string, int> 's:' . set number => parts needed under these options */
    private function needs(BuildOptions $options): array
    {
        $column = $options->minifigs ? 'need_qty' : 'need_qty - need_fig_qty';
        $needs = [];
        foreach ($this->pdo->query("SELECT set_num, {$column} FROM cat_set")->fetchAll(PDO::FETCH_NUM) as [$set, $n]) {
            $needs['s:' . $set] = (int) $n;
        }

        return $needs;
    }

    /** Changes whenever owned data (any batch, including an undo) or the catalogue changes. */
    private function version(): string
    {
        $owned = $this->pdo->query('SELECT COALESCE(MAX(id), 0), COUNT(reverted_at) FROM batch')->fetch(PDO::FETCH_NUM);
        $catalogue = $this->pdo->query("SELECT COALESCE(MAX(id), 0) FROM import_run WHERE status = 'success'")
            ->fetchColumn();

        return implode('-', [...array_map('strval', (array) $owned), (string) $catalogue]);
    }

    /** @param array<string, mixed> $result */
    private function store(string $file, array $result): void
    {
        if (!is_dir($this->cacheDirectory) && !@mkdir($this->cacheDirectory, 0775, true)) {
            return;
        }
        @file_put_contents($file, json_encode($result));
        $files = glob($this->cacheDirectory . '/coverage-*.json') ?: [];
        if (count($files) > self::CACHE_FILES_KEPT) {
            usort($files, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));
            foreach (array_slice($files, 0, count($files) - self::CACHE_FILES_KEPT) as $old) {
                @unlink($old);
            }
        }
    }
}
