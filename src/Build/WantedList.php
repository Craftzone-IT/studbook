<?php

declare(strict_types=1);

namespace Studbook\Build;

use PDO;

/**
 * BrickLink wanted-list XML (Want → Upload) for missing parts. Parts or
 * colours without a BrickLink id cannot be uploaded; they are listed in a
 * comment at the top of the file instead.
 */
final class WantedList
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<array{part: string, color_id: int, qty: int}> $items Rebrickable part and colour ids
     */
    public function xml(array $items): string
    {
        $items = array_values(array_filter($items, static fn (array $i): bool => $i['qty'] > 0));
        $parts = $this->lookup(
            'SELECT rb_num, bl_num FROM cat_part WHERE rb_num IN (%s)',
            array_values(array_unique(array_column($items, 'part')))
        );
        $colors = $this->lookup(
            'SELECT rb_id, bl_id FROM cat_color WHERE rb_id IN (%s)',
            array_values(array_unique(array_column($items, 'color_id')))
        );

        $lines = [];
        $skipped = [];
        foreach ($items as $item) {
            $bl = $parts['k:' . $item['part']] ?? null;
            $color = $colors['k:' . $item['color_id']] ?? null;
            if ($bl === null || $color === null) {
                $skipped[] = $item['qty'] . ' x ' . $item['part'] . ' / ' . $item['color_id'];
                continue;
            }
            $lines[] = '  <ITEM>'
                . '<ITEMTYPE>P</ITEMTYPE>'
                . '<ITEMID>' . self::escape($bl) . '</ITEMID>'
                . '<COLOR>' . (int) $color . '</COLOR>'
                . '<MINQTY>' . $item['qty'] . '</MINQTY>'
                . '</ITEM>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        if ($skipped !== []) {
            $list = str_replace('--', '- -', implode(', ', $skipped));
            $xml .= '<!-- No BrickLink id (Rebrickable part / colour): ' . $list . ' -->' . "\n";
        }

        return $xml . "<INVENTORY>\n" . implode("\n", $lines) . ($lines !== [] ? "\n" : '') . "</INVENTORY>\n";
    }

    /**
     * @param list<string|int> $keys
     * @return array<string, string> 'k:' . key => BrickLink id (only where known)
     */
    private function lookup(string $sql, array $keys): array
    {
        $result = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $stmt = $this->pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($chunk), '?'))));
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$key, $bl]) {
                if ($bl !== null && $bl !== '') {
                    $result['k:' . $key] = (string) $bl;
                }
            }
        }

        return $result;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
