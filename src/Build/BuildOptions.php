<?php

declare(strict_types=1);

namespace Studbook\Build;

/**
 * Which parts "what can I build" may use and how it compares them.
 *
 * The home collection's loose parts and unlocked sets always count; other
 * collections count only when they allow lending (`collection.can_lend`)
 * and are selected.
 */
final class BuildOptions
{
    /**
     * @param list<int> $others other collections that lend their parts
     */
    public function __construct(
        public readonly int $home,
        public readonly array $others = [],
        public readonly string $mode = 'variant',
        public readonly bool $minifigs = true,
        public readonly bool $anyColor = false,
    ) {
    }

    /**
     * Options from a query string or a stored build; unknown or unavailable values fall back to defaults.
     *
     * @param array<string, mixed> $input
     * @param list<array{id: int, name: string, can_lend: int|bool}> $collections active collections
     */
    public static function from(array $input, array $collections): ?self
    {
        if ($collections === []) {
            return null;
        }
        $ids = array_map(static fn (array $c): int => (int) $c['id'], $collections);
        $home = (int) ($input['home'] ?? 0);
        if (!in_array($home, $ids, true)) {
            $home = $ids[0];
        }
        $lenders = [];
        foreach ($collections as $c) {
            if ((bool) $c['can_lend'] && (int) $c['id'] !== $home) {
                $lenders[] = (int) $c['id'];
            }
        }
        if (isset($input['others']) || isset($input['opt'])) {
            $wanted = array_map('intval', is_array($input['others'] ?? null) ? $input['others'] : []);
            $others = array_values(array_intersect($lenders, $wanted));
        } else {
            $others = $lenders; // first visit: every lending collection is selected
        }
        $mode = (string) ($input['mode'] ?? 'variant');

        return new self(
            $home,
            $others,
            in_array($mode, PartEquivalence::MODES, true) ? $mode : 'variant',
            ($input['figs'] ?? '1') !== '0',
            ($input['any_color'] ?? '0') === '1',
        );
    }

    /** @return list<int> collections whose parts are used */
    public function collections(): array
    {
        return array_values(array_unique([$this->home, ...$this->others]));
    }

    /** @return array<string, mixed> query-string form (also stored with a build) */
    public function toArray(): array
    {
        return [
            'home' => $this->home,
            'others' => $this->others,
            'mode' => $this->mode,
            'figs' => $this->minifigs ? '1' : '0',
            'any_color' => $this->anyColor ? '1' : '0',
            'opt' => '1',
        ];
    }

    public function query(): string
    {
        return http_build_query($this->toArray());
    }
}
