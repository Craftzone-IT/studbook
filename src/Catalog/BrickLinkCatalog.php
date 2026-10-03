<?php

declare(strict_types=1);

namespace Studbook\Catalog;

/**
 * Reads the BrickLink catalogue files the user downloaded with their own
 * account (Catalog → Download) from the configured folder. Files are
 * recognised by content, not by name:
 *
 * - tab-delimited "Parts" item list: columns include "Number" and "Name"
 *   (optionally "Category Name", "Alternate Item Number");
 * - tab-delimited "Colors" list: columns include "Color ID" and "Color Name";
 * - the same two lists in BrickLink's XML format (<CATALOG><ITEM>…).
 *
 * Other files are ignored and listed in the warnings.
 */
final class BrickLinkCatalog
{
    /**
     * Keyed by BrickLink number. PHP turns numeric keys such as "3001" into
     * integers, so cast keys back to string when reading.
     *
     * @var array<int|string, array{name: string, category: ?string, alternates: list<string>}>
     */
    public array $parts = [];
    /** @var array<int, array{name: string, rgb: ?string}> */
    public array $colors = [];
    /** @var array<string, string> file name => recognised type (parts, colors) */
    public array $files = [];
    /** @var list<string> */
    public array $warnings = [];

    public static function load(string $directory): self
    {
        $catalog = new self();
        if ($directory === '' || !is_dir($directory)) {
            $catalog->warnings[] = sprintf('BrickLink folder %s does not exist.', $directory);

            return $catalog;
        }
        $entries = scandir($directory) ?: [];
        sort($entries);
        foreach ($entries as $entry) {
            $path = $directory . '/' . $entry;
            if ($entry[0] === '.' || !is_file($path)) {
                continue;
            }
            try {
                $type = $catalog->readFile($path);
            } catch (ImportException $e) {
                $catalog->warnings[] = $e->getMessage();
                continue;
            }
            if ($type === null) {
                $catalog->warnings[] = sprintf('%s: not a BrickLink parts or colours list, ignored.', $entry);
            } else {
                $catalog->files[$entry] = $type;
            }
        }

        return $catalog;
    }

    /** @return 'parts'|'colors'|null */
    private function readFile(string $path): ?string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ImportException(sprintf('Cannot read %s', basename($path)));
        }
        $contents = self::toUtf8($contents);
        if (str_starts_with(ltrim($contents), '<')) {
            return $this->readXml($contents, basename($path));
        }

        return $this->readTabDelimited($contents);
    }

    /** @return 'parts'|'colors'|null */
    private function readTabDelimited(string $contents): ?string
    {
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $header = array_map(
            static fn (string $h): string => strtolower(trim($h)),
            explode("\t", (string) array_shift($lines))
        );
        $col = array_flip($header);

        if (isset($col['color id'], $col['color name'])) {
            foreach ($lines as $line) {
                $f = explode("\t", $line);
                $id = trim($f[$col['color id']] ?? '');
                if ($id === '' || !ctype_digit($id)) {
                    continue;
                }
                $rgb = isset($col['rgb']) ? strtoupper(trim($f[$col['rgb']] ?? '')) : '';
                $this->colors[(int) $id] = [
                    'name' => self::clean($f[$col['color name']] ?? ''),
                    'rgb' => $rgb !== '' ? $rgb : null,
                ];
            }

            return 'colors';
        }

        if (isset($col['number'], $col['name']) && !isset($col['year released'])) {
            $alternateCol = $col['alternate item number'] ?? null;
            $categoryCol = $col['category name'] ?? null;
            foreach ($lines as $line) {
                $f = explode("\t", $line);
                $this->addPart(
                    $f[$col['number']] ?? '',
                    $f[$col['name']] ?? '',
                    $categoryCol !== null ? ($f[$categoryCol] ?? null) : null,
                    $alternateCol !== null ? ($f[$alternateCol] ?? '') : ''
                );
            }

            return 'parts';
        }

        return null;
    }

    /** @return 'parts'|'colors'|null */
    private function readXml(string $contents, string $name): ?string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contents, \SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($xml === false) {
            throw new ImportException(sprintf('%s: invalid XML', $name));
        }
        $type = null;
        foreach ($xml->ITEM as $item) {
            if (isset($item->COLOR, $item->COLORNAME) && !isset($item->ITEMID)) {
                $this->colors[(int) $item->COLOR] = [
                    'name' => self::clean((string) $item->COLORNAME),
                    'rgb' => isset($item->COLORRGB) && (string) $item->COLORRGB !== ''
                        ? strtoupper((string) $item->COLORRGB) : null,
                ];
                $type = 'colors';
            } elseif (isset($item->ITEMID)) {
                $itemType = isset($item->ITEMTYPE) ? (string) $item->ITEMTYPE : 'P';
                if ($itemType !== 'P') {
                    continue;
                }
                $this->addPart(
                    (string) $item->ITEMID,
                    (string) ($item->ITEMNAME ?? ''),
                    isset($item->CATEGORYNAME) ? (string) $item->CATEGORYNAME : null,
                    isset($item->ALTERNATE) ? (string) $item->ALTERNATE : ''
                );
                $type = 'parts';
            }
        }

        return $type;
    }

    private function addPart(string $number, string $name, ?string $category, string $alternates): void
    {
        $number = trim($number);
        if ($number === '') {
            return;
        }
        $list = array_values(array_filter(
            array_map('trim', explode(',', self::clean($alternates))),
            static fn (string $a): bool => $a !== ''
        ));
        $this->parts[$number] = [
            'name' => self::clean($name),
            'category' => $category !== null && trim($category) !== '' ? self::clean($category) : null,
            'alternates' => $list,
        ];
    }

    /** BrickLink files may be UTF-16 or Windows-1252, and names contain HTML entities. */
    private static function toUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($contents, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        return mb_check_encoding($contents, 'UTF-8')
            ? $contents
            : (string) mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
    }

    private static function clean(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
