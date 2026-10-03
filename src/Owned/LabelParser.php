<?php

declare(strict_types=1);

namespace Studbook\Owned;

use Studbook\Catalog\CatalogRepository;

/**
 * Turns the part numbers typed for a box label (BrickLink or Rebrickable
 * numbers, separated by spaces, commas, semicolons or new lines) into
 * Rebrickable numbers, and reports the ones not found in the catalogue.
 */
final class LabelParser
{
    public const MAX_LABELS = 200;

    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    /** @return array{parts: list<string>, unknown: list<string>} */
    public function parse(string $input): array
    {
        $tokens = preg_split('/[\s,;]+/u', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];
        $unknown = [];
        foreach (array_slice($tokens, 0, self::MAX_LABELS) as $token) {
            $part = $this->catalog->resolvePart($token);
            if ($part === null) {
                $unknown[] = $token;
            } elseif (!in_array($part['rb_num'], $parts, true)) {
                $parts[] = $part['rb_num'];
            }
        }

        return ['parts' => $parts, 'unknown' => array_values(array_unique($unknown))];
    }
}
