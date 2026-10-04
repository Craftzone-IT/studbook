<?php

declare(strict_types=1);

namespace Studbook\Catalog;

use PDO;

/**
 * Free-text part search: part numbers (Rebrickable or BrickLink, also as
 * prefix), words of the part name, size patterns (`2x4`), colour names, and
 * Hungarian / variant words through `search_synonym`, e.g. `piros kocka 2x4`
 * or `3001 red`. Unknown words are corrected to the closest word of the
 * catalogue vocabulary (typo tolerance).
 */
final class PartSearch
{
    public const LIMIT = 50;
    private const MAX_COLOR_WORDS = 4;

    /** @var array<string, string>|null lower-case term => canonical words */
    private ?array $synonyms = null;
    /** @var array<string, int>|null colour phrase => Rebrickable colour id */
    private ?array $colorIndex = null;
    /** @var array<string, int>|null word => number of names it occurs in */
    private ?array $vocabulary = null;

    public function __construct(private readonly PDO $pdo, private readonly string $cacheDirectory)
    {
    }

    /**
     * @return array{
     *     parts: list<array{rb_num: string, bl_num: ?string, name: string, display: string,
     *         width: ?int, length: ?int}>,
     *     color: ?array{id: int, name: string, rgb: string},
     *     size: ?array{0: int, 1: int},
     *     corrections: array<string, string>
     * }
     */
    public function search(string $query, int $limit = self::LIMIT): array
    {
        $result = ['parts' => [], 'color' => null, 'size' => null, 'corrections' => []];
        $text = mb_strtolower(trim($query));
        $text = str_replace('×', 'x', $text);
        if ($text === '' || mb_strlen($text) > 200) {
            return $result;
        }

        if (preg_match('/(?<![\d.])(\d{1,2})\s*x\s*(\d{1,2})(?![\d.])/u', $text, $m)) {
            $result['size'] = [(int) $m[1], (int) $m[2]];
            $text = str_replace($m[0], ' ', $text);
        }
        // "trans-clear" → "trans clear" (but keep part numbers such as "3626cpr0001" intact).
        $text = (string) preg_replace('/(?<=\p{L})-(?=\p{L})/u', ' ', $text);
        $raw = preg_split('/[^\p{L}\p{N}\-\/]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $numbers = [];
        $words = [];
        foreach ($raw as $token) {
            if (preg_match('/\d/', $token)) {
                $numbers[] = $token;
                continue;
            }
            foreach (explode(' ', $this->synonym($token)) as $word) {
                if ($word !== '') {
                    $words[] = $word;
                }
            }
        }

        [$colorId, $words] = $this->extractColor($words);
        $words = array_values(array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 2));
        foreach ($words as $i => $word) {
            $corrected = $this->correct($word);
            if ($corrected !== $word) {
                $result['corrections'][$word] = $corrected;
                $words[$i] = $corrected;
            }
        }
        if ($colorId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT rb_id, COALESCE(bl_name, name) AS name, rgb FROM cat_color WHERE rb_id = ?'
            );
            $stmt->execute([$colorId]);
            $color = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($color)) {
                $result['color'] = [
                    'id' => (int) $color['rb_id'],
                    'name' => (string) $color['name'],
                    'rgb' => (string) $color['rgb'],
                ];
            }
        }

        $where = [];
        $params = [];
        foreach ($numbers as $number) {
            // A number is a part number (or its prefix), or part of the name ("Slope 45°").
            $like = self::likePrefix($number);
            $where[] = '(p.rb_num = ? OR p.bl_num = ? OR p.rb_num LIKE ? OR p.bl_num LIKE ? OR p.name LIKE ?)';
            array_push($params, $number, $number, $like, $like, '%' . self::escapeLike($number) . '%');
        }
        foreach ($words as $word) {
            $where[] = 'p.name LIKE ?';
            $params[] = '%' . self::escapeLike($word) . '%';
        }
        if ($result['size'] !== null) {
            [$a, $b] = $result['size'];
            $where[] = '((p.width = ? AND p.length = ?) OR (p.width = ? AND p.length = ?))';
            array_push($params, $a, $b, $b, $a);
        }
        if ($colorId !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM cat_part_color pc WHERE pc.part = p.rb_num AND pc.color_id = ?)';
            $params[] = $colorId;
        }
        if ($where === []) {
            return $result;
        }

        $exact = '(1 = 0)';
        if ($numbers !== []) {
            $marks = implode(',', array_fill(0, count($numbers), '?'));
            $exact = "(p.rb_num IN ({$marks}) OR p.bl_num IN ({$marks}))";
            $params = [...$params, ...$numbers, ...$numbers];
        }
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT p.rb_num, p.bl_num, p.name, p.width, p.length FROM cat_part p
             WHERE %s
             ORDER BY %s DESC, p.popularity DESC, CHAR_LENGTH(p.name), p.rb_num
             LIMIT %d',
            implode(' AND ', $where),
            $exact,
            max(1, min(200, $limit))
        ));
        $stmt->execute($params);
        $seen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bl = $row['bl_num'] !== null ? (string) $row['bl_num'] : null;
            // Several Rebrickable parts can share a BrickLink number (3003 and 6223); show it once,
            // its colour list covers all of them.
            if ($bl !== null && isset($seen['b:' . $bl])) {
                continue;
            }
            $seen['b:' . $bl] = true;
            $result['parts'][] = [
                'rb_num' => (string) $row['rb_num'],
                'bl_num' => $bl,
                'name' => (string) $row['name'],
                'display' => $bl ?? (string) $row['rb_num'],
                'width' => $row['width'] !== null ? (int) $row['width'] : null,
                'length' => $row['length'] !== null ? (int) $row['length'] : null,
            ];
        }

        return $result;
    }

    /** Canonical words for a typed word: exact synonym, then the same without accents if unambiguous. */
    private function synonym(string $word): string
    {
        if ($this->synonyms === null) {
            $this->synonyms = [];
            $ambiguous = [];
            $rows = $this->pdo->query('SELECT term, canonical FROM search_synonym')->fetchAll(PDO::FETCH_KEY_PAIR);
            $plain = [];
            foreach ($rows as $term => $canonical) {
                $term = mb_strtolower((string) $term);
                $this->synonyms[$term] = mb_strtolower((string) $canonical);
                $key = self::stripAccents($term);
                if (isset($plain[$key]) && $plain[$key] !== $this->synonyms[$term]) {
                    $ambiguous[$key] = true;
                }
                $plain[$key] = $this->synonyms[$term];
            }
            foreach ($plain as $key => $canonical) {
                if (!isset($this->synonyms[$key]) && !isset($ambiguous[$key])) {
                    $this->synonyms[$key] = $canonical;
                }
            }
        }

        return $this->synonyms[$word] ?? $word;
    }

    /**
     * Finds the longest run of words that names a colour ("light bluish gray").
     *
     * @param list<string> $words
     * @return array{0: ?int, 1: list<string>} colour id and the remaining words
     */
    private function extractColor(array $words): array
    {
        if ($this->colorIndex === null) {
            $this->colorIndex = [];
            $rows = $this->pdo->query('SELECT rb_id, name, bl_name FROM cat_color WHERE rb_id >= 0 ORDER BY rb_id')
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                foreach ([$row['bl_name'], $row['name']] as $name) {
                    if (is_string($name) && $name !== '') {
                        $key = self::phrase($name);
                        $this->colorIndex[$key] ??= (int) $row['rb_id'];
                    }
                }
            }
        }
        $count = count($words);
        for ($length = min(self::MAX_COLOR_WORDS, $count); $length >= 1; $length--) {
            for ($start = 0; $start + $length <= $count; $start++) {
                $key = implode(' ', array_slice($words, $start, $length));
                if (isset($this->colorIndex[$key])) {
                    array_splice($words, $start, $length);

                    return [$this->colorIndex[$key], $words];
                }
            }
        }

        return [null, $words];
    }

    /**
     * Closest catalogue word for a word that does not occur in any part or
     * colour name: at most 1 edit for short words, 2 for longer ones (a
     * swapped pair of letters counts as one edit); ties go to the more
     * frequent word.
     */
    private function correct(string $word): string
    {
        if (mb_strlen($word) < 4 || !preg_match('/^[a-z]+$/', $word)) {
            return $word;
        }
        $vocabulary = $this->vocabulary();
        if (isset($vocabulary[$word])) {
            return $word;
        }
        $best = $word;
        $bestDistance = (strlen($word) <= 5 ? 1 : 2) + 1;
        $bestFrequency = 0;
        foreach ($vocabulary as $candidate => $frequency) {
            $candidate = (string) $candidate;
            if ($candidate[0] !== $word[0] || abs(strlen($candidate) - strlen($word)) > 2) {
                continue;
            }
            $distance = self::editDistance($word, $candidate);
            if ($distance < $bestDistance || ($distance === $bestDistance && $frequency > $bestFrequency)) {
                $best = $candidate;
                $bestDistance = $distance;
                $bestFrequency = $frequency;
            }
        }

        return $best;
    }

    /** Optimal string alignment distance: Levenshtein plus transposition of adjacent letters. */
    public static function editDistance(string $a, string $b): int
    {
        $la = strlen($a);
        $lb = strlen($b);
        $d = [];
        for ($i = 0; $i <= $la; $i++) {
            $d[$i] = [$i];
        }
        for ($j = 0; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$la][$lb];
    }

    /** @return array<string, int> words of all part and colour names with their frequency, cached per import */
    private function vocabulary(): array
    {
        if ($this->vocabulary !== null) {
            return $this->vocabulary;
        }
        $version = (int) $this->pdo->query("SELECT COALESCE(MAX(id), 0) FROM import_run WHERE status = 'success'")
            ->fetchColumn();
        $file = $this->cacheDirectory . '/search-vocabulary-' . $version . '.json';
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                return $this->vocabulary = array_map('intval', $data);
            }
        }
        $words = [];
        $names = $this->pdo->query('SELECT name FROM cat_part UNION ALL SELECT name FROM cat_color')
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($names as $name) {
            preg_match_all('/[a-z]{3,}/', strtolower((string) $name), $m);
            foreach (array_unique($m[0]) as $w) {
                $words[$w] = ($words[$w] ?? 0) + 1;
            }
        }
        if (is_dir($this->cacheDirectory) || @mkdir($this->cacheDirectory, 0775, true)) {
            @file_put_contents($file, json_encode($words));
        }

        return $this->vocabulary = $words;
    }

    private static function phrase(string $name): string
    {
        $name = mb_strtolower($name);
        $name = str_replace(['-', '_', '/'], ' ', $name);
        $name = (string) preg_replace('/[^\p{L}\p{N} ]+/u', '', $name);

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    private static function stripAccents(string $word): string
    {
        return strtr($word, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
        ]);
    }

    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    private static function likePrefix(string $value): string
    {
        return self::escapeLike($value) . '%';
    }
}
