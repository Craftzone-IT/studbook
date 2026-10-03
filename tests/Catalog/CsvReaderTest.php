<?php

declare(strict_types=1);

namespace Studbook\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Studbook\Catalog\CsvReader;
use Studbook\Catalog\ImportException;

final class CsvReaderTest extends TestCase
{
    public function testReadsPlainAndGzipFilesWithQuotedFields(): void
    {
        $plain = dirname(__DIR__) . '/fixtures/rebrickable/parts.csv';
        $gz = sys_get_temp_dir() . '/studbook-parts-' . bin2hex(random_bytes(4)) . '.csv.gz';
        file_put_contents($gz, gzencode((string) file_get_contents($plain)));

        try {
            $fromPlain = iterator_to_array(CsvReader::rows($plain, ['part_num', 'name']), false);
            $fromGz = iterator_to_array(CsvReader::rows($gz, ['part_num', 'name']), false);
        } finally {
            unlink($gz);
        }

        self::assertSame($fromPlain, $fromGz);
        self::assertCount(6, $fromPlain);
        self::assertSame('Torso, Plain', $fromPlain[5]['name']);
    }

    public function testMissingColumnsAreReported(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('missing column(s) nope');
        iterator_to_array(CsvReader::rows(dirname(__DIR__) . '/fixtures/rebrickable/themes.csv', ['id', 'nope']));
    }
}
