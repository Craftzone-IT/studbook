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
