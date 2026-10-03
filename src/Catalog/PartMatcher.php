<?php

declare(strict_types=1);

namespace Studbook\Catalog;

/**
 * Finds the BrickLink item number for a Rebrickable part number: first an
 * identical BrickLink number, then BrickLink's "alternate item numbers".
 * Prints and other variants without a counterpart stay unmatched and are
 * reported by the importer.
 */
final class PartMatcher
{
    public const EXACT = 'exact';
    public const ALTERNATE = 'alternate';

    /** @var array<string, true> lower-case BL number => true */
    private array $numbers = [];
    /** @var array<string, string> lower-case BL number => original BL number */
    private array $original = [];
    /** @var array<string, list<string>> lower-case alternate => BL numbers */
    private array $alternates = [];

    /** @param array<int|string, array{name: string, category: ?string, alternates: list<string>}> $blParts */
    public function __construct(array $blParts)
    {
        foreach ($blParts as $number => $part) {
            $key = strtolower((string) $number);
            $this->numbers[$key] = true;
            $this->original[$key] = (string) $number;
            foreach ($part['alternates'] as $alternate) {
                $this->alternates[strtolower($alternate)][] = (string) $number;
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->numbers === [];
    }

    /** @return array{bl_num: string, method: string}|null */
    public function match(string $rbNum): ?array
    {
        $key = strtolower($rbNum);
        if (isset($this->numbers[$key])) {
            return ['bl_num' => $this->original[$key], 'method' => self::EXACT];
        }
        $candidates = array_values(array_unique($this->alternates[$key] ?? []));
        if (count($candidates) === 1) {
            return ['bl_num' => $candidates[0], 'method' => self::ALTERNATE];
        }

        return null;
    }
}
