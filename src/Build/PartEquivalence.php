<?php

declare(strict_types=1);

namespace Studbook\Build;

use PDO;
use Studbook\Catalog\BulkInserter;

/**
 * Groups parts that can stand in for each other, from Rebrickable's part
 * relationships: "variant" joins mould variants and alternates (M, A),
 * "print" also joins printed and patterned versions with their base part
 * (P, T). Each group is represented by its most common part (by
 * popularity). Only parts that belong to a group get a `cat_part_canon` row.
 */
final class PartEquivalence
{
    public const MODES = ['exact', 'variant', 'print'];
    private const VARIANT_TYPES = ['M', 'A'];
    private const PRINT_TYPES = ['M', 'A', 'P', 'T'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Builds the table once when it is empty (e.g. right after the migration, before the next import). */
    public function ensure(): void
    {
        if ($this->pdo->query('SELECT 1 FROM cat_part_canon LIMIT 1')->fetchColumn() !== false) {
            return;
        }
        if ($this->pdo->query('SELECT 1 FROM cat_part_rel LIMIT 1')->fetchColumn() === false) {
            return;
        }
        $this->build('cat_part_canon', 'cat_part_rel', 'cat_part');
    }

    /** Fills `$target` (empty) from the relationships and popularity in the given tables; returns the row count. */
    public function build(string $target, string $relTable, string $partTable): int
    {
        $relations = $this->pdo->query("SELECT rel_type, child, parent FROM {$relTable}")->fetchAll(PDO::FETCH_ASSOC);
        $popularity = [];
        $stmt = $this->pdo->query("SELECT rb_num, popularity FROM {$partTable} WHERE rb_num IN (
            SELECT child FROM {$relTable} UNION SELECT parent FROM {$relTable})");
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$part, $count]) {
            $popularity['p:' . $part] = (int) $count;
        }

        $variant = self::groups($relations, self::VARIANT_TYPES, $popularity);
        $print = self::groups($relations, self::PRINT_TYPES, $popularity);
        $insert = new BulkInserter($this->pdo, $target, ['part', 'variant', 'print']);
        foreach ($print as $key => $printCanon) {
            $part = substr($key, 2);
            $insert->add([$part, $variant[$key] ?? $part, $printCanon]);
        }

        return $insert->flush();
    }

    /** SQL expression for the group key of `$column` (joined to cat_part_canon as `$alias`). */
    public static function groupExpression(string $mode, string $alias, string $column): string
    {
        return match ($mode) {
            'variant' => "COALESCE({$alias}.variant, {$column})",
            'print' => "COALESCE({$alias}.print, {$column})",
            default => $column,
        };
    }

    /**
     * Union-find over the relations of the given types.
     *
     * @param list<array{rel_type: string, child: string, parent: string}> $relations
     * @param list<string> $types
     * @param array<string, int> $popularity 'p:' . part => popularity
     * @return array<string, string> 'p:' . part => representative part, for every part in a group
     */
    private static function groups(array $relations, array $types, array $popularity): array
    {
        $parent = [];
        $find = static function (string $x) use (&$parent): string {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        foreach ($relations as $rel) {
            if (!in_array($rel['rel_type'], $types, true)) {
                continue;
            }
            $a = 'p:' . $rel['child'];
            $b = 'p:' . $rel['parent'];
            $parent[$a] ??= $a;
            $parent[$b] ??= $b;
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        }

        $members = [];
        foreach (array_keys($parent) as $key) {
            $members[$find($key)][] = $key;
        }
        $result = [];
        foreach ($members as $group) {
            usort($group, static fn (string $a, string $b): int => [-($popularity[$a] ?? 0), strlen($a), $a]
                <=> [-($popularity[$b] ?? 0), strlen($b), $b]);
            $canon = substr($group[0], 2);
            foreach ($group as $key) {
                $result[$key] = $canon;
            }
        }

        return $result;
    }
}
