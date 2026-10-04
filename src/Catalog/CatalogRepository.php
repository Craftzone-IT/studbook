<?php

declare(strict_types=1);

namespace Studbook\Catalog;

use PDO;

/**
 * Read access to the imported catalogue. The UI shows BrickLink numbers and
 * colour names; Rebrickable values are the fallback when a match is missing.
 */
final class CatalogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Finds a part by Rebrickable or BrickLink number (case-insensitive).
     *
     * @return array{rb_num: string, bl_num: ?string, name: string, display: string}|null
     */
    public function resolvePart(string $input): ?array
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 64) {
            return null;
        }
        foreach ([$input, strtolower($input)] as $candidate) {
            foreach (['rb_num', 'bl_num'] as $column) {
                $stmt = $this->pdo->prepare(
                    "SELECT rb_num, bl_num, name FROM cat_part WHERE {$column} = ? ORDER BY rb_num LIMIT 1"
                );
                $stmt->execute([$candidate]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    return self::part($row);
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $rbNums
     * @return array<string, array{rb_num: string, bl_num: ?string, name: string, display: string}>
     */
    public function parts(array $rbNums): array
    {
        $rbNums = array_values(array_unique($rbNums));
        if ($rbNums === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT rb_num, bl_num, name FROM cat_part WHERE rb_num IN (%s)',
            implode(',', array_fill(0, count($rbNums), '?'))
        ));
        $stmt->execute($rbNums);
        $parts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $parts[(string) $row['rb_num']] = self::part($row);
        }

        return $parts;
    }

    /**
     * Colours the part exists in, the most common (in the most sets) first.
     *
     * @return list<array{id: int, name: string, rgb: string}>
     */
    public function colorsForPart(string $rbNum): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.rb_id, c.name, c.bl_name, c.rgb FROM cat_part_color pc
             JOIN cat_color c ON c.rb_id = pc.color_id
             LEFT JOIN (SELECT color_id, COUNT(*) AS n FROM cat_inventory WHERE part = ? GROUP BY color_id) u
               ON u.color_id = pc.color_id
             WHERE pc.part = ? AND c.rb_id >= 0
             ORDER BY COALESCE(u.n, 0) DESC, COALESCE(c.bl_name, c.name)'
        );
        $stmt->execute([$rbNum, $rbNum]);

        return array_map(self::color(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int, array{id: int, name: string, rgb: string}> */
    public function colors(): array
    {
        $colors = [];
        $rows = $this->pdo->query('SELECT rb_id, name, bl_name, rgb FROM cat_color')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $color = self::color($row);
            $colors[$color['id']] = $color;
        }

        return $colors;
    }

    /**
     * Search words that map to colour words (e.g. Hungarian "piros" → "red"),
     * so the colour filter on the entry page understands them too.
     *
     * @return array<string, string>
     */
    public function colorSynonyms(): array
    {
        $words = [];
        foreach ($this->pdo->query('SELECT name, bl_name FROM cat_color')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            preg_match_all('/[a-z]+/', strtolower($row['name'] . ' ' . $row['bl_name']), $m);
            foreach ($m[0] as $w) {
                $words[$w] = true;
            }
        }
        $result = [];
        $rows = $this->pdo->query('SELECT term, canonical FROM search_synonym')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $term => $canonical) {
            $parts = explode(' ', (string) $canonical);
            if (array_filter($parts, static fn (string $p): bool => !isset($words[$p])) === []) {
                $result[mb_strtolower((string) $term)] = (string) $canonical;
            }
        }

        return $result;
    }

    /**
     * A catalogue set by number; "10696" also finds "10696-1".
     *
     * @return array{set_num: string, name: string, year: ?int, num_parts: int, theme: ?string}|null
     */
    public function set(string $setNum): ?array
    {
        $setNum = trim($setNum);
        if ($setNum === '' || strlen($setNum) > 64) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT s.set_num, s.name, s.year, s.num_parts, t.name AS theme
             FROM cat_set s LEFT JOIN cat_theme t ON t.id = s.theme_id
             WHERE s.set_num IN (?, ?) ORDER BY s.set_num = ? DESC LIMIT 1'
        );
        $stmt->execute([$setNum, $setNum . '-1', $setNum]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::setRow($row) : null;
    }

    /**
     * Sets matching a number (exact, "-1" variant, prefix) or the words of a name.
     *
     * @return list<array{set_num: string, name: string, year: ?int, num_parts: int, theme: ?string}>
     */
    public function findSets(string $query, int $limit = 30): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 100) {
            return [];
        }
        $like = static fn (string $v): string => addcslashes($v, '%_\\');
        $where = ['s.set_num = ?', 's.set_num LIKE ?'];
        $params = [$query, $like($query) . '%'];
        $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $nameMatch = implode(' AND ', array_fill(0, count($words), 's.name LIKE ?'));
        $where[] = '(' . $nameMatch . ')';
        foreach ($words as $word) {
            $params[] = '%' . $like($word) . '%';
        }
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT s.set_num, s.name, s.year, s.num_parts, t.name AS theme
             FROM cat_set s LEFT JOIN cat_theme t ON t.id = s.theme_id
             WHERE %s
             ORDER BY s.set_num = ? DESC, s.set_num = ? DESC, s.set_num LIKE ? DESC, s.year DESC, s.set_num
             LIMIT %d',
            implode(' OR ', $where),
            max(1, min(100, $limit))
        ));
        $stmt->execute([...$params, $query, $query . '-1', $like($query) . '%']);

        return array_map(self::setRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Official inventory of a set, one row per part and colour (minifig parts included).
     *
     * @return list<array{part: string, color_id: int, qty: int, spare: int}>
     */
    public function setInventory(string $setNum): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT part, color_id,
                    SUM(CASE WHEN is_spare = 0 THEN quantity ELSE 0 END) AS qty,
                    SUM(CASE WHEN is_spare = 1 THEN quantity ELSE 0 END) AS spare
             FROM cat_inventory WHERE set_num = ? GROUP BY part, color_id'
        );
        $stmt->execute([$setNum]);

        return array_map(static fn (array $r): array => [
            'part' => (string) $r['part'],
            'color_id' => (int) $r['color_id'],
            'qty' => (int) $r['qty'],
            'spare' => (int) $r['spare'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function isEmpty(): bool
    {
        return $this->pdo->query('SELECT 1 FROM cat_part LIMIT 1')->fetchColumn() === false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{rb_num: string, bl_num: ?string, name: string, display: string}
     */
    private static function part(array $row): array
    {
        $bl = $row['bl_num'] !== null ? (string) $row['bl_num'] : null;

        return [
            'rb_num' => (string) $row['rb_num'],
            'bl_num' => $bl,
            'name' => (string) $row['name'],
            'display' => $bl ?? (string) $row['rb_num'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, rgb: string}
     */
    private static function color(array $row): array
    {
        return [
            'id' => (int) $row['rb_id'],
            'name' => (string) ($row['bl_name'] ?? $row['name']),
            'rgb' => (string) $row['rgb'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{set_num: string, name: string, year: ?int, num_parts: int, theme: ?string}
     */
    private static function setRow(array $row): array
    {
        return [
            'set_num' => (string) $row['set_num'],
            'name' => (string) $row['name'],
            'year' => $row['year'] !== null ? (int) $row['year'] : null,
            'num_parts' => (int) $row['num_parts'],
            'theme' => $row['theme'] !== null ? (string) $row['theme'] : null,
        ];
    }
}
