<?php

declare(strict_types=1);

namespace Studbook\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = [],
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
        );
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
