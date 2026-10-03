<?php

declare(strict_types=1);

namespace Studbook\Cli;

use Studbook\Config;

/** Small helpers shared by the scripts in `bin/`. */
final class Console
{
    public static function config(): Config
    {
        try {
            return Config::load(dirname(__DIR__, 2));
        } catch (\Throwable $e) {
            self::fail($e->getMessage());
        }
    }

    public static function out(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    public static function err(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }

    public static function fail(string $message, int $code = 1): never
    {
        self::err('Error: ' . $message);
        exit($code);
    }

    public static function prompt(string $question): string
    {
        fwrite(STDOUT, $question);
        $line = fgets(STDIN);

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    /** Reads a line without echoing it when running on a terminal. */
    public static function promptHidden(string $question): string
    {
        $interactive = function_exists('posix_isatty') ? posix_isatty(STDIN) : stream_isatty(STDIN);
        if (!$interactive) {
            return self::prompt('');
        }
        fwrite(STDOUT, $question);
        shell_exec('stty -echo');
        try {
            $line = fgets(STDIN);
        } finally {
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }

        return $line === false ? '' : rtrim($line, "\r\n");
    }
}
