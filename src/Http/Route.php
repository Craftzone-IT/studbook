<?php

declare(strict_types=1);

namespace Studbook\Http;

final class Route
{
    /**
     * @param callable(Request, array<string, string>): Response $handler
     * @param bool $light read-only request (images, small lookups): the session is released
     *        right after the login check, so it does not block other requests of the same user
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly mixed $handler,
        public readonly bool $public = false,
        public readonly bool $light = false,
    ) {
    }
}
