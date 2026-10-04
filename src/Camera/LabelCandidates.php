<?php

declare(strict_types=1);

namespace Studbook\Camera;

use Studbook\Catalog\CatalogRepository;

/**
 * Turns OCR text into candidate part numbers checked against the catalogue.
 * Common OCR confusions (O/0, I/1, S/5, B/8, …) are tried when a token is not
 * a known number as read; tokens that stay unknown are offered for editing.
 */
final class LabelCandidates
{
    public const MAX_CANDIDATES = 100;
    /** Letters OCR often reads instead of digits. */
    private const DIGIT_LOOKALIKES = [
        'O' => '0', 'o' => '0', 'Q' => '0', 'D' => '0',
        'I' => '1', 'l' => '1', 'i' => '1', '|' => '1', '!' => '1',
        'Z' => '2', 'z' => '2',
        'S' => '5', 's' => '5',
        'G' => '6', 'b' => '6',
        'T' => '7',
        'B' => '8',
        'g' => '9', 'q' => '9',
    ];

    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    /**
     * @return list<array{raw: string, rb_num: ?string, display: string, name: string, status: string}>
     *         status: `ok` (read as is), `fixed` (after correcting look-alike characters) or `unknown`
     */
    public function fromText(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}|!\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result = [];
        $seen = [];
        foreach ($tokens as $token) {
            $token = trim($token, '-');
            if (
                strlen($token) < 3 || strlen($token) > 20 || preg_match_all('/\d/', $token) < 2
                || preg_match('/^\d+x\d+$/i', $token)
            ) {
                continue; // words, sizes such as 2x4, stray marks
            }
            [$part, $status] = $this->resolve($token);
            $key = 'k:' . ($part['rb_num'] ?? '?' . strtolower($token));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = [
                'raw' => $token,
                'rb_num' => $part['rb_num'] ?? null,
                'display' => $part['display'] ?? $token,
                'name' => $part['name'] ?? '',
                'status' => $status,
            ];
            if (count($result) >= self::MAX_CANDIDATES) {
                break;
            }
        }

        return $result;
    }

    /** @return array{0: ?array{rb_num: string, bl_num: ?string, name: string, display: string}, 1: string} */
    private function resolve(string $token): array
    {
        $part = $this->catalog->resolvePart($token);
        if ($part !== null) {
            return [$part, 'ok'];
        }
        foreach (self::variants($token) as $variant) {
            $part = $this->catalog->resolvePart($variant);
            if ($part !== null) {
                return [$part, 'fixed'];
            }
        }

        return [null, 'unknown'];
    }

    /**
     * Readings with look-alike letters turned into digits: first only in the
     * leading number (so suffixes such as "3942c" or "pb01" survive), then everywhere.
     *
     * @return list<string>
     */
    private static function variants(string $token): array
    {
        $variants = [];
        if (preg_match('/^([^a-z]*[0-9][^a-z]*)([a-z].*)?$/', $token, $m)) {
            $variants[] = strtr($m[1], self::DIGIT_LOOKALIKES) . ($m[2] ?? '');
        }
        $leading = preg_replace_callback(
            '/^[0-9A-Z|!OQDISZGTB]+/',
            static fn (array $x): string => strtr($x[0], self::DIGIT_LOOKALIKES),
            $token
        );
        $variants[] = (string) $leading;
        $variants[] = strtr($token, self::DIGIT_LOOKALIKES);

        return array_values(array_unique(array_filter($variants, static fn (string $v): bool => $v !== $token)));
    }
}
