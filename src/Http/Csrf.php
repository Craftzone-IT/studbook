<?php

declare(strict_types=1);

namespace Studbook\Http;

/** Per-session synchroniser token, checked on every POST. */
final class Csrf
{
    public const FIELD = '_csrf';
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public static function isValid(string $submitted): bool
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($token) && $token !== '' && hash_equals($token, $submitted);
    }

    /** Rotates the token, e.g. after login. */
    public static function reset(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
