<?php

declare(strict_types=1);

use Studbook\I18n\Lang;

if (!function_exists('t')) {
    /**
     * Translates a UI string. Every user-facing string goes through this.
     *
     * @param array<string, string|int|float> $params replaces `{name}` placeholders
     */
    function t(string $key, array $params = []): string
    {
        return Lang::translator()->translate($key, $params);
    }
}

if (!function_exists('e')) {
    /** Escapes a value for HTML output (text and attribute context). */
    function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** App-relative URL for a path, e.g. `url('/settings')`. */
    function url(string $path): string
    {
        return \Studbook\Http\Url::to($path);
    }
}

if (!function_exists('swatch')) {
    /**
     * A small colour square as inline SVG (inline style attributes are blocked by the CSP).
     */
    function swatch(string $rgb): string
    {
        $hex = preg_match('/^[0-9A-Fa-f]{6}$/', $rgb) === 1 ? $rgb : 'CCCCCC';

        return '<svg class="swatch" viewBox="0 0 10 10" width="14" height="14" aria-hidden="true">'
            . '<rect width="10" height="10" rx="2" fill="#' . $hex . '" stroke="rgba(0,0,0,.35)"/></svg>';
    }
}

if (!function_exists('coverage_bar')) {
    /**
     * Horizontal bar: parts covered by loose parts, then by sets, out of `$need`
     * (inline SVG, because the CSP blocks inline style widths).
     */
    function coverage_bar(int $need, int $loose, int $sets, string $label): string
    {
        $need = max(1, $need);
        $a = round(100 * min($loose, $need) / $need, 2);
        $b = round(100 * min($sets, $need - min($loose, $need)) / $need, 2);

        return '<svg class="coverage-bar" viewBox="0 0 100 8" preserveAspectRatio="none" role="img" aria-label="'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
            . '<rect class="cov-bg" width="100" height="8" rx="2"/>'
            . '<rect class="cov-loose" width="' . $a . '" height="8"/>'
            . '<rect class="cov-sets" x="' . $a . '" width="' . $b . '" height="8"/></svg>';
    }
}
