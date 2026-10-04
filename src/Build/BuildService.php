<?php

declare(strict_types=1);

namespace Studbook\Build;

use PDO;
use Studbook\Owned\Batch;
use Studbook\Owned\BatchService;
use Studbook\Owned\LotWriter;
use Studbook\Owned\OwnedQueries;

/**
 * Builds: a target set being assembled, with parts reserved for it
 * (`allocation`) from loose lots first, then from unlocked sets. Each
 * public method that writes is one batch.
 */
final class BuildService
{
    private readonly LotWriter $lots;

    public function __construct(
        private readonly PDO $pdo,
        private readonly BatchService $batches,
        private readonly OwnedQueries $queries,
    ) {
        $this->lots = new LotWriter($queries);
    }

    /**
     * Starts a build of a catalogue set and reserves what is available.
     *
     * @return array{id: int, batch: int, reserved: int}
     */
    public function start(string $setNum, BuildOptions $options): array
    {
        $set = $this->catalogueSet($setNum) ?? throw new \DomainException('set_unknown');
        $collection = $this->queries->collection($options->home) ?? throw new \DomainException('not_found');

        $work = function (Batch $b) use ($set, $options): array {
            $now = LotWriter::now();
            $id = $b->insert('build', [
                'collection_id' => $options->home,
                'set_num' => $set['set_num'],
                'state' => 'active',
                'options' => json_encode($options->toArray()),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['id' => $id, 'reserved' => $this->reserveInto($b, $id, $set['set_num'], $options)];
        };
        [$result, $batch] = $this->batches->run('batch.build_started', [
            'set' => $set['set_num'],
            'name' => $set['name'],
            'collection' => $collection['name'],
        ], $work);

        return ['id' => $result['id'], 'batch' => $batch, 'reserved' => $result['reserved']];
    }

    /**
     * Reserves more parts for a build (after parts were added or freed).
     *
     * @return array{batch: int, reserved: int}
     */
    public function reserve(int $buildId): array
    {
        $build = $this->requireActive($buildId);
        $options = $this->options($build);

        $work = fn (Batch $b): int => $this->reserveInto($b, $buildId, (string) $build['set_num'], $options);
        [$reserved, $batch] = $this->batches->run('batch.build_reserved', ['set' => $build['set_num']], $work);

        return ['batch' => $batch, 'reserved' => $reserved];
    }

    /** Cancels a build: its reservations are released and the build is removed. */
    public function release(int $buildId): int
    {
        $build = $this->requireActive($buildId);

        $work = function (Batch $b) use ($buildId): void {
            foreach ($this->ids('SELECT id FROM allocation WHERE build_id = ?', $buildId) as $id) {
                $b->delete('allocation', $id);
            }
            $b->delete('build', $buildId);
        };

        return $this->batches->run('batch.build_released', ['set' => $build['set_num']], $work)[1];
    }

    /**
     * Finishes a build: reserved loose parts are taken out of their lots,
     * parts borrowed from sets are recorded as missing there, and optionally
     * the finished model becomes an owned set (built) in the build's collection.
     */
    public function finish(int $buildId, bool $addAsSet): int
    {
        $build = $this->requireActive($buildId);

        $work = function (Batch $b) use ($build, $buildId, $addAsSet): void {
            $now = LotWriter::now();
            foreach ($this->allocations($buildId) as $allocation) {
                $qty = (int) $allocation['qty'];
                if ($allocation['loose_lot_id'] !== null) {
                    $lot = $this->queries->lot((int) $allocation['loose_lot_id']);
                    if ($lot !== null) {
                        $this->lots->reduce($b, $lot, min($qty, (int) $lot['qty']));
                    }
                } elseif ($allocation['owned_set_id'] !== null) {
                    $this->borrowFromSet($b, (int) $allocation['owned_set_id'], $allocation, $qty);
                }
                $b->delete('allocation', (int) $allocation['id']);
            }
            $b->update('build', $buildId, ['state' => 'done', 'updated_at' => $now]);
            if ($addAsSet) {
                $b->insert('owned_set', [
                    'collection_id' => (int) $build['collection_id'],
                    'set_num' => $build['set_num'],
                    'state' => 'built',
                    'lock_mode' => 'locked',
                    'storage_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        };

        return $this->batches->run('batch.build_finished', ['set' => $build['set_num']], $work)[1];
    }

    /** @return array<string, mixed>|null a build with its set and collection names */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, s.name, s.year, s.num_parts, c.name AS collection_name
             FROM build b JOIN collection c ON c.id = b.collection_id
             LEFT JOIN cat_set s ON s.set_num = b.set_num WHERE b.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> active builds with reserved and needed part counts */
    public function active(): array
    {
        $rows = $this->pdo->query(
            "SELECT b.*, s.name, s.need_qty, c.name AS collection_name,
                (SELECT COALESCE(SUM(a.qty), 0) FROM allocation a WHERE a.build_id = b.id) AS reserved
             FROM build b JOIN collection c ON c.id = b.collection_id
             LEFT JOIN cat_set s ON s.set_num = b.set_num
             WHERE b.state = 'active' ORDER BY b.created_at DESC, b.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    public function options(array $build): BuildOptions
    {
        $stored = json_decode((string) $build['options'], true);

        return BuildOptions::from(is_array($stored) ? $stored : [], $this->queries->activeCollections())
            ?? new BuildOptions((int) $build['collection_id']);
    }

    /**
     * What the build needs per group and colour, how much is reserved and what is missing.
     *
     * @return list<array{g: string, part: string, color_id: int, need: int, reserved: int, missing: int}>
     */
    public function progress(int $buildId): array
    {
        $build = $this->find($buildId) ?? throw new \DomainException('not_found');
        $options = $this->options($build);
        $reserved = [];
        foreach ($this->allocations($buildId) as $a) {
            $key = $this->group((string) $a['part'], $options) . '|' . $a['color_id'];
            $reserved[$key] = ($reserved[$key] ?? 0) + (int) $a['qty'];
        }
        $rows = [];
        foreach ($this->needs((string) $build['set_num'], $options) as $key => $need) {
            $done = min($need['need'], $reserved[$key] ?? 0);
            $rows[] = [
                'g' => $need['g'],
                'part' => $need['part'],
                'color_id' => $need['color_id'],
                'need' => $need['need'],
                'reserved' => $done,
                'missing' => $need['need'] - $done,
            ];
        }

        return $rows;
    }

    /**
     * Reserved parts grouped by where to fetch them: boxes for loose parts, sets for borrowed ones.
     *
     * @return list<array{kind: string, id: ?int, name: string, collection: string, items: list<array<string, mixed>>}>
     */
    public function pickList(int $buildId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, p.bl_num, p.name AS part_name, COALESCE(c.bl_name, c.name) AS color_name, c.rgb,
                l.storage_id, l.qty AS lot_qty, st.name AS box_name, lc.name AS lot_collection,
                o.set_num AS from_set, o.storage_id AS set_box_id, sb.name AS set_box_name, oc.name AS set_collection
             FROM allocation a
             LEFT JOIN cat_part p ON p.rb_num = a.part
             LEFT JOIN cat_color c ON c.rb_id = a.color_id
             LEFT JOIN loose_lot l ON l.id = a.loose_lot_id
             LEFT JOIN storage st ON st.id = l.storage_id
             LEFT JOIN collection lc ON lc.id = l.collection_id
             LEFT JOIN owned_set o ON o.id = a.owned_set_id
             LEFT JOIN storage sb ON sb.id = o.storage_id
             LEFT JOIN collection oc ON oc.id = o.collection_id
             WHERE a.build_id = ?
             ORDER BY COALESCE(p.bl_num, a.part), color_name'
        );
        $stmt->execute([$buildId]);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['loose_lot_id'] !== null) {
                $key = 'box:' . ($row['storage_id'] ?? 'gone');
                $groups[$key] ??= [
                    'kind' => 'box',
                    'id' => $row['storage_id'] !== null ? (int) $row['storage_id'] : null,
                    'name' => (string) ($row['box_name'] ?? ''),
                    'collection' => (string) ($row['lot_collection'] ?? ''),
                    'items' => [],
                ];
            } else {
                $key = 'set:' . $row['owned_set_id'];
                $groups[$key] ??= [
                    'kind' => 'set',
                    'id' => $row['owned_set_id'] !== null ? (int) $row['owned_set_id'] : null,
                    'name' => trim((string) $row['from_set'] . ' '
                        . ($row['set_box_name'] !== null ? '(' . $row['set_box_name'] . ')' : '')),
                    'collection' => (string) ($row['set_collection'] ?? ''),
                    'items' => [],
                ];
            }
            $groups[$key]['items'][] = $row;
        }
        uasort($groups, static fn (array $a, array $b): int => [$a['kind'], $a['name']] <=> [$b['kind'], $b['name']]);

        return array_values($groups);
    }

    /**
     * Reserves free parts for the rest of what the build needs; returns the number of parts reserved.
     */
    private function reserveInto(Batch $b, int $buildId, string $setNum, BuildOptions $options): int
    {
        $needs = $this->needs($setNum, $options);
        foreach ($this->allocations($buildId) as $a) {
            $key = $this->group((string) $a['part'], $options) . '|' . $a['color_id'];
            if (isset($needs[$key])) {
                $needs[$key]['need'] -= (int) $a['qty'];
            }
        }
        $needs = array_filter($needs, static fn (array $n): bool => $n['need'] > 0);
        if ($needs === []) {
            return 0;
        }
        $members = $this->members(array_values(array_unique(array_column($needs, 'g'))), $options);
        $parts = array_map('strval', array_keys($members));
        $sources = [...$this->freeLots($options, $parts), ...$this->freeSetParts($options, $parts)];

        $now = LotWriter::now();
        $reserved = 0;
        foreach ($sources as $source) {
            $key = $members[$source['part']] . '|' . $source['color_id'];
            if (!isset($needs[$key]) || $needs[$key]['need'] <= 0 || $source['free'] <= 0) {
                continue;
            }
            $qty = min($needs[$key]['need'], $source['free']);
            $b->insert('allocation', [
                'build_id' => $buildId,
                'part' => $source['part'],
                'color_id' => $source['color_id'],
                'qty' => $qty,
                'loose_lot_id' => $source['lot'],
                'owned_set_id' => $source['set'],
                'updated_at' => $now,
            ]);
            $needs[$key]['need'] -= $qty;
            $reserved += $qty;
        }
        $b->update('build', $buildId, ['updated_at' => $now]);

        return $reserved;
    }

    /**
     * @return array<string, array{g: string, part: string, color_id: int, need: int}> keyed 'group|colour'
     */
    private function needs(string $setNum, BuildOptions $options): array
    {
        $group = PartEquivalence::groupExpression($options->mode, 'c', 'i.part');
        $figs = $options->minifigs ? '' : ' AND i.from_minifig = 0';
        (new PartEquivalence($this->pdo))->ensure();
        $stmt = $this->pdo->prepare(
            "SELECT {$group} AS g, MIN(i.part) AS part, i.color_id, SUM(i.quantity) AS need
             FROM cat_inventory i LEFT JOIN cat_part_canon c ON c.part = i.part
             WHERE i.set_num = ? AND i.is_spare = 0{$figs}
             GROUP BY g, i.color_id ORDER BY part, i.color_id"
        );
        $stmt->execute([$setNum]);
        $needs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $needs[$row['g'] . '|' . $row['color_id']] = [
                'g' => (string) $row['g'],
                'part' => (string) $row['part'],
                'color_id' => (int) $row['color_id'],
                'need' => (int) $row['need'],
            ];
        }

        return $needs;
    }

    /**
     * @param list<string> $groups
     * @return array<string, string> part => group, for every part of the groups
     */
    private function members(array $groups, BuildOptions $options): array
    {
        $members = [];
        foreach ($groups as $g) {
            $members[$g] = $g;
        }
        if ($options->mode !== 'exact' && $groups !== []) {
            $column = $options->mode === 'print' ? 'print' : 'variant';
            foreach (array_chunk($groups, 500) as $chunk) {
                $stmt = $this->pdo->prepare(sprintf(
                    "SELECT part, {$column} FROM cat_part_canon WHERE {$column} IN (%s)",
                    implode(',', array_fill(0, count($chunk), '?'))
                ));
                $stmt->execute($chunk);
                foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$part, $g]) {
                    $members[(string) $part] = (string) $g;
                }
            }
        }

        // PHP turns numeric part numbers into integer keys; callers compare as strings.
        return array_combine(array_map('strval', array_keys($members)), array_values($members));
    }

    /**
     * Free quantities of loose lots of the given parts (not reserved by any build), exact part first.
     *
     * @param list<string> $parts
     * @return list<array{part: string, color_id: int, free: int, lot: ?int, set: ?int}>
     */
    private function freeLots(BuildOptions $options, array $parts): array
    {
        $collections = $options->collections();
        $sources = [];
        foreach (array_chunk($parts, 500) as $chunk) {
            $stmt = $this->pdo->prepare(sprintf(
                'SELECT l.id, l.part, l.color_id,
                    CAST(l.qty AS SIGNED)
                        - COALESCE((SELECT SUM(a.qty) FROM allocation a WHERE a.loose_lot_id = l.id), 0) AS free
                 FROM loose_lot l JOIN storage s ON s.id = l.storage_id
                 WHERE l.collection_id IN (%s) AND l.part IN (%s)
                 ORDER BY s.name, l.id',
                implode(',', array_fill(0, count($collections), '?')),
                implode(',', array_fill(0, count($chunk), '?'))
            ));
            $stmt->execute([...$collections, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sources[] = [
                    'part' => (string) $row['part'],
                    'color_id' => (int) $row['color_id'],
                    'free' => (int) $row['free'],
                    'lot' => (int) $row['id'],
                    'set' => null,
                ];
            }
        }

        return $sources;
    }

    /**
     * Free parts of unlocked sets: contents (inventory without spares, plus deltas) minus reservations.
     *
     * @param list<string> $parts
     * @return list<array{part: string, color_id: int, free: int, lot: ?int, set: ?int}>
     */
    private function freeSetParts(BuildOptions $options, array $parts): array
    {
        $sets = (new Pool($this->pdo))->lendableSets($options);
        if ($sets === [] || $parts === []) {
            return [];
        }
        $setList = implode(',', $sets);
        $sources = [];
        foreach (array_chunk($parts, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT owned_set_id, part, color_id, SUM(qty) AS free FROM (
                    SELECT o.id AS owned_set_id, i.part, i.color_id, i.quantity AS qty FROM owned_set o
                    JOIN cat_inventory i ON i.set_num = o.set_num AND i.is_spare = 0
                    WHERE o.id IN ({$setList}) AND i.part IN ({$marks})
                    UNION ALL
                    SELECT owned_set_id, part, color_id, qty FROM owned_set_delta
                    WHERE owned_set_id IN ({$setList}) AND part IN ({$marks})
                    UNION ALL
                    SELECT owned_set_id, part, color_id, -CAST(qty AS SIGNED) FROM allocation
                    WHERE owned_set_id IN ({$setList}) AND part IN ({$marks})
                 ) x GROUP BY owned_set_id, part, color_id HAVING SUM(qty) > 0
                 ORDER BY owned_set_id, part, color_id"
            );
            $stmt->execute([...$chunk, ...$chunk, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sources[] = [
                    'part' => (string) $row['part'],
                    'color_id' => (int) $row['color_id'],
                    'free' => (int) $row['free'],
                    'lot' => null,
                    'set' => (int) $row['owned_set_id'],
                ];
            }
        }

        return $sources;
    }

    /** @param array<string, mixed> $allocation */
    private function borrowFromSet(Batch $b, int $setId, array $allocation, int $qty): void
    {
        if ($this->queries->ownedSet($setId) === null) {
            return;
        }
        $existing = $this->queries->select(
            'SELECT id, qty FROM owned_set_delta WHERE owned_set_id = ? AND part = ? AND color_id = ?',
            [$setId, (string) $allocation['part'], (int) $allocation['color_id']]
        );
        $now = LotWriter::now();
        if ($existing === []) {
            $b->insert('owned_set_delta', [
                'owned_set_id' => $setId,
                'part' => (string) $allocation['part'],
                'color_id' => (int) $allocation['color_id'],
                'qty' => -$qty,
                'updated_at' => $now,
            ]);
        } elseif ((int) $existing[0]['qty'] - $qty === 0) {
            $b->delete('owned_set_delta', (int) $existing[0]['id']);
        } else {
            $b->update('owned_set_delta', (int) $existing[0]['id'], [
                'qty' => (int) $existing[0]['qty'] - $qty,
                'updated_at' => $now,
            ]);
        }
        $b->update('owned_set', $setId, ['updated_at' => $now]);
    }

    private function group(string $part, BuildOptions $options): string
    {
        if ($options->mode === 'exact') {
            return $part;
        }
        $column = $options->mode === 'print' ? 'print' : 'variant';
        $stmt = $this->pdo->prepare("SELECT {$column} FROM cat_part_canon WHERE part = ?");
        $stmt->execute([$part]);
        $g = $stmt->fetchColumn();

        return is_string($g) ? $g : $part;
    }

    /** @return list<array<string, mixed>> */
    private function allocations(int $buildId): array
    {
        return $this->queries->select('SELECT * FROM allocation WHERE build_id = ? ORDER BY id', [$buildId]);
    }

    /** @return array<string, mixed> */
    private function requireActive(int $id): array
    {
        $build = $this->find($id);
        if ($build === null) {
            throw new \DomainException('not_found');
        }
        if ($build['state'] !== 'active') {
            throw new \DomainException('build_finished');
        }

        return $build;
    }

    /** @return array{set_num: string, name: string}|null */
    private function catalogueSet(string $setNum): ?array
    {
        $stmt = $this->pdo->prepare('SELECT set_num, name FROM cat_set WHERE set_num = ?');
        $stmt->execute([$setNum]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['set_num' => (string) $row['set_num'], 'name' => (string) $row['name']] : null;
    }

    /** @return list<int> */
    private function ids(string $sql, int $param): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->queries->select($sql, [$param]));
    }
}
