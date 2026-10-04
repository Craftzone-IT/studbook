<?php

declare(strict_types=1);

namespace Studbook\Tests\Build;

use Studbook\Build\BuildOptions;
use Studbook\Build\CoverageService;
use Studbook\Build\PartEquivalence;
use Studbook\Owned\SetService;
use Studbook\Catalog\CatalogRepository;

final class CoverageServiceTest extends BuildTestCase
{
    private CoverageService $coverage;
    private string $cache;
    private int $home;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = sys_get_temp_dir() . '/studbook-cov-' . bin2hex(random_bytes(4));
        $this->coverage = new CoverageService($this->pdo, $this->cache);
        [$this->home] = $this->owned->createCollection('Home', false, 'Inbox');
        [$this->other] = $this->owned->createCollection('Other', true, 'Inbox');
        $inbox = $this->queries->inboxId($this->home);
        $this->owned->addLot($inbox, '3001', 4, 3);
        $this->owned->addLot($inbox, '3024', 71, 1);
        $this->owned->addLot($this->queries->inboxId($this->other), '3001', 4, 5);
        $sets = new SetService($this->batches, $this->queries, new CatalogRepository($this->pdo));
        $sets->addSet($this->home, 'D-1', 'built', 'lendable', null);
        $sets->addSet($this->home, 'D-1', 'built', 'locked', null);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cache . '/*') ?: []);
        @rmdir($this->cache);
        parent::tearDown();
    }

    public function testEquivalenceGroups(): void
    {
        (new PartEquivalence($this->pdo))->ensure();
        $canon = $this->pdo->query('SELECT part, variant, print FROM cat_part_canon ORDER BY part')
            ->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([
            ['part' => '3001', 'variant' => '3001', 'print' => '3001'],
            ['part' => '3001a', 'variant' => '3001', 'print' => '3001'],
            ['part' => '3068b', 'variant' => '3068b', 'print' => '3068b'],
            ['part' => '3068bpr0001', 'variant' => '3068bpr0001', 'print' => '3068b'],
        ], $canon, 'the most popular part represents the group; prints only join in print mode');
    }

    public function testLooseFirstThenUnlockedSets(): void
    {
        $scan = $this->coverage->scan(new BuildOptions($this->home));
        self::assertSame(
            ['need' => 7, 'loose' => 4, 'total' => 6, 'any' => null],
            $scan['s:A-1'],
            '3001: 3 of 4 loose; 3024: 1 loose + 2 from the unlocked D-1 (the locked copy does not count)'
        );
        self::assertArrayNotHasKey('s:B-1', $scan, 'sets without any available part are not listed');

        $noFigs = $this->coverage->scan(new BuildOptions($this->home, [], 'variant', false));
        self::assertSame(['need' => 6, 'loose' => 4, 'total' => 5], array_slice($noFigs['s:A-1'], 0, 3));

        $withOther = $this->coverage->scan(new BuildOptions($this->home, [$this->other]));
        self::assertSame(7, $withOther['s:A-1']['total'], 'a lending collection adds its loose parts');
    }

    public function testEquivalenceModesAndAnyColour(): void
    {
        $exact = $this->coverage->scan(new BuildOptions($this->home, [], 'exact'));
        self::assertSame(1, $exact['s:C-1']['total'], 'only the 3001 counts exactly');
        $variant = $this->coverage->scan(new BuildOptions($this->home));
        self::assertSame(3, $variant['s:C-1']['total'], '3001a and 3001 share the 3 loose bricks');

        $this->owned->addLot($this->queries->inboxId($this->home), '3068b', 0, 1);
        self::assertArrayNotHasKey('s:B-1', $this->coverage->scan(new BuildOptions($this->home)));
        $print = $this->coverage->scan(new BuildOptions($this->home, [], 'print'));
        self::assertSame(1, $print['s:B-1']['total'], 'a plain tile stands in for the printed one');

        $this->owned->addLot($this->queries->inboxId($this->home), '3001', 0, 2);
        $any = $this->coverage->scan(new BuildOptions($this->home, [], 'variant', true, true));
        self::assertSame(6, $any['s:A-1']['total']);
        self::assertSame(7, $any['s:A-1']['any'], 'black bricks fill in for the missing red one');
    }

    public function testTargetMatchesTheScanAndTheCacheFollowsChanges(): void
    {
        $options = new BuildOptions($this->home);
        $rows = $this->coverage->target('A-1', $options);
        self::assertSame(
            [['3001', 4, 4, 3, 0, 1], ['3024', 71, 3, 1, 2, 0]],
            array_map(
                static fn (array $r): array
                    => [$r['g'], $r['color_id'], $r['need'], $r['loose'], $r['sets'], $r['missing']],
                $rows
            )
        );
        $c = $this->coverage->target('C-1', $options);
        self::assertSame(['3001', '3001a'], $c[0]['parts'], 'variants of one group are one row');

        self::assertSame(6, $this->coverage->scan($options)['s:A-1']['total']);
        self::assertNotSame([], glob($this->cache . '/coverage-*.json'));
        $batch = $this->owned->addLot($this->queries->inboxId($this->home), '3001', 4, 1);
        self::assertSame(7, $this->coverage->scan($options)['s:A-1']['total'], 'a new batch invalidates the cache');
        $this->batches->revert($batch);
        self::assertSame(6, $this->coverage->scan($options)['s:A-1']['total'], 'so does an undo');
    }

    public function testOptionsFromInput(): void
    {
        $collections = $this->queries->activeCollections();
        $defaults = BuildOptions::from([], $collections);
        self::assertSame([$this->other], $defaults->others, 'lending collections are selected by default');
        self::assertSame('variant', $defaults->mode);

        $options = BuildOptions::from(
            [
                'home' => (string) $this->other,
                'opt' => '1',
                'mode' => 'nonsense',
                'figs' => '0',
                'others' => [(string) $this->home],
            ],
            $collections
        );
        self::assertSame($this->other, $options->home);
        self::assertSame([], $options->others, 'a collection that does not lend cannot be added');
        self::assertFalse($options->minifigs);
        self::assertSame('variant', $options->mode);
        self::assertSame($options->toArray(), BuildOptions::from($options->toArray(), $collections)->toArray());
    }
}
