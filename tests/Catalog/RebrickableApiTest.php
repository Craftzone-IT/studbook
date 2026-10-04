<?php

declare(strict_types=1);

namespace Studbook\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Studbook\Catalog\RebrickableApi;

final class RebrickableApiTest extends TestCase
{
    private string $dir;
    /** @var list<array{url: string, headers: array<string, string>}> */
    private array $requests = [];
    /** @var list<float> */
    private array $sleeps = [];
    private int $now = 2_000_000_000;
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/studbook-api-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testReadsAllPartPagesWithTheApiKey(): void
    {
        $api = $this->api([
            self::ok(['next' => 'https://rebrickable.test/api/v3/lego/parts/?page=2', 'results' => [
                ['part_num' => '3001', 'external_ids' => ['BrickLink' => ['3001'], 'LDraw' => ['3001']]],
                ['part_num' => '973c27h01', 'external_ids' => ['BrickLink' => ['973pb0001c01', '973old']]],
                ['part_num' => 'nobl', 'external_ids' => ['LDraw' => ['x']]],
            ]]),
            self::ok(['next' => null, 'results' => [
                ['part_num' => '3040b', 'external_ids' => ['BrickLink' => ['3040']]],
            ]]),
        ]);

        $ids = $api->partIds($this->logger());

        self::assertSame(['3001'], $ids['3001']);
        self::assertSame(['973pb0001c01', '973old'], $ids['973c27h01']);
        self::assertSame(['3040'], $ids['3040b']);
        self::assertArrayNotHasKey('nobl', $ids);
        self::assertStringContainsString('lego/parts/?inc_part_details=1&page_size=1000', $this->requests[0]['url']);
        self::assertSame('key secret-key', $this->requests[0]['headers']['Authorization']);
        self::assertSame([1.1], $this->sleeps, 'requests are spaced out');
    }

    public function testColoursCarryBrickLinkIdsAndNames(): void
    {
        $api = $this->api([self::ok(['next' => null, 'results' => [
            [
                'id' => 0,
                'name' => 'Black',
                'external_ids' => ['BrickLink' => ['ext_ids' => [11], 'ext_descrs' => [['Black']]]],
            ],
            ['id' => -1, 'name' => '[Unknown]', 'external_ids' => []],
        ]])]);

        self::assertSame(['0' => ['ids' => [11], 'names' => ['Black']]], $api->colorIds($this->logger()));
    }

    public function testResultIsCachedForADay(): void
    {
        $page = self::ok(['next' => null, 'results' => [
            ['part_num' => '3001', 'external_ids' => ['BrickLink' => ['3001']]],
        ]]);
        $this->api([$page])->partIds($this->logger());

        $this->now += 23 * 3600;
        self::assertSame(['3001' => ['3001']], $this->api([])->partIds($this->logger()));
        self::assertCount(1, $this->requests);

        $this->now += 2 * 3600;
        $this->api([$page])->partIds($this->logger());
        self::assertCount(2, $this->requests);
    }

    public function testThrottlingIsHonoured(): void
    {
        $api = $this->api([
            ['status' => 429, 'body' => '{"detail":"Request was throttled."}', 'retry_after' => 7],
            self::ok(['next' => null, 'results' => []]),
        ]);

        $api->partIds($this->logger());

        self::assertSame([7.0], $this->sleeps);
        self::assertCount(2, $this->requests);
    }

    public function testRejectedKeyFallsBackGracefully(): void
    {
        $ids = $this->api([['status' => 401, 'body' => '{"detail":"Invalid token."}', 'retry_after' => null]])
            ->partIds($this->logger());

        self::assertSame([], $ids);
        self::assertStringContainsString('API key was rejected', implode("\n", $this->log));
    }

    public function testFailureUsesTheOlderCache(): void
    {
        $this->api([self::ok(['next' => null, 'results' => [
            ['part_num' => '3001', 'external_ids' => ['BrickLink' => ['3001']]],
        ]])])->partIds($this->logger());
        $this->now += 8 * 86400;

        $ids = $this->api([['status' => 500, 'body' => '', 'retry_after' => null]])->partIds($this->logger());

        self::assertSame(['3001' => ['3001']], $ids);
        self::assertStringContainsString('using the older cached result', end($this->log));
    }

    public function testNextPageMustStayOnTheApiHost(): void
    {
        $ids = $this->api([self::ok(['next' => 'https://evil.example/steal', 'results' => [
            ['part_num' => '3001', 'external_ids' => ['BrickLink' => ['3001']]],
        ]])])->partIds($this->logger());

        self::assertSame([], $ids);
        self::assertCount(1, $this->requests);
    }

    /** @param list<array{status: int, body: string, retry_after: ?int}> $responses */
    private function api(array $responses): RebrickableApi
    {
        return new RebrickableApi(
            'secret-key',
            'https://rebrickable.test/api/v3/',
            $this->dir,
            24,
            'test',
            function (string $url, array $headers) use (&$responses): array {
                $this->requests[] = ['url' => $url, 'headers' => $headers];

                return array_shift($responses) ?? throw new \RuntimeException('unexpected request');
            },
            function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
            fn (): int => $this->now
        );
    }

    private function logger(): \Closure
    {
        return function (string $line): void {
            $this->log[] = $line;
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: int, body: string, retry_after: ?int}
     */
    private static function ok(array $data): array
    {
        return ['status' => 200, 'body' => (string) json_encode($data), 'retry_after' => null];
    }
}
