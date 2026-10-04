<?php

declare(strict_types=1);

namespace Studbook\Tests\Build;

use Studbook\Build\BuildOptions;
use Studbook\Build\BuildService;
use Studbook\Build\CoverageService;
use Studbook\Build\WantedList;
use Studbook\Catalog\CatalogRepository;
use Studbook\Owned\SetService;

final class BuildServiceTest extends BuildTestCase
{
    private BuildService $builds;
    private int $home;
    private int $inbox;
    private int $lendable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builds = new BuildService($this->pdo, $this->batches, $this->queries);
        [$this->home] = $this->owned->createCollection('Home', false, 'Inbox');
        $this->inbox = $this->queries->inboxId($this->home);
        $this->owned->addLot($this->inbox, '3001', 4, 3);
        $this->owned->addLot($this->inbox, '3024', 71, 1);
        $sets = new SetService($this->batches, $this->queries, new CatalogRepository($this->pdo));
        $this->lendable = $sets->addSet($this->home, 'D-1', 'built', 'lendable', null)['ids'][0];
    }

    public function testStartReservesLooseFirstThenSets(): void
    {
        $started = $this->builds->start('A-1', new BuildOptions($this->home));
        self::assertSame(6, $started['reserved']);
        $progress = $this->byPart($this->builds->progress($started['id']));
        self::assertSame(['need' => 4, 'reserved' => 3, 'missing' => 1], $progress['3001']);
        self::assertSame(['need' => 3, 'reserved' => 3, 'missing' => 0], $progress['3024']);

        $pick = $this->builds->pickList($started['id']);
        self::assertSame(['box', 'set'], array_column($pick, 'kind'));
        self::assertSame('Inbox', $pick[0]['name']);
        self::assertSame(2, (int) $pick[1]['items'][0]['qty'], 'two grey plates borrowed from D-1');

        $cache = sys_get_temp_dir() . '/studbook-b-' . bin2hex(random_bytes(4));
        $scan = (new CoverageService($this->pdo, $cache))->scan(new BuildOptions($this->home));
        self::assertSame(3, $scan['s:A-1']['total'], 'reserved parts do not count for others; D-1 has 3 left');
        array_map('unlink', glob($cache . '/*') ?: []);
        @rmdir($cache);

        $second = $this->builds->start('A-1', new BuildOptions($this->home));
        self::assertSame(3, $second['reserved'], 'only what the first build left');
    }

    public function testReserveAgainFinishAndUndo(): void
    {
        $build = $this->builds->start('A-1', new BuildOptions($this->home))['id'];
        $this->owned->addLot($this->inbox, '3001', 4, 2);
        self::assertSame(1, $this->builds->reserve($build)['reserved']);
        self::assertSame(0, array_sum(array_column($this->builds->progress($build), 'missing')));

        $batch = $this->builds->finish($build, true);
        self::assertSame('done', $this->builds->find($build)['state']);
        self::assertSame(0, $this->rowCount('allocation'));
        $lots = $this->queries->lots($this->inbox);
        self::assertSame([['3001', 1]], array_map(static fn (array $l): array => [$l['part'], (int) $l['qty']], $lots));
        self::assertSame(-2, (int) $this->queries->deltas($this->lendable)[0]['qty'], 'borrowed parts missing in D-1');
        $sets = $this->queries->sets($this->home);
        self::assertSame(['A-1', 'D-1'], array_column($sets, 'set_num'), 'the model became a built set');

        $this->batches->revert($batch);
        self::assertSame('active', $this->builds->find($build)['state']);
        self::assertSame(4, $this->rowCount('allocation'));
        self::assertSame([], $this->queries->deltas($this->lendable));

        $this->expectException(\DomainException::class);
        $this->builds->finish($build, false);
        $this->builds->finish($build, false);
    }

    public function testReleaseAndMissingSources(): void
    {
        $build = $this->builds->start('C-1', new BuildOptions($this->home))['id'];
        self::assertSame(3, (int) $this->pdo->query('SELECT SUM(qty) FROM allocation')->fetchColumn());

        // The reserved lot is taken out in the meantime: finishing takes what is left.
        $lot = (int) $this->queries->lots($this->inbox)[0]['id'];
        $this->owned->takeOut($lot, 2);
        $pick = $this->builds->pickList($build);
        self::assertSame(1, (int) $pick[0]['items'][0]['lot_qty']);

        $batch = $this->builds->release($build);
        self::assertNull($this->builds->find($build));
        self::assertSame(0, $this->rowCount('allocation'));
        $this->batches->revert($batch);
        self::assertNotNull($this->builds->find($build));
    }

    public function testWantedListXml(): void
    {
        $xml = (new WantedList($this->pdo))->xml([
            ['part' => '3001', 'color_id' => 4, 'qty' => 2],
            ['part' => '3001a', 'color_id' => 4, 'qty' => 1],
            ['part' => '3024', 'color_id' => 71, 'qty' => 0],
        ]);
        self::assertStringContainsString(
            '<ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>3001</ITEMID><COLOR>5</COLOR><MINQTY>2</MINQTY></ITEM>',
            $xml,
            'BrickLink part and colour ids'
        );
        self::assertStringContainsString('<!-- No BrickLink id (Rebrickable part / colour): 1 x 3001a / 4 -->', $xml);
        self::assertStringNotContainsString('3024', $xml, 'nothing missing, nothing listed');
        self::assertNotFalse(simplexml_load_string($xml));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array{need: int, reserved: int, missing: int}>
     */
    private function byPart(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['g']] = [
                'need' => $row['need'],
                'reserved' => $row['reserved'],
                'missing' => $row['missing'],
            ];
        }

        return $result;
    }
}
