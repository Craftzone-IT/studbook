<?php

declare(strict_types=1);

namespace Studbook\Catalog;

use PDO;
use Studbook\Build\PartEquivalence;

/**
 * Loads the Rebrickable CSV files and the BrickLink lists into `*_new`
 * copies of the catalogue tables, derives flattened set inventories, then
 * swaps all tables in one atomic RENAME TABLE. The app keeps reading the old
 * tables until the swap, and a failed import leaves them untouched.
 */
final class CatalogImporter
{
    public const TABLES = [
        'cat_color',
        'cat_part_category',
        'cat_part',
        'cat_part_color',
        'cat_part_rel',
        'cat_theme',
        'cat_set',
        'cat_minifig',
        'cat_inventory',
        'cat_part_canon',
        'cat_bl_color',
        'cat_bl_part',
    ];

    private const STAGING = [
        'imp_inventories' => 'id INT NOT NULL PRIMARY KEY, version INT NOT NULL,
            set_num VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, KEY (set_num, version)',
        'imp_inventory_parts' => 'inventory_id INT NOT NULL, part VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
            color_id INT NOT NULL, quantity INT NOT NULL, is_spare TINYINT(1) NOT NULL, KEY (inventory_id)',
        'imp_inventory_minifigs' => 'inventory_id INT NOT NULL, fig_num VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
            quantity INT NOT NULL, KEY (inventory_id)',
        'imp_inventory_sets' => 'inventory_id INT NOT NULL, set_num VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
            quantity INT NOT NULL, KEY (inventory_id)',
        'imp_default_inventory' => 'set_num VARCHAR(64) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
            inventory_id INT NOT NULL, KEY (inventory_id)',
    ];

    /** Maximum number of unmatched parts listed in the report. */
    private const SAMPLE_SIZE = 50;

    /** @var \Closure(string): void */
    private \Closure $log;
    private bool $apiAvailable = false;

    /** @param callable(string): void $log */
    public function __construct(private readonly PDO $pdo, callable $log)
    {
        $this->log = \Closure::fromCallable($log);
    }

    /**
     * @param array<string, string> $files Rebrickable file key => local path
     * @param array<string, list<string>> $apiParts BrickLink ids per part from the Rebrickable API
     * @param array<string, array{ids: list<int>, names: list<string>}> $apiColors BrickLink ids per colour
     * @return array<string, mixed> statistics for the import report
     */
    public function import(
        array $files,
        BrickLinkCatalog $bricklink,
        array $apiParts = [],
        array $apiColors = [],
    ): array {
        foreach (RebrickableDownloader::FILES as $key) {
            if (!isset($files[$key])) {
                throw new ImportException(sprintf('Rebrickable file %s is missing', $key));
            }
        }
        $this->apiAvailable = $apiParts !== [] || $apiColors !== [];
        $this->prepareTables();
        try {
            $stats = ['counts' => []];
            $stats['bricklink'] = $this->loadBrickLink($bricklink);
            $colorMatcher = new ColorMatcher($bricklink->colors);
            $partMatcher = new PartMatcher($bricklink->parts);

            $this->loadThemes($files['themes']);
            $stats['colors'] = $this->loadColors($files['colors'], $colorMatcher, $apiColors, $bricklink);
            $this->loadCategories($files['part_categories']);
            $stats['parts'] = $this->loadParts($files['parts'], $partMatcher, $apiParts);
            $stats['api'] = ['parts' => count($apiParts), 'colors' => count($apiColors)];
            $this->loadRelationships($files['part_relationships']);
            $this->loadSets($files['sets']);
            $this->loadMinifigs($files['minifigs']);
            $this->loadInventories($files);
            $this->loadPartColors($files['elements'], $files['inventory_parts']);
            $this->deriveInventory();

            foreach (self::TABLES as $table) {
                $stats['counts'][$table] = (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}_new")->fetchColumn();
            }
            if ($stats['counts']['cat_part'] === 0 || $stats['counts']['cat_set'] === 0) {
                throw new ImportException('The import produced no parts or no sets; keeping the previous catalogue.');
            }
            $this->swap();
        } finally {
            $this->dropStaging();
        }
        ($this->log)('Catalogue tables swapped in.');

        return $stats;
    }

    private function prepareTables(): void
    {
        foreach (self::TABLES as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}_new");
            $this->pdo->exec("CREATE TABLE {$table}_new LIKE {$table}");
        }
        foreach (self::STAGING as $table => $columns) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
            $this->pdo->exec("CREATE TABLE {$table} ({$columns}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    private function dropStaging(): void
    {
        foreach (array_keys(self::STAGING) as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
        foreach (self::TABLES as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}_new");
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}_old");
        }
    }

