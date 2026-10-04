<?php

declare(strict_types=1);

namespace Studbook\Tests\Camera;

use PHPUnit\Framework\TestCase;
use Studbook\Camera\Brickognize;
use Studbook\Camera\PhotoException;

final class BrickognizeTest extends TestCase
{
    private string $dir;
    private string $image;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/studbook-bg-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->image = $this->dir . '/photo.jpg';
        file_put_contents($this->image, 'jpeg bytes ' . random_bytes(8));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testCandidatesAreSortedFilteredAndCached(): void
    {
        $calls = [];
        $answer = json_encode([
            'listing_id' => 'res-1',
            'bounding_box' => ['left' => 0, 'upper' => 0, 'right' => 10, 'lower' => 10],
            'items' => [
                ['id' => '3002', 'name' => 'Brick 2 x 3', 'type' => 'part', 'category' => 'Brick', 'score' => 0.2,
                    'img_url' => 'https://example.test/3002.webp', 'external_sites' => []],
                ['id' => '3001', 'name' => 'Brick 2 x 4', 'type' => 'part', 'category' => 'Brick', 'score' => 0.84,
                    'img_url' => 'https://example.test/3001.webp', 'external_sites' => []],
                ['id' => '10696-1', 'name' => 'Box', 'type' => 'set', 'category' => null, 'score' => 0.5,
                    'img_url' => 'https://example.test/s.webp', 'external_sites' => []],
            ],
        ]);
        $post = function (string $url, string $image) use (&$calls, $answer): string {
            $calls[] = [$url, $image];

            return (string) $answer;
        };
        $client = new Brickognize('https://api.example.test/', 5, $this->dir, 'test', $post);

        $candidates = $client->identify($this->image);
        self::assertSame(['3001', '3002'], array_column($candidates, 'id'), 'best first, parts only');
        self::assertSame(0.84, $candidates[0]['score']);
        self::assertSame([['https://api.example.test/predict/parts/', $this->image]], $calls);

        $client->identify($this->image);
        self::assertCount(1, $calls, 'the same photo is answered from the cache');
    }

    public function testFailuresBecomeOneError(): void
    {
        $answers = [
            static fn (): string => throw new \RuntimeException('timeout'),
            static fn (): string => '<html>Bad gateway</html>',
        ];
        foreach ($answers as $answer) {
            $cache = $this->dir . '/c' . random_int(0, 99999);
            $client = new Brickognize('https://api.example.test', 5, $cache, 'test', $answer);
            try {
                $client->identify($this->image);
                self::fail('an error was expected');
            } catch (PhotoException $e) {
                self::assertSame('recognition_failed', $e->getMessage());
            }
        }
    }
}
