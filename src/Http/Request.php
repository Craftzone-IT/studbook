<?php

declare(strict_types=1);

namespace Studbook\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, array{tmp_name: string, name: string, size: int, error: int}> $files
     *        uploaded files (only genuine uploads when built from globals)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = [],
        private readonly array $files = [],
    ) {
    }

    /** Builds a request from PHP globals; `$basePath` is stripped from the path. */
    public static function fromGlobals(string $basePath = ''): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . ltrim($path, '/');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
            $_SERVER,
            self::uploadedFiles($_FILES),
        );
    }

    /**
     * An uploaded file, or null when none was sent. `error` is one of PHP's UPLOAD_ERR_* codes.
     *
     * @return array{tmp_name: string, name: string, size: int, error: int}|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) && $file['error'] !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    /**
     * Single-file fields of `$_FILES`; a file that is not a genuine upload is dropped.
     *
     * @param array<string, mixed> $files
     * @return array<string, array{tmp_name: string, name: string, size: int, error: int}>
     */
    private static function uploadedFiles(array $files): array
    {
        $result = [];
        foreach ($files as $key => $file) {
            if (!is_array($file) || !is_string($file['tmp_name'] ?? null)) {
                continue; // multi-file fields are not used
            }
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_OK && !is_uploaded_file($file['tmp_name'])) {
                continue;
            }
            $result[(string) $key] = [
                'tmp_name' => $file['tmp_name'],
                'name' => (string) ($file['name'] ?? ''),
                'size' => (int) ($file['size'] ?? 0),
                'error' => $error,
            ];
        }

        return $result;
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** @return array<string, mixed> all query parameters (values may be arrays, e.g. `others[]`) */
    public function queryAll(): array
    {
        return $this->query;
    }

    /** @return array<string, mixed> all POST fields (values may be arrays) */
    public function inputAll(): array
    {
        return $this->post;
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function server(string $key): string
    {
        $value = $this->server[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Client IP. `X-Forwarded-For` is honoured only when the direct peer is a
     * trusted proxy; the right-most untrusted address is used.
     *
     * @param list<string> $trustedProxies
     */
    public function clientIp(array $trustedProxies = []): string
    {
        $remote = $this->server('REMOTE_ADDR');
        if ($remote === '' || !in_array($remote, $trustedProxies, true)) {
            return $remote;
        }
        $forwarded = array_reverse(array_map('trim', explode(',', $this->server('HTTP_X_FORWARDED_FOR'))));
        foreach ($forwarded as $ip) {
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $trustedProxies, true)) {
                return $ip;
            }
        }

        return $remote;
    }
}
