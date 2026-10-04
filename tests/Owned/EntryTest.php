<?php

declare(strict_types=1);

namespace Studbook\Tests\Owned;

use Studbook\App;
use Studbook\Auth\UserRepository;
use Studbook\Config;
use Studbook\Http\Csrf;
use Studbook\Http\Request;
use Studbook\Http\Response;

/** Fast entry (M3): entry sessions as one batch, the JSON endpoints, search, picker and history pages. */
final class EntryTest extends OwnedTestCase
{
    private App $app;
    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec(
            "INSERT INTO cat_part_category (id, name) VALUES (11, 'Bricks'), (14, 'Plates'), (9, 'Plates Special')"
        );
        $this->pdo->exec("UPDATE cat_part SET width = 2, length = 4, popularity = 10 WHERE rb_num = '3001'");
        $this->pdo->exec("UPDATE cat_part SET width = 1, length = 1, popularity = 20 WHERE rb_num = '3024'");
        (new UserRepository($this->pdo))->create('owner', 'correct horse battery');
        $_SESSION = [];
        $this->cache = sys_get_temp_dir() . '/studbook-entry-' . bin2hex(random_bytes(4));
        $this->app = new App(new Config([
            'APP_URL' => 'https://studbook.test',
            'APP_ENV' => 'production',
            'DB_HOST' => 'x',
            'DB_NAME' => 'x',
            'DB_USER' => 'x',
            'DB_PASSWORD' => '',
            'IMAGE_CACHE_PATH' => sys_get_temp_dir() . '/studbook-img-unused',
            'STORAGE_PATH' => $this->cache,
        ], dirname(__DIR__, 2)), $this->pdo);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cache . '/cache/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->cache . '/cache');
        @rmdir($this->cache);
        parent::tearDown();
    }

    public function testAnEntrySessionIsOneBatchPerBox(): void
    {
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $inbox = $this->queries->inboxId($id);
        [$box] = $this->owned->createBox($id, 'Bricks', 'large');

        $first = $this->owned->addLotInSession($box, '3001', 4, 5, null);
        $second = $this->owned->addLotInSession($box, '3001', 0, 3, $first['batch']);
        self::assertSame($first['batch'], $second['batch']);
        self::assertSame(['batch' => $first['batch'], 'lots' => 2, 'parts' => 8], $second);
        self::assertSame($second, $this->owned->entrySession($first['batch'], $box));

        $other = $this->owned->addLotInSession($inbox, '3024', 71, 1, $first['batch']);
        self::assertNotSame($first['batch'], $other['batch'], 'another box starts its own batch');
        self::assertNull($this->owned->entrySession($first['batch'], $inbox));

        $this->batches->revert($first['batch']);
        self::assertSame([], $this->queries->lots($box), 'undo removes the whole session');
        self::assertNull($this->owned->entrySession($first['batch'], $box));
        $again = $this->owned->addLotInSession($box, '3001', 4, 1, $first['batch']);
        self::assertNotSame($first['batch'], $again['batch'], 'a reverted session is not continued');
    }

    public function testAppendRefusesRevertedBatches(): void
    {
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $inbox = $this->queries->inboxId($id);
        $session = $this->owned->addLotInSession($inbox, '3001', 4, 1, null);
        $this->batches->revert($session['batch']);

        $ran = false;
        self::assertFalse($this->batches->append($session['batch'], [], static function () use (&$ran): void {
            $ran = true;
        }));
        self::assertFalse($ran);
        self::assertFalse($this->batches->append(999999, [], static fn () => null));
    }

    public function testEntryPagesRequireLogin(): void
    {
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $box = $this->queries->inboxId($id);

        $b = '/b/' . $box;
        $paths = [
            $b . '/entry', $b . '/entry/search?q=3001', $b . '/entry/part?part=3001', $b . '/pick',
            '/search', '/history',
        ];
        foreach ($paths as $path) {
            self::assertSame('/login', $this->get($path)->header('Location'), $path);
        }
    }

    public function testKeyboardFlowEndpoints(): void
    {
        $this->login();
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $inbox = $this->queries->inboxId($id);
        [$box] = $this->owned->createBox($id, 'Bricks', 'large');
        $this->owned->setLabels($box, ['3001']);

        $page = $this->get('/b/' . $inbox . '/entry');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('data-search-url', $page->body);

        $search = self::json($this->get('/b/' . $inbox . '/entry/search?q=piros+kocka+2x4'));
        self::assertSame('3001', $search['parts'][0]['rb_num']);
        self::assertSame(4, $search['color']['id']);

        $part = self::json($this->get('/b/' . $inbox . '/entry/part?part=3001'));
        self::assertSame([0, 4], array_column($part['colors'], 'id'), 'only colours the part exists in');
        self::assertSame([['id' => $box, 'name' => 'Bricks']], $part['hint'], 'where does this go?');
        self::assertSame('red', $part['colorSynonyms']['piros']);
        self::assertSame([], self::json($this->get('/b/' . $box . '/entry/part?part=3001'))['hint']);
        self::assertSame(404, $this->get('/b/' . $inbox . '/entry/part?part=nope')->status);

        $added = $this->post('/b/' . $box . '/entry', ['part' => '3001', 'color' => '4', 'qty' => '5']);
        $first = self::json($added);
        self::assertStringContainsString('5 × 3001 Red', $first['message']);
        self::assertStringContainsString('3001', $first['contents']);
        $second = self::json($this->post('/b/' . $box . '/entry', ['part' => '3001', 'color' => '0', 'qty' => '2']));
        self::assertSame($first['session']['batch'], $second['session']['batch']);
        self::assertSame(1, $this->rowCount('batch') - 3, 'collection, box, labels and one entry session');

        self::assertSame(422, $this->post('/b/' . $box . '/entry', ['part' => '3001', 'color' => '71'])->status);
        $this->post('/b/' . $box . '/entry', ['part' => '3001', 'color' => '4', 'qty' => '1', Csrf::FIELD => 'bad']);
        self::assertSame(7, array_sum(array_map('intval', array_column($this->queries->lots($box), 'qty'))));

        $reload = $this->get('/b/' . $box . '/entry')->body;
        self::assertStringContainsString('/batches/' . $first['session']['batch'] . '/undo', $reload);
        $this->batches->revert($first['session']['batch']);
        self::assertStringNotContainsString(
            '/batches/' . $first['session']['batch'] . '/undo',
            $this->get('/b/' . $box . '/entry')->body,
            'an undone session is not offered again'
        );
    }

    public function testSearchPickerAndHistoryPages(): void
    {
        $this->login();
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $inbox = $this->queries->inboxId($id);
        $this->owned->addLot($inbox, '3001', 4, 6);

        $search = $this->get('/search?q=3001')->body;
        self::assertStringContainsString('Brick 2 x 4', $search);
        self::assertStringContainsString('href="/b/' . $inbox . '"', $search, 'the result says where the part is kept');

        $categories = $this->get('/b/' . $inbox . '/pick')->body;
        self::assertStringContainsString('Bricks', $categories);
        $sizes = $this->get('/b/' . $inbox . '/pick?cat=11')->body;
        self::assertStringContainsString('size=2x4', $sizes);
        $parts = $this->get('/b/' . $inbox . '/pick?cat=11&size=4x2')->body;
        self::assertStringContainsString('/b/' . $inbox . '/add?part=3001', $parts, 'either orientation');
        self::assertStringNotContainsString('3001pr0001', $parts);
        self::assertSame(404, $this->get('/b/' . $inbox . '/pick?cat=999')->status);

        $history = $this->get('/history')->body;
        self::assertStringContainsString('Mine', $history);
        self::assertStringContainsString('3001', $history);
    }

    private function login(): void
    {
        $this->post('/login', ['username' => 'owner', 'password' => 'correct horse battery']);
    }

    /** @return array<string, mixed> */
    private static function json(Response $response): array
    {
        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true);
        self::assertIsArray($data);

        return $data;
    }

    private function get(string $path): Response
    {
        $parts = parse_url($path);
        parse_str($parts['query'] ?? '', $query);

        return $this->app->handle(
            new Request('GET', $parts['path'], query: $query, server: ['REMOTE_ADDR' => '192.0.2.50'])
        );
    }

    /** @param array<string, string> $data */
    private function post(string $path, array $data = []): Response
    {
        return $this->app->handle(new Request(
            'POST',
            $path,
            post: $data + [Csrf::FIELD => Csrf::token()],
            server: ['REMOTE_ADDR' => '192.0.2.50']
        ));
    }
}