    private function swap(): void
    {
        $renames = [];
        foreach (self::TABLES as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}_old");
            $renames[] = "{$table} TO {$table}_old, {$table}_new TO {$table}";
        }
        $this->pdo->exec('RENAME TABLE ' . implode(', ', $renames));
    }

    /** @return array<string, mixed> */
    private function loadBrickLink(BrickLinkCatalog $bricklink): array
    {
        $colors = new BulkInserter($this->pdo, 'cat_bl_color_new', ['bl_id', 'name', 'rgb']);
        foreach ($bricklink->colors as $id => $color) {
            $rgb = $color['rgb'] !== null ? substr($color['rgb'], 0, 6) : null;
            $colors->add([$id, mb_substr($color['name'], 0, 100), $rgb]);
        }
        $parts = new BulkInserter($this->pdo, 'cat_bl_part_new', ['bl_num', 'name', 'category', 'alternates']);
        foreach ($bricklink->parts as $number => $part) {
            if (strlen((string) $number) > 64) {
                continue;
            }
            $parts->add([
                (string) $number,
                mb_substr($part['name'], 0, 500),
                $part['category'] !== null ? mb_substr($part['category'], 0, 200) : null,
                $part['alternates'] !== [] ? mb_substr(implode(', ', $part['alternates']), 0, 500) : null,
            ]);
        }
        $colors->flush();
        $parts->flush();
        foreach ($bricklink->files as $file => $type) {
            ($this->log)(sprintf('BrickLink %s file: %s', $type, $file));
        }
        foreach ($bricklink->warnings as $warning) {
            ($this->log)('WARNING ' . $warning);
        }
        if (($bricklink->parts === [] || $bricklink->colors === []) && !$this->apiAvailable) {
            ($this->log)('WARNING No BrickLink parts and/or colours list found; BrickLink numbers stay empty. '
                . 'See docs/catalogue-import.md.');
        }

        return [
            'files' => $bricklink->files,
            'warnings' => $bricklink->warnings,
            'parts' => count($bricklink->parts),
            'colors' => count($bricklink->colors),
        ];
    }

    private function loadThemes(string $path): void
    {
        $insert = new BulkInserter($this->pdo, 'cat_theme_new', ['id', 'name', 'parent_id']);
        foreach (CsvReader::rows($path, ['id', 'name', 'parent_id']) as $row) {
            $insert->add([(int) $row['id'], $row['name'], $row['parent_id'] === '' ? null : (int) $row['parent_id']]);
        }
        ($this->log)(sprintf('Themes: %d', $insert->flush()));
    }

    /**
     * @param array<string, array{ids: list<int>, names: list<string>}> $apiColors
     * @return array<string, mixed>
     */
    private function loadColors(
        string $path,
        ColorMatcher $matcher,
        array $apiColors,
        BrickLinkCatalog $bricklink
    ): array {
        $insert = new BulkInserter(
            $this->pdo,
            'cat_color_new',
            ['rb_id', 'name', 'rgb', 'is_trans', 'bl_id', 'bl_name']
        );
        $matched = 0;
        $matchedApi = 0;
        $unmatched = [];
        foreach (CsvReader::rows($path, ['id', 'name', 'rgb', 'is_trans']) as $row) {
            $api = $apiColors[$row['id']] ?? null;
            if ($api !== null) {
                $blId = $api['ids'][0];
                $match = [
                    'bl_id' => $blId,
                    'bl_name' => $bricklink->colors[$blId]['name'] ?? ($api['names'][0] ?? null),
                ];
                $matchedApi++;
            } else {
                $match = $matcher->match($row['name'], $row['rgb']);
            }
            if ($match !== null) {
                $matched++;
            } elseif ((int) $row['id'] >= 0) {
                $unmatched[] = $row['name'];
            }
            $insert->add([
                (int) $row['id'],
                $row['name'],
                strtoupper(substr($row['rgb'], 0, 6)),
                self::bool($row['is_trans']),
                $match['bl_id'] ?? null,
                $match['bl_name'] ?? null,
            ]);
        }
        $total = $insert->flush();
        ($this->log)(sprintf(
            'Colours: %d, %d matched to BrickLink, %d unmatched',
            $total,
            $matched,
            count($unmatched)
        ));

        return ['total' => $total, 'matched' => $matched, 'matched_api' => $matchedApi, 'unmatched' => $unmatched];
    }

    private function loadCategories(string $path): void
    {
        $insert = new BulkInserter($this->pdo, 'cat_part_category_new', ['id', 'name']);
        foreach (CsvReader::rows($path, ['id', 'name']) as $row) {
            $insert->add([(int) $row['id'], $row['name']]);
        }
        ($this->log)(sprintf('Part categories: %d', $insert->flush()));
    }

    /**
     * @param array<string, list<string>> $apiParts
     * @return array<string, mixed>
     */
    private function loadParts(string $path, PartMatcher $matcher, array $apiParts): array
    {
        $insert = new BulkInserter($this->pdo, 'cat_part_new', [
            'rb_num', 'name', 'category_id', 'material', 'bl_num', 'bl_match', 'width', 'length', 'height_plates',
        ]);
        $byMethod = [PartMatcher::API => 0, PartMatcher::EXACT => 0, PartMatcher::ALTERNATE => 0];
        $unmatched = 0;
        $unmatchedByCategory = [];
        $samples = [];
        $withSize = 0;
        foreach (CsvReader::rows($path, ['part_num', 'name', 'part_cat_id']) as $row) {
            $num = $row['part_num'];
            if ($num === '' || strlen($num) > 64) {
                continue;
            }
            $apiIds = $apiParts[$num] ?? null;
            $match = $apiIds !== null && strlen($apiIds[0]) <= 64
                ? ['bl_num' => $apiIds[0], 'method' => PartMatcher::API]
                : $matcher->match($num);
            if ($match !== null) {
                $byMethod[$match['method']]++;
            } else {
                $unmatched++;
                $category = (int) $row['part_cat_id'];
                $unmatchedByCategory[$category] = ($unmatchedByCategory[$category] ?? 0) + 1;
                // Prints and stickers are expected to be unmatched; list the others first.
                if (count($samples) < self::SAMPLE_SIZE && !self::isPrintOrSticker($num, $row['name'])) {
                    $samples[] = ['rb_num' => $num, 'name' => $row['name']];
                }
            }
            $size = PartDimensions::parse($row['name']);
            if ($size['width'] !== null) {
                $withSize++;
            }
            $insert->add([
                $num,
                mb_substr($row['name'], 0, 500),
                (int) $row['part_cat_id'],
                ($row['part_material'] ?? '') !== '' ? mb_substr($row['part_material'], 0, 50) : null,
                $match['bl_num'] ?? null,
                $match['method'] ?? null,
                $size['width'],
                $size['length'],
                $size['height_plates'],
            ]);
        }
        $total = $insert->flush();
        arsort($unmatchedByCategory);
        ($this->log)(sprintf(
            'Parts: %d, BrickLink match: %d via Rebrickable API, %d exact, %d via alternate number, %d unmatched; '
            . '%d with parsed size',
            $total,
            $byMethod[PartMatcher::API],
            $byMethod[PartMatcher::EXACT],
            $byMethod[PartMatcher::ALTERNATE],
            $unmatched,
            $withSize
        ));

        return [
            'total' => $total,
            'matched_api' => $byMethod[PartMatcher::API],
            'matched_exact' => $byMethod[PartMatcher::EXACT],
            'matched_alternate' => $byMethod[PartMatcher::ALTERNATE],
            'unmatched' => $unmatched,
            'unmatched_by_category' => array_slice($unmatchedByCategory, 0, 15, true),
            'unmatched_samples' => $samples,
            'with_size' => $withSize,
        ];
    }

    private function loadRelationships(string $path): void
    {
        $insert = new BulkInserter($this->pdo, 'cat_part_rel_new', ['rel_type', 'child', 'parent'], 'INSERT IGNORE');
        foreach (CsvReader::rows($path, ['rel_type', 'child_part_num', 'parent_part_num']) as $row) {
            $insert->add([substr($row['rel_type'], 0, 1), $row['child_part_num'], $row['parent_part_num']]);
        }
        ($this->log)(sprintf('Part relationships: %d', $insert->flush()));
    }

    private function loadSets(string $path): void
    {
        $insert = new BulkInserter(
            $this->pdo,
            'cat_set_new',
            ['set_num', 'name', 'year', 'theme_id', 'num_parts', 'img_url']
        );
        foreach (CsvReader::rows($path, ['set_num', 'name', 'year', 'theme_id', 'num_parts']) as $row) {
            $insert->add([
                $row['set_num'],
                mb_substr($row['name'], 0, 255),
                $row['year'] === '' ? null : (int) $row['year'],
                $row['theme_id'] === '' ? null : (int) $row['theme_id'],
                (int) $row['num_parts'],
                self::url($row['img_url'] ?? ''),
            ]);
        }
        ($this->log)(sprintf('Sets: %d', $insert->flush()));
    }

    private function loadMinifigs(string $path): void
    {
        $insert = new BulkInserter($this->pdo, 'cat_minifig_new', ['fig_num', 'name', 'num_parts', 'img_url']);
        foreach (CsvReader::rows($path, ['fig_num', 'name', 'num_parts']) as $row) {
            $insert->add([
                $row['fig_num'],
                mb_substr($row['name'], 0, 255),
                (int) $row['num_parts'],
                self::url($row['img_url'] ?? ''),
            ]);
        }
        ($this->log)(sprintf('Minifigs: %d', $insert->flush()));
    }

    /** @param array<string, string> $files */
    private function loadInventories(array $files): void
    {
        $insert = new BulkInserter($this->pdo, 'imp_inventories', ['id', 'version', 'set_num']);
        foreach (CsvReader::rows($files['inventories'], ['id', 'version', 'set_num']) as $row) {
            $insert->add([(int) $row['id'], (int) $row['version'], $row['set_num']]);
        }
        $insert->flush();

        $insert = new BulkInserter(
            $this->pdo,
            'imp_inventory_parts',
            ['inventory_id', 'part', 'color_id', 'quantity', 'is_spare']
        );
        $columns = ['inventory_id', 'part_num', 'color_id', 'quantity', 'is_spare'];
        foreach (CsvReader::rows($files['inventory_parts'], $columns) as $row) {
            $insert->add([
                (int) $row['inventory_id'],
                $row['part_num'],
                (int) $row['color_id'],
                (int) $row['quantity'],
                self::bool($row['is_spare']),
            ]);
        }
        ($this->log)(sprintf('Inventory parts: %d', $insert->flush()));

        $insert = new BulkInserter($this->pdo, 'imp_inventory_minifigs', ['inventory_id', 'fig_num', 'quantity']);
        foreach (CsvReader::rows($files['inventory_minifigs'], ['inventory_id', 'fig_num', 'quantity']) as $row) {
            $insert->add([(int) $row['inventory_id'], $row['fig_num'], (int) $row['quantity']]);
        }
        $insert->flush();

        $insert = new BulkInserter($this->pdo, 'imp_inventory_sets', ['inventory_id', 'set_num', 'quantity']);
        foreach (CsvReader::rows($files['inventory_sets'], ['inventory_id', 'set_num', 'quantity']) as $row) {
            $insert->add([(int) $row['inventory_id'], $row['set_num'], (int) $row['quantity']]);
        }
        $insert->flush();
    }

    /** Part × colour pairs that exist (elements and inventories), with an image URL where known. */
    private function loadPartColors(string $elementsPath, string $inventoryPartsPath): void
    {
        $insert = new BulkInserter(
            $this->pdo,
            'cat_part_color_new',
            ['part', 'color_id', 'img_url'],
            'INSERT',
            'ON DUPLICATE KEY UPDATE img_url = COALESCE(img_url, VALUES(img_url))'
        );
        $seen = [];
        foreach (CsvReader::rows($inventoryPartsPath, ['part_num', 'color_id']) as $row) {
            $key = $row['part_num'] . '|' . $row['color_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $insert->add([$row['part_num'], (int) $row['color_id'], self::url($row['img_url'] ?? '')]);
        }
        foreach (CsvReader::rows($elementsPath, ['part_num', 'color_id']) as $row) {
            $key = $row['part_num'] . '|' . $row['color_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $insert->add([$row['part_num'], (int) $row['color_id'], null]);
        }
        ($this->log)(sprintf('Part/colour combinations: %d', $insert->flush()));
    }

    /**
     * Flattens the default (lowest version) inventory of every set and
     * minifig: own parts, parts of included minifigs (from_minifig = 1) and
     * parts of included sub-sets with their minifigs.
     */
    private function deriveInventory(): void
    {
        $this->pdo->exec(
            'INSERT INTO imp_default_inventory (set_num, inventory_id)
             SELECT i.set_num, MIN(i.id) FROM imp_inventories i
             JOIN (SELECT set_num, MIN(version) AS version FROM imp_inventories GROUP BY set_num) v
               ON v.set_num = i.set_num AND v.version = i.version
             GROUP BY i.set_num'
        );
        $upsert = ' ON DUPLICATE KEY UPDATE quantity = cat_inventory_new.quantity + VALUES(quantity)';
        $columns = 'INSERT INTO cat_inventory_new (set_num, part, color_id, is_spare, from_minifig, quantity) ';

        // Own parts.
        $this->pdo->exec($columns
            . 'SELECT d.set_num, p.part, p.color_id, p.is_spare, 0, SUM(p.quantity)
               FROM imp_default_inventory d JOIN imp_inventory_parts p ON p.inventory_id = d.inventory_id
               GROUP BY d.set_num, p.part, p.color_id, p.is_spare' . $upsert);
        // Parts of minifigs included in the set.
        $this->pdo->exec($columns
            . 'SELECT d.set_num, p.part, p.color_id, p.is_spare, 1, SUM(p.quantity * m.quantity)
               FROM imp_default_inventory d
               JOIN imp_inventory_minifigs m ON m.inventory_id = d.inventory_id
               JOIN imp_default_inventory fd ON fd.set_num = m.fig_num
               JOIN imp_inventory_parts p ON p.inventory_id = fd.inventory_id
               GROUP BY d.set_num, p.part, p.color_id, p.is_spare' . $upsert);
        // Parts of sub-sets (e.g. multi-packs), one level deep.
        $this->pdo->exec($columns
            . 'SELECT d.set_num, p.part, p.color_id, p.is_spare, 0, SUM(p.quantity * s.quantity)
               FROM imp_default_inventory d
               JOIN imp_inventory_sets s ON s.inventory_id = d.inventory_id
               JOIN imp_default_inventory sd ON sd.set_num = s.set_num
               JOIN imp_inventory_parts p ON p.inventory_id = sd.inventory_id
               GROUP BY d.set_num, p.part, p.color_id, p.is_spare' . $upsert);
        // Minifigs inside those sub-sets.
        $this->pdo->exec($columns
            . 'SELECT d.set_num, p.part, p.color_id, p.is_spare, 1, SUM(p.quantity * m.quantity * s.quantity)
               FROM imp_default_inventory d
               JOIN imp_inventory_sets s ON s.inventory_id = d.inventory_id
               JOIN imp_default_inventory sd ON sd.set_num = s.set_num
               JOIN imp_inventory_minifigs m ON m.inventory_id = sd.inventory_id
               JOIN imp_default_inventory fd ON fd.set_num = m.fig_num
               JOIN imp_inventory_parts p ON p.inventory_id = fd.inventory_id
               GROUP BY d.set_num, p.part, p.color_id, p.is_spare' . $upsert);

        $rows = (int) $this->pdo->query('SELECT COUNT(*) FROM cat_inventory_new')->fetchColumn();
        ($this->log)(sprintf('Flattened inventory rows: %d', $rows));

        // Popularity = number of sets a part appears in; ranks search results and pickers.
        $this->pdo->exec(
            "UPDATE cat_part_new p
             JOIN (SELECT part, COUNT(DISTINCT set_num) AS n FROM cat_inventory_new
                   WHERE set_num NOT LIKE 'fig-%' GROUP BY part) x ON x.part = p.rb_num
             SET p.popularity = x.n"
        );
        // Parts a set needs, for "what can I build" percentages.
        $this->pdo->exec(
            'UPDATE cat_set_new s
             JOIN (SELECT set_num, SUM(quantity) AS n, SUM(CASE WHEN from_minifig = 1 THEN quantity ELSE 0 END) AS f
                   FROM cat_inventory_new WHERE is_spare = 0 GROUP BY set_num) x ON x.set_num = s.set_num
             SET s.need_qty = x.n, s.need_fig_qty = x.f'
        );
        $groups = (new PartEquivalence($this->pdo))->build('cat_part_canon_new', 'cat_part_rel_new', 'cat_part_new');
        ($this->log)(sprintf('Parts in equivalence groups: %d', $groups));
    }

    private static function isPrintOrSticker(string $num, string $name): bool
    {
        return (bool) preg_match('/pr\d|pat\d|pb\d/i', $num)
            || stripos($name, 'sticker') !== false
            || stripos($name, ' print') !== false;
    }

    private static function bool(string $value): int
    {
        return in_array(strtolower(trim($value)), ['true', 't', '1', 'yes'], true) ? 1 : 0;
    }

    private static function url(string $value): ?string
    {
        $value = trim($value);

        return $value === '' || strlen($value) > 500 ? null : $value;
    }
}
