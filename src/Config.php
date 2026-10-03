<?php

declare(strict_types=1);

namespace Studbook;

/**
 * Instance configuration read from `.env` (and real environment variables, which win).
 *
 * Every key the application reads must be documented in `.env.example`.
 */
final class Config
{
    /** Keys that must be present and non-empty. */
    public const REQUIRED = [
        'APP_URL',
        'APP_ENV',
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
    ];

    /** Keys that must be present but may be empty. */
    public const REQUIRED_MAY_BE_EMPTY = [
        'DB_PASSWORD',
    ];

    /** @param array<string, string> $values */
    public function __construct(private readonly array $values, private readonly string $rootPath)
    {
    }

    /**
     * Loads `.env` from the project root, overlays real environment variables
     * and validates required keys.
     *
     * @throws ConfigException when the file is missing or required keys are absent.
     */
    public static function load(string $rootPath, ?string $envFile = null): self
    {
        $envFile ??= $rootPath . '/.env';
        if (!is_file($envFile)) {
            throw new ConfigException(sprintf(
                'Configuration file %s not found. Copy .env.example to .env and adjust it.',
                $envFile
            ));
        }
        $contents = file_get_contents($envFile);
        if ($contents === false) {
            throw new ConfigException(sprintf('Configuration file %s is not readable.', $envFile));
        }

        $values = self::parse($contents);
        foreach (array_keys($values) as $key) {
            $fromEnv = getenv($key);
            if ($fromEnv !== false) {
                $values[$key] = $fromEnv;
            }
        }

        $config = new self($values, $rootPath);
        $config->validate();

        return $config;
    }

    /**
     * Parses dotenv syntax: `KEY=value`, optional quotes, `#` comments
     * (whole-line, or after whitespace in unquoted values).
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $lineNo => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                throw new ConfigException(sprintf('Invalid line %d in .env: missing "=".', $lineNo + 1));
            }
            $key = trim(substr($line, 0, $pos));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                throw new ConfigException(sprintf('Invalid key "%s" on line %d in .env.', $key, $lineNo + 1));
            }
            $values[$key] = self::parseValue(trim(substr($line, $pos + 1)));
        }

        return $values;
    }

    private static function parseValue(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $quote = $raw[0];
        if ($quote === '"' || $quote === "'") {
            $end = strpos($raw, $quote, 1);
            if ($end === false) {
                throw new ConfigException('Unterminated quoted value in .env.');
            }
            $value = substr($raw, 1, $end - 1);

            return $quote === '"' ? str_replace(['\\n', '\\"'], ["\n", '"'], $value) : $value;
        }

        // Unquoted: strip an inline comment that follows whitespace.
        $value = preg_replace('/\s+#.*$/', '', $raw) ?? $raw;

        return trim($value);
    }

    public function validate(): void
    {
        $missing = [];
        foreach (self::REQUIRED as $key) {
            if (($this->values[$key] ?? '') === '') {
                $missing[] = $key;
            }
        }
        foreach (self::REQUIRED_MAY_BE_EMPTY as $key) {
            if (!array_key_exists($key, $this->values)) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new ConfigException(
                'Missing required configuration keys in .env: ' . implode(', ', $missing)
                . '. See .env.example.'
            );
        }
        if (!in_array($this->values['APP_ENV'], ['production', 'development'], true)) {
            throw new ConfigException('APP_ENV must be "production" or "development".');
        }
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->values[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!isset($this->values[$key]) || $this->values[$key] === '') {
            return $default;
        }

        return filter_var($this->values[$key], FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? '';

        return is_numeric($value) ? (int) $value : $default;
    }

    public function isDevelopment(): bool
    {
        return $this->get('APP_ENV') === 'development';
    }

    /** Debug output (stack traces) is only ever shown in development. */
    public function isDebug(): bool
    {
        return $this->isDevelopment() && $this->bool('APP_DEBUG');
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    /** Resolves a configured path; relative paths are relative to the project root. */
    public function path(string $key, string $default = ''): string
    {
        $path = $this->get($key, $default);
        if ($path === '' || str_starts_with($path, '/')) {
            return $path;
        }

        return $this->rootPath . '/' . $path;
    }

    /** Base path of the app below the host, e.g. "" or "/studbook". */
    public function basePath(): string
    {
        $path = parse_url($this->get('APP_URL'), PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') : '';
    }

    /** @return list<string> reverse proxy IPs whose X-Forwarded-For header is trusted */
    public function trustedProxies(): array
    {
        $list = array_map('trim', explode(',', $this->get('TRUSTED_PROXIES')));

        return array_values(array_filter($list, static fn (string $ip): bool => $ip !== ''));
    }

    public function isHttps(): bool
    {
        return str_starts_with(strtolower($this->get('APP_URL')), 'https://');
    }
}
