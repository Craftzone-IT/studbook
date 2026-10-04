<?php

declare(strict_types=1);

namespace Studbook\Tests\Build;

use Studbook\App;
use Studbook\Auth\UserRepository;
use Studbook\Config;
use Studbook\Http\Csrf;
use Studbook\Http\Request;
use Studbook\Http\Response;

final class BuildPagesTest extends BuildTestCase
{
    private App $app;
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        (new UserRepository($this->pdo))->create('owner', 'correct horse battery');
        $_SESSION = [];
        $this->storage = sys_get_temp_dir() . '/studbook-bp-' . bin2hex(random_bytes(4));
        $this->app = new App(new Config([
            'APP_URL' => 'https://studbook.test',
            'APP_ENV' => 'production',
            'DB_HOST' => 'x',
            'DB_NAME' => 'x',
            'DB_USER' => 'x',
            'DB_PASSWORD' => '',
            'IMAGE_CACHE_PATH' => sys_get_temp_dir() . '/studbook-img-unused',
            'STORAGE_PATH' => $this->storage,
        ], dirname(__DIR__, 2)), $this->pdo);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->storage . '/cache/*') ?: []);
        @rmdir($this->storage . '/cache');
        @rmdir($this->storage);
        parent::tearDown();
    }

    public function testPagesRequireLogin(): void
    {
        $paths = ['/build', '/build/set/A-1', '/build/set/A-1/wanted.xml', '/builds/1', '/builds/1/wanted.xml'];
        foreach ($paths as $path) {
            self::assertSame('/login', $this->get($path)->header('Location'), $path);
        }
    }

    public function testScanTargetBuildAndWantedList(): void
    {
        $this->login();
        self::assertStringContainsString('Create a collection first', $this->get('/build')->body);

        [$home] = $this->owned->createCollection('Home', false, 'Inbox');
        $this->owned->addLot($this->queries->inboxId($home), '3001', 4, 3);

        $scan = $this->get('/build?opt=1&parts_min=0&sort=missing')->body;
        self::assertStringContainsString('/build/set/C-1?', $scan);
        self::assertStringContainsString('/build/set/A-1?', $scan);
        self::assertStringContainsString('100%', $scan, 'C-1 is complete with a mould variant');
        self::assertStringNotContainsString('Set A', $this->get('/build')->body, 'default minimum of 25 parts');
        self::assertStringNotContainsString('Set A', $this->get('/build?opt=1&parts_min=0&theme=2')->body);

        $target = $this->get('/build/set/A-1?opt=1&home=' . $home)->body;
        self::assertStringContainsString('3 from loose parts, 0 from sets, 4 missing of 7 parts', $target);

        $wanted = $this->get('/build/set/A-1/wanted.xml?opt=1&home=' . $home);
        self::assertSame('application/xml; charset=utf-8', $wanted->header('Content-Type'));
        self::assertStringContainsString('<ITEMID>3001</ITEMID><COLOR>5</COLOR><MINQTY>1</MINQTY>', $wanted->body);

        $started = $this->post('/builds', ['set' => 'A-1', 'opt' => '1', 'home' => (string) $home]);
        $location = (string) $started->header('Location');
        self::assertMatchesRegularExpression('#^/builds/\d+$#', $location);
        $page = $this->get($location)->body;
        self::assertStringContainsString('3 of 7 parts reserved, 4 missing', $page);
        self::assertStringContainsString('Pick list', $page);
        self::assertStringContainsString('Builds in progress', $this->get('/build')->body);
        self::assertStringContainsString('<MINQTY>3</MINQTY>', $this->get($location . '/wanted.xml')->body);

        $this->post($location . '/finish', ['add_set' => '1']);
        self::assertSame([], $this->queries->lots($this->queries->inboxId($home)));
        self::assertStringContainsString('finished', $this->get($location)->body);
        self::assertSame(404, $this->get('/build/set/NOPE-1')->status);
        self::assertSame(404, $this->get('/builds/999999')->status);
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
