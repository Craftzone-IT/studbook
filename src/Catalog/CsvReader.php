<?php

declare(strict_types=1);

namespace Studbook\Catalog;

/**
 * Streams rows of a comma-separated file with a header line as associative
 * arrays. Reads `.csv` and gzip-compressed `.csv.gz` files.
 */
final class CsvReader
{
    /**
     * @param list<string> $requiredColumns
     * @return \Generator<int, array<string, string>>
     * @throws ImportException when the file cannot be read or columns are missing
     */
    public static function rows(string $path, array $requiredColumns = []): \Generator
    {
        $handle = @fopen(str_ends_with($path, '.gz') ? 'compress.zlib://' . $path : $path, 'rb');
        if ($handle === false) {
            throw new ImportException(sprintf('Cannot open %s', basename($path)));
        }
        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if (!is_array($header) || $header === [null]) {
                throw new ImportException(sprintf('%s is empty', basename($path)));
            }
            $header = array_map(static fn ($h): string => trim((string) $h, "\xEF\xBB\xBF \t"), $header);
            $missing = array_diff($requiredColumns, $header);
            if ($missing !== []) {
                throw new ImportException(sprintf(
                    '%s: missing column(s) %s (found: %s)',
                    basename($path),
                    implode(', ', $missing),
                    implode(', ', $header)
                ));
            }
            $count = count($header);
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                $row = array_map('strval', $row);
                if (count($row) !== $count) {
                    $row = array_slice(array_pad($row, $count, ''), 0, $count);
                }
                yield array_combine($header, $row);
            }
        } finally {
            fclose($handle);
        }
    }
}
