<?php

declare(strict_types=1);

namespace Studbook\Http;

final class Route
{
    /**
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly mixed $handler,
        public readonly bool $public = false,
    ) {
    }
}
