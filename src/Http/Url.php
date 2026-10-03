<?php

declare(strict_types=1);

namespace Studbook\Http;

/** Builds app-relative URLs that respect a base path (app installed in a sub-folder). */
final class Url
{
    private static string $basePath = '';

    public static function setBasePath(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/');
    }

    public static function to(string $path): string
    {
        return self::$basePath . '/' . ltrim($path, '/');
    }
}
