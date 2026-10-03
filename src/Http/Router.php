<?php

declare(strict_types=1);

namespace Studbook\Http;

/**
 * Minimal router. Patterns use `{name}` placeholders that match one path
 * segment, e.g. `/b/{id}`; `{id}` only matches digits. Routes are private (login required) unless
 * registered with `$public = true`.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @param callable(Request, array<string, string>): Response $handler */
    public function get(string $pattern, callable $handler, bool $public = false): void
    {
        $this->routes[] = new Route('GET', $pattern, $handler, $public);
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function post(string $pattern, callable $handler, bool $public = false): void
    {
        $this->routes[] = new Route('POST', $pattern, $handler, $public);
    }

    /**
     * @return array{0: Route, 1: array<string, string>}|null|false
     *         the route and its parameters; null if no path matches; false if
     *         the path matches but not the method.
     */
    public function match(string $method, string $path): array|null|false
    {
        $pathMatched = false;
        // HEAD is answered like GET (the body is discarded by the SAPI).
        $method = $method === 'HEAD' ? 'GET' : $method;
        foreach ($this->routes as $route) {
            $params = self::matchPattern($route->pattern, $path);
            if ($params === null) {
                continue;
            }
            if ($route->method === $method) {
                return [$route, $params];
            }
            $pathMatched = true;
        }

        return $pathMatched ? false : null;
    }

    /** @return array<string, string>|null */
    public static function matchPattern(string $pattern, string $path): ?array
    {
        $regex = preg_replace_callback(
            '/\\\{([a-z_][a-z0-9_]*)\\\}/i',
            // `{id}` matches digits only; other placeholders any single segment.
            static fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[1] === 'id' ? '\d+' : '[^/]+') . ')',
            preg_quote($pattern, '#')
        );
        if (!preg_match('#^' . $regex . '$#', $path, $matches)) {
            return null;
        }

        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
