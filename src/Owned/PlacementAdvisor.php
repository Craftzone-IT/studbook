<?php

declare(strict_types=1);

namespace Studbook\Owned;

use PDO;

/**
 * "Where does it go?": ranks the boxes of a collection for a part, from the
 * box labels and the catalogue only (`docs/placement-advice.md`). Steps are
 * tried in order; a box found by an earlier step ranks above boxes from later
 * steps and appears only once. The Inbox is never suggested.
 */
final class PlacementAdvisor
{
    /** Size distance used when either part has no known width and length. */
    public const NO_SIZE_DISTANCE = 99;
    private const PRINT_TYPES = "('P', 'T')";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Setting key of the box that holds the printed and patterned parts of a collection. */
    public static function printBoxKey(int $collectionId): string
    {
        return 'print_box.' . $collectionId;
    }

    /**
     * @param string $rbNum resolved Rebrickable part number
     * @return list<array{box_id: int, name: string, type: string, reason: string, detail: array<string, string|int>}>
     */
    public function advise(int $collectionId, string $rbNum, int $limit = 3): array
    {
        $part = $this->part($rbNum);
        if ($part === null || $limit < 1) {
            return [];
        }
        $isPrint = $this->isPrint($rbNum);

        $found = [];
        $add = static function (array $rows, string $reason, callable $detail) use (&$found): void {
            foreach ($rows as $row) {
                $found['b' . $row['id']] ??= [
                    'box_id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'type' => (string) $row['type'],
                    'reason' => $reason,
                    'detail' => $detail($row),
                ];
            }
        };
        $none = static fn (array $row): array => [];
        $labelPart = fn (array $row): array => $this->partDetail((string) $row['part']);

        $add($this->labelRows($collectionId, 'l.part = ?', [$rbNum]), 'label', $none);
        $add($this->labelRows(
            $collectionId,
            'l.part <> ? AND (lp.bl_num = ? OR lc.variant = ?)',
            [$rbNum, $part['bl_num'], $part['variant']]
        ), 'label_variant', $labelPart);
        if ($isPrint) {
            $add($this->printBox($collectionId), 'print_box', $none);
            $add($this->labelRows(
                $collectionId,
                'l.part <> ? AND (l.part IN (SELECT r.parent FROM cat_part_rel r
                    WHERE r.child = ? AND r.rel_type IN ' . self::PRINT_TYPES . ') OR lc.print = ?)',
                [$rbNum, $rbNum, $part['print']]
            ), 'label_unprinted', $labelPart);
        }
        $add(
            $this->storedRows($collectionId, $rbNum),
            'stored',
            static fn (array $row): array => ['qty' => (int) $row['qty']]
        );
        $add($this->similarRows($collectionId, $part), 'similar', $labelPart);

        return array_slice(array_values($found), 0, $limit);
    }

    /** @return array{part: string, part_label: string} the part as the UI shows it */
    private function partDetail(string $rbNum): array
    {
        $part = $this->part($rbNum);
        $display = ($part['bl_num'] ?? '') !== '' ? (string) $part['bl_num'] : $rbNum;

        return [
            'part' => $rbNum,
            'part_label' => trim($display . ' ' . ($part['name'] ?? '')),
        ];
    }

    /** @return array<string, mixed>|null catalogue row with the equivalence groups */
    private function part(string $rbNum): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.rb_num, p.name, p.bl_num, p.category_id, p.width, p.length, c.variant, c.print
             FROM cat_part p LEFT JOIN cat_part_canon c ON c.part = p.rb_num WHERE p.rb_num = ?'
        );
        $stmt->execute([$rbNum]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function isPrint(string $rbNum): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM cat_part_rel WHERE child = ? AND rel_type IN ' . self::PRINT_TYPES . ' LIMIT 1'
        );
        $stmt->execute([$rbNum]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Candidate boxes with a label part matching `$condition`, one row per box (its first such label).
     *
     * @param list<?string> $params params of the condition; NULL never matches
     * @return list<array<string, mixed>>
     */
    private function labelRows(int $collectionId, string $condition, array $params): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT b.id, b.name, b.type, l.part
             FROM storage_label l
             JOIN storage b ON b.id = l.storage_id
             LEFT JOIN cat_part lp ON lp.rb_num = l.part
             LEFT JOIN cat_part_canon lc ON lc.part = l.part
             WHERE b.collection_id = ? AND b.type <> 'inbox' AND ({$condition})
             ORDER BY b.name, b.id, l.position, l.id"
        );
        $stmt->execute([$collectionId, ...$params]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> the collection's print box, when one is set */
    private function printBox(int $collectionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT b.id, b.name, b.type FROM setting s JOIN storage b ON b.id = CAST(s.value AS UNSIGNED)
             WHERE s.`key` = ? AND b.collection_id = ? AND b.type <> 'inbox'"
        );
        $stmt->execute([self::printBoxKey($collectionId), $collectionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> boxes holding loose lots of the part, most pieces first */
    private function storedRows(int $collectionId, string $rbNum): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT b.id, b.name, b.type, SUM(l.qty) AS qty
             FROM loose_lot l JOIN storage b ON b.id = l.storage_id
             WHERE l.collection_id = ? AND b.collection_id = ? AND b.type <> 'inbox' AND l.part = ?
             GROUP BY b.id, b.name, b.type
             ORDER BY qty DESC, b.name, b.id"
        );
        $stmt->execute([$collectionId, $collectionId, $rbNum]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Boxes labelled for parts of the same category: nearest size first, then
     * more such labels, then box name. The distance compares the shorter and
     * the longer side separately, so 2 x 3 and 3 x 2 are the same size.
     *
     * @param array<string, mixed> $part
     * @return list<array<string, mixed>>
     */
    private function similarRows(int $collectionId, array $part): array
    {
        $sized = $part['width'] !== null && $part['length'] !== null;
        $short = $sized ? min((int) $part['width'], (int) $part['length']) : 0;
        $long = $sized ? max((int) $part['width'], (int) $part['length']) : 0;
        // width/length are unsigned: cast before subtracting, or MySQL fails on negative results.
        $distance = $sized
            ? 'CASE WHEN lp.width IS NULL OR lp.length IS NULL THEN ' . self::NO_SIZE_DISTANCE . '
                ELSE ABS(LEAST(CAST(lp.width AS SIGNED), CAST(lp.length AS SIGNED)) - CAST(? AS SIGNED))
                   + ABS(GREATEST(CAST(lp.width AS SIGNED), CAST(lp.length AS SIGNED)) - CAST(? AS SIGNED)) END'
            : (string) self::NO_SIZE_DISTANCE;
        $stmt = $this->pdo->prepare(
            "SELECT b.id, b.name, b.type, l.part, {$distance} AS distance
             FROM storage_label l
             JOIN storage b ON b.id = l.storage_id
             JOIN cat_part lp ON lp.rb_num = l.part
             WHERE b.collection_id = ? AND b.type <> 'inbox' AND lp.category_id = ?
             ORDER BY b.name, b.id, distance, l.position, l.id"
        );
        $stmt->execute([...($sized ? [$short, $long] : []), $collectionId, (int) $part['category_id']]);

        $boxes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = 'b' . $row['id'];
            if (!isset($boxes[$key])) {
                // Rows of a box come nearest first, so the first one names the nearest label part.
                $boxes[$key] = $row + ['labels' => 0];
            }
            $boxes[$key]['labels']++;
        }
        $boxes = array_values($boxes);
        // Stable sort: boxes that tie keep the name order of the query.
        usort($boxes, static fn (array $a, array $b): int => [(int) $a['distance'], -$a['labels']]
            <=> [(int) $b['distance'], -$b['labels']]);

        return $boxes;
    }
}
