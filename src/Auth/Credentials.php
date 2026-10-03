<?php

declare(strict_types=1);

namespace Studbook\Auth;

/** Rules for usernames and passwords, shared by the setup page and `bin/create-user`. */
final class Credentials
{
    public const MIN_PASSWORD_LENGTH = 10;
    public const USERNAME_PATTERN = '/^[\p{L}\p{N}._@-]{1,64}$/u';

    /** @return string|null translation key of the problem, or null when valid */
    public static function usernameError(string $username): ?string
    {
        return preg_match(self::USERNAME_PATTERN, $username) === 1 ? null : 'credentials.username_invalid';
    }

    /** @return string|null translation key of the problem, or null when valid */
    public static function passwordError(string $password, ?string $repeat = null): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'credentials.password_too_short';
        }
        if ($repeat !== null && !hash_equals($password, $repeat)) {
            return 'credentials.password_mismatch';
        }

        return null;
    }
}
