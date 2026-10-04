<?php

declare(strict_types=1);

namespace Studbook\Tests\Owned;

use Studbook\Catalog\ImageCache;
use Studbook\Catalog\ImageWarmer;

final class ImageWarmerTest extends OwnedTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/studbook-warm-' . bin2hex(random_bytes(4));
        $this->pdo->exec("INSERT INTO cat_set (set_num, name, year, theme_id, num_parts, img_url) VALUES
            ('6000-1', 'Small Set', 1990, NULL, 3, 'https://cdn.rebrickable.com/media/sets/6000-1.jpg')");
        $this->pdo->exec("INSERT INTO cat_inventory (set_num, part, color_id, is_spare, from_minifig, quantity) VALUES
            ('6000-1', '3024', 71, 0, 0, 3), ('6000-1', '3001', 0, 1, 0, 1)");
    }

    protected function tearDown(): void
    {
        foreach (['/.locks', '/??', ''] as $sub) {
            foreach (glob($this->dir . $sub . '/*') ?: [] as $file) {
                is_file($file) && unlink($file);
            }
        }
        foreach (array_merge(glob($this->dir . '/??') ?: [], [$this->dir . '/.locks', $this->dir]) as $dir) {
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function testOwnedThingsFirstAndOnlyWhatIsNotCached(): void
    {
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $inbox = $this->queries->inboxId($id);
        $this->owned->addLot($inbox, '3001', 4, 2);
        $this->owned->setLabels($inbox, ['3024']);
        $this->pdo->exec("INSERT INTO owned_set (collection_id, set_num, state, lock_mode, updated_at)
            VALUES ({$id}, '6000-1', 'built', 'locked', UTC_TIMESTAMP())");

        $downloads = [];
        $cache = new ImageCache($this->pdo, $this->dir, 'test', function (string $url) use (&$downloads): array {
            $downloads[] = $url;

            return ['body' => 'JPEG', 'content_type' => 'image/jpeg'];
        });
        $warmer = new ImageWarmer($this->pdo, $cache, 10, static fn () => null);
        self::assertSame(
            [['3001', 4], ['3024', -1], ['6000-1', -2], ['3024', 71]],
            $warmer->candidates(),
            'lots, labels, set pictures, then set contents (no spares)'
        );

        self::assertSame(2, $warmer->run(static fn () => null), '3024 has no picture URL in this catalogue');
        self::assertSame([
            'https://cdn.rebrickable.com/media/parts/3001-4.jpg',
            'https://cdn.rebrickable.com/media/sets/6000-1.jpg',
        ], $downloads);
        self::assertSame([], $warmer->candidates(), 'nothing left until the retry time');
    }

    public function testStopsWhileThePagesAreDownloading(): void
    {
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $this->owned->addLot($this->queries->inboxId($id), '3001', 4, 2);
        $cache = new ImageCache($this->pdo, $this->dir, 'test', static fn (): array => self::fail('no download'));
        mkdir($this->dir . '/.locks', 0775, true);
        $held = [];
        for ($i = 0; $i < ImageCache::MAX_PARALLEL; $i++) {
            $held[] = $handle = fopen($this->dir . '/.locks/fetch-' . $i . '.lock', 'c');
            flock($handle, LOCK_EX);
        }

        self::assertSame(0, (new ImageWarmer($this->pdo, $cache, 10, static fn () => null))->run(static fn () => null));
        self::assertSame(0, (new ImageWarmer($this->pdo, $cache, 0))->run(static fn () => null), 'switched off');
        array_map('fclose', $held);
    }
}
