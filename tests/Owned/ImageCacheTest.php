<?php

declare(strict_types=1);

namespace Studbook\Tests\Owned;

use Studbook\Catalog\ImageCache;
use Studbook\Catalog\ImageNotFound;

final class ImageCacheTest extends OwnedTestCase
{
    private string $dir;
    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/studbook-imgcache-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            foreach (
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                ) as $file
            ) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->dir);
        }
    }

    public function testFetchesOnceThenServesFromDisk(): void
    {
        $cache = $this->cache(fn (string $url): array => $this->serve($url, 'image/jpeg; charset=binary'));

        $first = $cache->get('3001', 4);
        self::assertNotNull($first);
        self::assertSame('image/jpeg', $first['content_type']);
        self::assertStringEndsWith('.jpg', $first['path']);
        self::assertSame('JPEGDATA', file_get_contents($first['path']));

        $cache->get('3001', 4);
        self::assertSame(['https://cdn.rebrickable.com/media/parts/3001-4.jpg'], $this->requested);
    }

    public function testAnyColourUsesTheFirstAvailableImage(): void
    {
        $cache = $this->cache(fn (string $url): array => $this->serve($url, 'image/jpeg'));

        self::assertNotNull($cache->get('3001', ImageCache::ANY_COLOR));
        self::assertSame(['https://cdn.rebrickable.com/media/parts/3001-4.jpg'], $this->requested);
    }

    public function testTemporaryFailuresAreRetriedAfterTenMinutes(): void
    {
        $cache = $this->cache(function (string $url): array {
            $this->requested[] = $url;
            throw new \RuntimeException('timeout');
        });

        self::assertNull($cache->get('3001', 4));
        self::assertNull($cache->get('3001', 4));
        self::assertCount(1, $this->requested);

        self::assertSame('error', $cache->lastMiss);
        $this->pdo->exec("UPDATE cat_image_cache SET fetched_at = UTC_TIMESTAMP() - INTERVAL 11 MINUTE");
        $cache->get('3001', 4);
        self::assertCount(2, $this->requested, 'retried after ten minutes');
    }

    public function testMissingImagesAreRetriedAfterAWeek(): void
    {
        $cache = $this->cache(function (string $url): array {
            $this->requested[] = $url;
            throw new ImageNotFound();
        });

        self::assertNull($cache->get('3001', 4));
        $this->pdo->exec("UPDATE cat_image_cache SET fetched_at = UTC_TIMESTAMP() - INTERVAL 2 DAY");
        self::assertNull($cache->get('3001', 4));
        self::assertCount(1, $this->requested);

        $this->pdo->exec("UPDATE cat_image_cache SET fetched_at = UTC_TIMESTAMP() - INTERVAL 8 DAY");
        $cache->get('3001', 4);
        self::assertCount(2, $this->requested, 'retried after a week');
    }

    public function testRejectsNonImagesAndUnknownHosts(): void
    {
        $cache = $this->cache(fn (string $url): array => $this->serve($url, 'text/html'));
        self::assertNull($cache->get('3001', 4));

        $this->pdo->exec(
            "UPDATE cat_part_color SET img_url = 'https://evil.example/x.jpg' WHERE part = '3001' AND color_id = 0"
        );
        self::assertNull($cache->get('3001', 0));
        self::assertCount(1, $this->requested, 'URLs outside rebrickable.com are never requested');
    }

    public function testAllowedUrls(): void
    {
        self::assertTrue(ImageCache::isAllowedUrl('https://cdn.rebrickable.com/media/parts/x.jpg'));
        self::assertFalse(ImageCache::isAllowedUrl('http://cdn.rebrickable.com/media/parts/x.jpg'));
        self::assertFalse(ImageCache::isAllowedUrl('https://rebrickable.com.evil.example/x.jpg'));
        self::assertFalse(ImageCache::isAllowedUrl('https://cdn.rebrickable.com:8443/x.jpg'));
    }

    public function testBusySlotsAnswerAtOnceWithoutRemembering(): void
    {
        $cache = $this->cache(fn (string $url): array => $this->serve($url, 'image/jpeg'));
        mkdir($this->dir . '/.locks', 0775, true);
        $held = [];
        for ($i = 0; $i < ImageCache::MAX_PARALLEL; $i++) {
            $held[$i] = fopen($this->dir . '/.locks/fetch-' . $i . '.lock', 'c');
            flock($held[$i], LOCK_EX);
        }
        // flock() locks belong to the file description, so a second fopen() in the same
        // process sees them as taken, like another PHP worker would.
        self::assertNull($cache->get('3001', 4));
        self::assertSame('busy', $cache->lastMiss);
        self::assertSame([], $this->requested, 'nothing is downloaded while all slots are busy');
        self::assertSame(0, $this->rowCount('cat_image_cache'), 'a busy answer is not remembered');

        flock($held[1], LOCK_UN);
        self::assertNotNull($cache->get('3001', 4), 'a free slot is used');
        self::assertNull($cache->lastMiss);
        self::assertNull($cache->get('3024', 4));
        self::assertSame('missing', $cache->lastMiss, 'no image URL for this colour');
        array_map('fclose', $held);
    }

    public function testUnusableLockFolderDoesNotStopDownloads(): void
    {
        $cache = $this->cache(fn (string $url): array => $this->serve($url, 'image/jpeg'));
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/.locks', 'a file where the lock folder should be');

        $logged = ini_set('error_log', '/dev/null');
        try {
            self::assertNotNull($cache->get('3001', 4), 'downloaded without a limit rather than never');
        } finally {
            ini_set('error_log', (string) $logged);
        }
        self::assertNull($cache->lastMiss);
    }

    private function cache(callable $fetch): ImageCache
    {
        return new ImageCache($this->pdo, $this->dir, 'test', $fetch);
    }

    /** @return array{body: string, content_type: string} */
    private function serve(string $url, string $type): array
    {
        $this->requested[] = $url;

        return ['body' => 'JPEGDATA', 'content_type' => $type];
    }
}
