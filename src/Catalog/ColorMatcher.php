<?php

declare(strict_types=1);

namespace Studbook\Catalog;

/**
 * Matches Rebrickable colours to BrickLink colours. Rebrickable mostly uses
 * BrickLink's colour names, so names are compared after normalisation;
 * a short alias table covers known differences.
 */
final class ColorMatcher
{
    /** Normalised Rebrickable name => normalised BrickLink name. */
    private const ALIASES = [
        'nocoloranycolor' => 'notapplicable',
    ];

    /** @var array<string, list<int>> normalised BL name => BL ids */
    private array $byName = [];

    /** @param array<int, array{name: string, rgb: ?string}> $blColors */
    public function __construct(private readonly array $blColors)
    {
        foreach ($blColors as $id => $color) {
            $this->byName[self::normalise($color['name'])][] = $id;
        }
    }

    /** @return array{bl_id: int, bl_name: string}|null */
    public function match(string $rbName, string $rbRgb = ''): ?array
    {
        $key = self::normalise($rbName);
        $key = self::ALIASES[$key] ?? $key;
        $ids = $this->byName[$key] ?? [];
        if (count($ids) > 1 && $rbRgb !== '') {
            $sameRgb = array_values(array_filter(
                $ids,
                fn (int $id): bool => strcasecmp((string) $this->blColors[$id]['rgb'], $rbRgb) === 0
            ));
            $ids = $sameRgb !== [] ? $sameRgb : $ids;
        }
        if (count($ids) !== 1) {
            return null;
        }

        return ['bl_id' => $ids[0], 'bl_name' => $this->blColors[$ids[0]]['name']];
    }

    public static function normalise(string $name): string
    {
        $name = strtolower($name);
        $name = str_replace(['grey'], ['gray'], $name);

        return preg_replace('/[^a-z0-9]+/', '', $name) ?? $name;
    }
}
