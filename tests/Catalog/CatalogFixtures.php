<?php

declare(strict_types=1);

namespace Studbook\Tests\Catalog;

use Studbook\Catalog\RebrickableDownloader;

/** Copies the CSV fixtures into a temporary folder as fresh `.csv.gz` downloads. */
final class CatalogFixtures
{
    public static function downloadFolder(): string
    {
        $dir = sys_get_temp_dir() . '/studbook-rb-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (RebrickableDownloader::FILES as $key) {
            $csv = (string) file_get_contents(dirname(__DIR__) . '/fixtures/rebrickable/' . $key . '.csv');
            file_put_contents($dir . '/' . $key . '.csv.gz', gzencode($csv));
        }

        return $dir;
    }

    /** @return array<string, string> */
    public static function files(string $dir): array
    {
        $files = [];
        foreach (RebrickableDownloader::FILES as $key) {
            $files[$key] = $dir . '/' . $key . '.csv.gz';
        }

        return $files;
    }

    public static function remove(string $dir): void
    {
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
