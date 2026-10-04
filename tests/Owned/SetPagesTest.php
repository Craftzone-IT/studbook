<?php

declare(strict_types=1);

namespace Studbook\Tests\Owned;

use Studbook\App;
use Studbook\Auth\UserRepository;
use Studbook\Config;
use Studbook\Http\Csrf;
use Studbook\Http\Request;
use Studbook\Http\Response;

/** M4 set pages: add by number, set page, deltas, break up, move and remove. */
final class SetPagesTest extends OwnedTestCase
{
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO cat_set (set_num, name, year, theme_id, num_parts) VALUES
            ('6000-1', 'Small Set', 1990, NULL, 9), ('6001-1', 'Small Set Two', 1991, NULL, 4)");
        $this->pdo->exec("INSERT INTO cat_inventory (set_num, part, color_id, is_spare, from_minifig, quantity) VALUES
            ('6000-1', '3001', 4, 0, 0, 5), ('6000-1', '3024', 71, 0, 0, 3), ('6000-1', '3024', 71, 1, 0, 1)");
        (new UserRepository($this->pdo))->create('owner', 'correct horse battery');
        $_SESSION = [];
        $this->app = new App(new Config([
            'APP_URL' => 'https://studbook.test',
            'APP_ENV' => 'production',
            'DB_HOST' => 'x',
            'DB_NAME' => 'x',
            'DB_USER' => 'x',
            'DB_PASSWORD' => '',
            'IMAGE_CACHE_PATH' => sys_get_temp_dir() . '/studbook-img-unused',
        ], dirname(__DIR__, 2)), $this->pdo);
    }

    public function testSetPagesRequireLogin(): void
    {
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $paths = ['/c/' . $id . '/sets/new?q=6000', '/s/1', '/s/1/delta?part=3001', '/s/1/break-up', '/img?set=6000-1'];
        foreach ($paths as $path) {
            self::assertSame('/login', $this->get($path)->header('Location'), $path);
        }
    }

    public function testAddASetByNumberAndWorkWithIt(): void
    {
        $this->login();
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $inbox = $this->queries->inboxId($id);

        $search = $this->get('/c/' . $id . '/sets/new?q=small+set')->body;
        self::assertStringContainsString('?set=6000-1', $search, 'several matches are listed');
        $preview = $this->get('/c/' . $id . '/sets/new?q=6000')->body;
        self::assertStringContainsString('Small Set', $preview);
        self::assertStringContainsString('name="set" value="6000-1"', $preview, '6000 resolves to 6000-1');
        self::assertStringContainsString('No set found', $this->get('/c/' . $id . '/sets/new?q=nothing')->body);

        $created = $this->post('/c/' . $id . '/sets', [
            'set' => '6000-1',
            'state' => 'built',
            'lock_mode' => 'lendable',
            'storage' => '',
            'copies' => '1',
        ]);
        $location = (string) $created->header('Location');
        self::assertMatchesRegularExpression('#^/s/\d+$#', $location);
        $setId = (int) substr($location, 3);

        $page = $this->get($location)->body;
        self::assertStringContainsString('Small Set', $page);
        self::assertStringContainsString('Undo', $page, 'adding offers an undo');
        self::assertStringContainsString('Contents (2 lots)', $page);
        self::assertStringContainsString('Small Set', $this->get('/c/' . $id)->body, 'listed on the collection page');

        $delta = $this->get('/s/' . $setId . '/delta?kind=missing&part=3001&color=4')->body;
        self::assertStringContainsString('in set: 5', $delta);
        $this->post('/s/' . $setId . '/delta', ['part' => '3001', 'color' => '4', 'kind' => 'missing', 'qty' => '2']);
        $this->post('/s/' . $setId . '/delta', ['part' => '3024', 'color' => '71', 'kind' => 'extra', 'qty' => '1']);
        $page = $this->get('/s/' . $setId)->body;
        self::assertStringContainsString('2 missing', $page);
        self::assertStringContainsString('1 extra', $page);
        $this->post('/s/' . $setId . '/delta', ['part' => '3001', 'color' => '4', 'kind' => 'missing', 'qty' => '9']);
        self::assertStringContainsString('cannot miss more', $this->get('/s/' . $setId)->body);
        self::assertStringContainsString(
            'not in the set',
            $this->get('/s/' . $setId . '/delta?kind=missing&part=3794b')->body
        );

        $this->post('/s/' . $setId . '/update', [
            'state' => 'sealed',
            'lock_mode' => 'locked',
            'storage' => (string) $inbox,
        ]);
        self::assertSame('sealed', $this->queries->ownedSet($setId)['state']);
        self::assertStringContainsString('Small Set', $this->get('/b/' . $inbox)->body, 'shown in its box');

        $form = $this->get('/s/' . $setId . '/break-up')->body;
        self::assertStringContainsString('1 spare parts', $form);
        $done = $this->post('/s/' . $setId . '/break-up', ['storage' => (string) $inbox, 'spares' => '1']);
        self::assertSame('/c/' . $id, $done->header('Location'));
        self::assertNull($this->queries->ownedSet($setId));
        self::assertSame(
            ['3001' => 3, '3024' => 5],
            array_combine(
                array_map('strval', array_column($this->queries->lots($inbox), 'part')),
                array_map('intval', array_column($this->queries->lots($inbox), 'qty'))
            )
        );
    }

    public function testMoveAndRemove(): void
    {
        $this->login();
        [$a] = $this->owned->createCollection('A', false, 'Inbox');
        [$b] = $this->owned->createCollection('B', false, 'Inbox');
        [$box] = $this->owned->createBox($a, 'Shelf', 'set_box');
        $this->post('/c/' . $a . '/sets', [
            'set' => '6001',
            'state' => 'sealed',
            'lock_mode' => 'locked',
            'storage' => (string) $box,
            'copies' => '2',
        ]);
        $sets = $this->queries->sets($a);
        self::assertCount(2, $sets);

        $setId = (int) $sets[0]['id'];
        $this->post('/s/' . $setId . '/move', ['collection' => (string) $b]);
        self::assertSame($b, (int) $this->queries->ownedSet($setId)['collection_id']);

        $this->post('/b/' . $box . '/move', ['collection' => (string) $b]);
        self::assertSame($b, (int) $this->queries->box($box)['collection_id']);
        self::assertCount(2, $this->queries->sets($b));

        $removed = $this->post('/s/' . $setId . '/delete');
        self::assertSame('/c/' . $b, $removed->header('Location'));
        self::assertNull($this->queries->ownedSet($setId));

        self::assertSame(404, $this->get('/s/999999')->status);
        self::assertSame(404, $this->post('/deltas/999999/delete')->status);
    }

    private function login(): void
    {
        $this->post('/login', ['username' => 'owner', 'password' => 'correct horse battery']);
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
