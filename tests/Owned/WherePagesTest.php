<?php

declare(strict_types=1);

namespace Studbook\Tests\Owned;

use Studbook\App;
use Studbook\Auth\UserRepository;
use Studbook\Config;
use Studbook\Http\Csrf;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Owned\PlacementAdvisor;
use Studbook\SettingRepository;

final class WherePagesTest extends PlacementTestCase
{
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
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

    public function testLoginRequired(): void
    {
        self::assertSame('/login', $this->get('/where?part=3001')->header('Location'));
    }

    public function testBestBoxWithReasonAndAlternatives(): void
    {
        $this->login();

        $page = $this->get('/where?part=26603');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('01 Tiles', $page->body);
        self::assertStringContainsString('Holds similar parts: 63864 Tile 1 x 3', $page->body);
        self::assertStringContainsString('href="/b/' . $this->boxes['tiles'] . '/add?part=26603"', $page->body);
        self::assertStringContainsString('Tiles', $page->body, 'the category is named');
        self::assertMatchesRegularExpression('/id="where-part"[^>]*value="26603"/', $page->body, 'kept for the next');
        self::assertStringNotContainsString('id="where-collection"', $page->body, 'only one collection');

        $plates = $this->get('/where?part=3033')->body;
        self::assertStringContainsString('Also possible', $plates);
        self::assertStringContainsString('href="/b/' . $this->boxes['long_plates'] . '/add?part=3033"', $plates);
    }

    public function testBrickLinkNumbersAreAccepted(): void
    {
        $this->login();

        $page = $this->get('/where?part=15573')->body;

        self::assertStringContainsString('02 Small plates', $page);
        self::assertStringContainsString('Labelled for 3794a Plate Special', $page);
        self::assertStringContainsString('/add?part=3794b', $page);
    }

    public function testUnknownPartAndNoBox(): void
    {
        $this->login();

        $unknown = $this->get('/where?part=nope-1');
        self::assertSame(200, $unknown->status);
        self::assertStringContainsString('Part nope-1 was not found in the catalogue.', $unknown->body);
        self::assertMatchesRegularExpression('/id="where-part"[^>]*value="nope-1"/', $unknown->body);

        $none = $this->get('/where?part=3626c')->body;
        self::assertStringContainsString('No box holds Minifig Heads parts yet.', $none);
        self::assertStringContainsString('href="/b/' . $this->inbox . '/add?part=3626c"', $none);
        self::assertStringContainsString('Add to Inbox', $none);
    }

    public function testCollectionSelectAndRememberedCollection(): void
    {
        $this->login();
        [$other] = $this->owned->createCollection('Other', false, 'Inbox');
        [$otherBox] = $this->owned->createBox($other, 'Other tiles', 'large');
        $this->owned->setLabels($otherBox, ['26603']);

        $first = $this->get('/where?part=26603')->body;
        self::assertStringContainsString('id="where-collection"', $first);
        self::assertStringContainsString('01 Tiles', $first, 'first active collection by name: Mine');

        $chosen = $this->get('/where?part=26603&c=' . $other)->body;
        self::assertStringContainsString('Other tiles', $chosen);
        self::assertStringNotContainsString('01 Tiles', $chosen);

        self::assertStringContainsString('Other tiles', $this->get('/where?part=26603')->body, 'remembered');
        self::assertStringContainsString('Other tiles', $this->get('/where?part=26603&c=999')->body, 'unknown c');
        self::assertStringContainsString('01 Tiles', $this->get('/where?part=26603&c=' . $this->collection)->body);
    }

    public function testEntryPoints(): void
    {
        $this->login();

        $home = $this->get('/')->body;
        self::assertStringContainsString('action="/where"', $home);
        self::assertStringContainsString('href="/where"', $home, 'nav item');

        $search = $this->get('/search?q=26603')->body;
        self::assertStringContainsString('href="/where?part=26603"', $search);
    }

    public function testAddFormAndFastEntryHintsUseTheAdvisor(): void
    {
        $this->login();

        $add = $this->get('/b/' . $this->inbox . '/add?part=26603')->body;
        self::assertStringContainsString('This part belongs in another box:', $add);
        self::assertStringContainsString('href="/b/' . $this->boxes['tiles'] . '/add?part=26603"', $add);
        self::assertStringContainsString('Holds similar parts: 63864 Tile 1 x 3', $add);

        self::assertStringNotContainsString(
            'belongs in another box',
            $this->get('/b/' . $this->boxes['tiles'] . '/add?part=26603')->body,
            'nothing when the current box is advised'
        );

        $json = json_decode($this->get('/b/' . $this->inbox . '/entry/part?part=3033')->body, true);
        self::assertSame([[
            'id' => $this->boxes['large_plates'],
            'name' => '03 Large plates',
            'reason' => 'Holds similar parts: 3030 Plate 4 x 10',
        ]], $json['hint']);
        $json = json_decode($this->get('/b/' . $this->boxes['long_plates'] . '/entry/part?part=3033')->body, true);
        self::assertSame([], $json['hint'], 'an alternative box is fine as well');
    }

    public function testPrintBoxSetting(): void
    {
        $this->login();
        $key = PlacementAdvisor::printBoxKey($this->collection);
        $update = fn (string $box, array $extra = []): Response => $this->post(
            '/b/' . $this->boxes[$box] . '/update',
            ['name' => 'Box ' . $box, 'type' => 'large'] + $extra
        );

        self::assertStringContainsString('name="print_box"', $this->get('/b/' . $this->boxes['misc'])->body);
        self::assertStringNotContainsString('name="print_box"', $this->get('/b/' . $this->inbox)->body);

        $update('misc', ['print_box' => '1']);
        self::assertSame((string) $this->boxes['misc'], $this->setting($key));
        $boxPage = $this->get('/b/' . $this->boxes['misc'])->body;
        self::assertMatchesRegularExpression('/name="print_box" value="1" checked/', $boxPage);
        self::assertStringContainsString('Printed parts go here', $this->get('/where?part=3001pr0001')->body);

        $update('slopes', ['print_box' => '1']);
        self::assertSame((string) $this->boxes['slopes'], $this->setting($key), 'at most one box per collection');
        $update('misc');
        self::assertSame((string) $this->boxes['slopes'], $this->setting($key), 'unticking another box keeps it');
        $update('slopes');
        self::assertNull($this->setting($key));

        $update('misc', ['print_box' => '1']);
        $this->post('/b/' . $this->boxes['misc'] . '/delete');
        self::assertNull($this->setting($key), 'deleting the box clears it');

        [$other] = $this->owned->createCollection('Other', false, 'Inbox');
        $update('slopes', ['print_box' => '1']);
        $this->post('/b/' . $this->boxes['slopes'] . '/move', ['collection' => (string) $other]);
        self::assertNull($this->setting($key), 'moving the box clears it');
    }

    private function setting(string $key): ?string
    {
        return (new SettingRepository($this->pdo))->get($key);
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
