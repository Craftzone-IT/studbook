<?php

declare(strict_types=1);

namespace Studbook\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Studbook\Catalog\ImportException;
use Studbook\Catalog\RebrickableDownloader;

final class RebrickableDownloaderTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $requested = [];
    private int $now = 2_000_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/studbook-dl-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testDownloadsOnceThenReusesWithinTheInterval(): void
    {
        $downloader = $this->downloader(fn (string $url, string $target) => $this->serveGzip($url, $target));

        $paths = $downloader->fetchAll(static fn () => null);
        self::assertCount(count(RebrickableDownloader::FILES), $this->requested);
        self::assertSame('https://cdn.example/dl/themes.csv.gz', $this->requested[0]);
        self::assertFileExists($paths['parts']);

        $this->requested = [];
        $this->now += 23 * 3600;
        $downloader->fetchAll(static fn () => null);
        self::assertSame([], $this->requested, 'no second download within 24 hours');

        $this->now += 2 * 3600;
        $downloader->fetchAll(static fn () => null);
        self::assertCount(count(RebrickableDownloader::FILES), $this->requested);
    }

    public function testIntervalBelowOneDayIsNotAllowed(): void
    {
        $downloader = $this->downloader(fn (string $url, string $target) => $this->serveGzip($url, $target), 1);
        $downloader->fetchAll(static fn () => null);
        $this->requested = [];
        $this->now += 2 * 3600;

        $downloader->fetchAll(static fn () => null);
        self::assertSame([], $this->requested);
    }

    public function testFailedDownloadFallsBackToOlderCopy(): void
    {
        $this->downloader(fn (string $url, string $target) => $this->serveGzip($url, $target))
            ->fetchAll(static fn () => null);
        $this->now += 8 * 86400;
        $log = [];

        $paths = $this->downloader(static function (): void {
            throw new \RuntimeException('HTTP 500');
        })->fetchAll(static function (string $line) use (&$log): void {
            $log[] = $line;
        });

        self::assertFileExists($paths['sets']);
        self::assertStringContainsString('WARNING', $log[0]);
        self::assertStringContainsString('HTTP 500', $log[0]);
    }

    public function testFailedDownloadWithoutCopyFails(): void
    {
        $this->expectException(ImportException::class);
        $this->downloader(static function (): void {
            throw new \RuntimeException('offline');
        })->fetchAll(static fn () => null);
    }

    public function testNonGzipResponseIsRejected(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('not a gzip file');
        $this->downloader(static function (string $url, string $target): void {
            file_put_contents($target, '<html>Rate limited</html>');
        })->fetchAll(static fn () => null);
    }

    private function downloader(callable $fetch, int $interval = 24): RebrickableDownloader
    {
        $clock = fn (): int => $this->now;

        return new RebrickableDownloader('https://cdn.example/dl/', $this->dir, $interval, 'test', $fetch, $clock);
    }

    private function serveGzip(string $url, string $target): void
    {
        $this->requested[] = $url;
        file_put_contents($target, gzencode("id,name\n1,x\n"));
    }
}
