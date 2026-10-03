<?php

declare(strict_types=1);

namespace Studbook\Auth;

use Studbook\Http\Csrf;
use Studbook\Http\Session;

final class Auth
{
    private const SESSION_USER = 'user_id';
    private const SESSION_LAST_SEEN = 'last_seen';
    /** Sessions expire after this many seconds without a request. */
    public const IDLE_TIMEOUT = 60 * 60 * 24 * 7;

    /** @var \Closure(): UserRepository */
    private \Closure $usersFactory;
    private ?UserRepository $users = null;

    /**
     * The repository is created lazily, so checking the session never needs
     * a database connection.
     *
     * @param UserRepository|callable(): UserRepository $users
     */
    public function __construct(UserRepository|callable $users)
    {
        $this->usersFactory = $users instanceof UserRepository
            ? static fn (): UserRepository => $users
            : \Closure::fromCallable($users);
    }

    private function users(): UserRepository
    {
        return $this->users ??= ($this->usersFactory)();
    }

    /** @return array{id: int, username: string}|null */
    public function attempt(string $username, string $password): ?array
    {
        $user = $this->users()->findByUsername($username);
        if ($user === null) {
            // Spend comparable time so response timing does not reveal valid usernames.
            password_hash($password, PASSWORD_DEFAULT);

            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users()->updatePassword($user['id'], $password);
        }

        return ['id' => $user['id'], 'username' => $user['username']];
    }

    public function login(int $userId): void
    {
        Session::regenerate();
        Csrf::reset();
        Session::set(self::SESSION_USER, $userId);
        Session::set(self::SESSION_LAST_SEEN, time());
        $this->users()->touchLogin($userId);
    }

    public function logout(): void
    {
        Session::destroy();
    }

    public function userId(): ?int
    {
        $id = Session::get(self::SESSION_USER);
        if (!is_int($id)) {
            return null;
        }
        $lastSeen = Session::get(self::SESSION_LAST_SEEN);
        if (!is_int($lastSeen) || time() - $lastSeen > self::IDLE_TIMEOUT) {
            Session::remove(self::SESSION_USER);

            return null;
        }
        Session::set(self::SESSION_LAST_SEEN, time());

        return $id;
    }

    public function check(): bool
    {
        return $this->userId() !== null;
    }
}
