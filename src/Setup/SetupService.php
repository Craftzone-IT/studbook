<?php

declare(strict_types=1);

namespace Studbook\Setup;

use PDO;
use Studbook\Auth\UserRepository;
use Studbook\Config;
use Studbook\Database\Migrator;

/**
 * Works out whether the instance still needs setup (database unreachable,
 * migrations pending, or no user yet) and runs the environment checks shown
 * on the setup page.
 */
final class SetupService
{
    public const MIN_PHP_VERSION = '8.2.0';
    public const MIN_TOKEN_LENGTH = 16;

    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /** @var \Closure(): PDO */
    private \Closure $pdoFactory;
    private ?PDO $pdo = null;
    private ?string $databaseError = null;
    private bool $connected = false;

    /** @param callable(): PDO $pdoFactory */
    public function __construct(private readonly Config $config, callable $pdoFactory)
    {
        $this->pdoFactory = \Closure::fromCallable($pdoFactory);
    }

    /** The database connection, or null when it cannot be opened (see {@see databaseError()}). */
    public function pdo(): ?PDO
    {
        if (!$this->connected) {
            $this->connected = true;
            try {
                $this->pdo = ($this->pdoFactory)();
            } catch (\PDOException $e) {
                $this->databaseError = $e->getMessage();
            }
        }

        return $this->pdo;
    }

    public function databaseError(): ?string
    {
        $this->pdo();

        return $this->databaseError;
    }

    public function migrator(): ?Migrator
    {
        $pdo = $this->pdo();

        return $pdo === null ? null : new Migrator($pdo, $this->config->rootPath() . '/migrations');
    }

    /** @return list<string> */
    public function pendingMigrations(): array
    {
        return $this->migrator()?->pending() ?? [];
    }

    /** True when at least one user exists; false when unknown (no database or no table yet). */
    public function userExists(): bool
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            return (new UserRepository($pdo))->count() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    public function isNeeded(): bool
    {
        return $this->pdo() === null || $this->pendingMigrations() !== [] || !$this->userExists();
    }

    /** @return 'missing'|'too_short'|'ok' */
    public function tokenStatus(): string
    {
        $token = $this->config->get('SETUP_TOKEN');
        if ($token === '') {
            return 'missing';
        }

        return strlen($token) < self::MIN_TOKEN_LENGTH ? 'too_short' : 'ok';
    }

    public function tokenMatches(string $submitted): bool
    {
        return $this->tokenStatus() === 'ok' && hash_equals($this->config->get('SETUP_TOKEN'), $submitted);
    }

    /** Fingerprint stored in the session, so changing the token ends earlier setup sessions. */
    public function tokenFingerprint(): string
    {
        return hash('sha256', 'studbook-setup|' . $this->config->get('SETUP_TOKEN'));
    }

    /**
     * @return list<array{label: string, params: array<string, string>, status: string, detail: ?string}>
     *         label is a translation key
     */
    public function checks(): array
    {
        $checks = [];
        $checks[] = $this->check(
            'setup.check.php_version',
            ['required' => self::MIN_PHP_VERSION, 'current' => PHP_VERSION],
            version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=') ? self::OK : self::ERROR
        );
        foreach (['pdo_mysql', 'mbstring'] as $extension) {
            $checks[] = $this->check(
                'setup.check.extension_required',
                ['name' => $extension],
                extension_loaded($extension) ? self::OK : self::ERROR
            );
        }
        $checks[] = $this->check(
            'setup.check.extension_recommended',
            ['name' => 'intl'],
            extension_loaded('intl') ? self::OK : self::WARNING
        );
        $checks[] = $this->check(
            'setup.check.database',
            ['name' => $this->config->get('DB_NAME'), 'host' => $this->config->get('DB_HOST')],
            $this->databaseError() === null ? self::OK : self::ERROR,
            $this->databaseError()
        );
        $storage = $this->config->path('STORAGE_PATH', 'storage');
        $checks[] = $this->check(
            'setup.check.storage',
            ['path' => $storage],
            $this->ensureWritableDirectory($storage) ? self::OK : self::ERROR
        );

        return $checks;
    }

    /** @param list<array{status: string}> $checks */
    public static function hasErrors(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === self::ERROR) {
                return true;
            }
        }

        return false;
    }

    private function ensureWritableDirectory(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return is_dir($path) && is_writable($path);
    }

    /**
     * @param array<string, string> $params
     * @return array{label: string, params: array<string, string>, status: string, detail: ?string}
     */
    private function check(string $label, array $params, string $status, ?string $detail = null): array
    {
        return ['label' => $label, 'params' => $params, 'status' => $status, 'detail' => $detail];
    }
}
