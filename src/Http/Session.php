<?php

declare(strict_types=1);

namespace Studbook\Http;

/**
 * Hardened native PHP session: strict mode, cookie-only, HttpOnly,
 * SameSite=Lax, Secure when the app runs on HTTPS.
 */
final class Session
{
    public const NAME = 'studbook_session';

    public static function start(bool $secure, string $path): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $path === '' ? '/' : $path . '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Writes the session and releases its lock. PHP keeps the session file locked for the whole
     * request, so without this every image of a page would wait for the one before it.
     */
    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Issues a new session ID (on login) to prevent session fixation. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];

            return;
        }
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name() ?: self::NAME, '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
    }

    /** Stores a one-time message shown on the next page, optionally with an "Undo" button for a batch. */
    public static function flash(string $type, string $message, ?int $undoBatch = null): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message, 'undo' => $undoBatch];
    }

    /** @return list<array{type: string, message: string, undo?: ?int}> */
    public static function takeFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($flashes) ? array_values($flashes) : [];
    }
}
