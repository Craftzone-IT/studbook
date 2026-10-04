<?php

declare(strict_types=1);

namespace Studbook\Tests\Owned;

use Studbook\App;
use Studbook\Catalog\ImageCache;
use Studbook\Controller\ImageController;
use Studbook\Auth\UserRepository;
use Studbook\Config;
use Studbook\Http\Csrf;
use Studbook\Http\Request;
use Studbook\Http\Response;

final class OwnedPagesTest extends OwnedTestCase
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

    public function testBoxPagesRequireLogin(): void
    {
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $box = $this->queries->inboxId($id);

        $paths = ['/b/' . $box, '/c/' . $id, '/c/' . $id . '/labels', '/b/' . $box . '/qr.svg', '/img?part=3001'];
        foreach ($paths as $path) {
            self::assertSame('/login', $this->get($path)->header('Location'), $path);
        }
    }

    public function testCreateCollectionAndBoxThroughThePages(): void
    {
        $this->login();

        $created = $this->post('/collections', ['name' => 'My bricks', 'can_lend' => '1']);
        $collectionId = (int) substr((string) $created->header('Location'), 3);
        self::assertSame(1, (int) $this->queries->collection($collectionId)['can_lend']);

        $collection = $this->get('/c/' . $collectionId)->body;
        self::assertStringContainsString('Inbox', $collection);
        self::assertStringContainsString('Undo', $collection, 'creating offers an undo');

        $home = $this->get('/')->body;
        self::assertStringContainsString('My bricks', $home);
        self::assertStringContainsString('Loose parts', $home);

        $box = $this->post('/c/' . $collectionId . '/boxes', ['name' => 'Bricks', 'type' => 'large']);
        self::assertMatchesRegularExpression('#^/b/\d+$#', (string) $box->header('Location'));
    }

    public function testLabelsAreValidatedAgainstTheCatalogue(): void
    {
        $this->login();
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        [$box] = $this->owned->createBox($id, 'Bricks', 'large');

        $this->post('/b/' . $box . '/labels', ['labels' => "3001, 15573\nnope-1"]);
        self::assertSame([], $this->queries->labels($box), 'nothing is saved while a number is unknown');
        $page = $this->get('/b/' . $box)->body;
        self::assertStringContainsString('nope-1', $page);
        self::assertStringContainsString('3001, 15573', $page, 'the typed text is kept');

        $this->post('/b/' . $box . '/labels', ['labels' => '3001 15573']);
        self::assertSame(['3001', '3794b'], $this->queries->labels($box), 'BL numbers are stored as RB ids');
        $page = $this->get('/b/' . $box)->body;
        self::assertStringContainsString('<strong>15573</strong>', $page, 'tiles show the BrickLink number');
    }

    public function testAddTakeOutAndMoveFromTheBoxPage(): void
    {
        $this->login();
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $inbox = $this->queries->inboxId($id);
        [$box] = $this->owned->createBox($id, 'Bricks', 'large');

        $form = $this->get('/b/' . $box . '/add?part=3001')->body;
        self::assertStringContainsString('Red', $form);
        self::assertStringContainsString('Black', $form);
        self::assertStringNotContainsString('Light Bluish Gray', $form, 'only colours the part exists in');

        $this->post('/b/' . $box . '/lots', ['part' => '3001', 'color' => '71', 'qty' => '2']);
        self::assertSame([], $this->queries->lots($box), 'a colour the part does not exist in is refused');

        $this->post('/b/' . $box . '/lots', ['part' => '3001', 'color' => '4', 'qty' => '10']);
        $lot = $this->queries->lots($box)[0];
        self::assertSame(10, (int) $lot['qty']);
        $page = $this->get('/b/' . $box)->body;
        self::assertStringContainsString('Red', $page);
        self::assertStringContainsString('<svg class="swatch"', $page);

        $this->post('/lots/' . $lot['id'] . '/take', ['qty' => '3']);
        $this->post('/lots/' . $lot['id'] . '/move', ['qty' => '5', 'target' => (string) $inbox]);
        self::assertSame(2, (int) $this->queries->lots($box)[0]['qty']);
        self::assertSame(5, (int) $this->queries->lots($inbox)[0]['qty']);

        $this->post('/b/' . $box . '/lots', ['part' => '3001', 'color' => '4', 'qty' => 'abc']);
        self::assertStringContainsString('between 1 and 100,000', $this->get('/b/' . $box)->body);
    }

    public function testDeletingANonEmptyCollectionNeedsConfirmationAndCanBeUndone(): void
    {
        $this->login();
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $this->owned->addLot($this->queries->inboxId($id), '3001', 4, 3);

        $this->post('/c/' . $id . '/delete');
        self::assertNotNull($this->queries->collection($id), 'not deleted without confirmation');

        $deleted = $this->post('/c/' . $id . '/delete', ['confirm' => '1']);
        self::assertSame('/', $deleted->header('Location'));
        self::assertNull($this->queries->collection($id));

        $home = $this->get('/')->body;
        self::assertMatchesRegularExpression('#/batches/(\d+)/undo#', $home);
        preg_match('#/batches/(\d+)/undo#', $home, $m);

        $this->post('/batches/' . $m[1] . '/undo', ['return' => '/']);
        self::assertNotNull($this->queries->collection($id));
        self::assertSame(3, (int) $this->queries->lots($this->queries->inboxId($id))[0]['qty']);
        self::assertStringContainsString('Undone: collection', $this->get('/')->body);

        $this->post('/batches/' . $m[1] . '/undo', ['return' => '//evil.example']);
        self::assertStringContainsString('already been undone', $this->get('/')->body);
    }

    public function testLabelSheetHasQrCodesAndBrickLinkNumbers(): void
    {
        $this->login();
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        [$box] = $this->owned->createBox($id, 'Plates', 'small');
        $this->owned->setLabels($box, ['3794b', '3024']);

        $sheet = $this->get('/c/' . $id . '/labels')->body;
        self::assertSame(2, substr_count($sheet, 'class="box-label"'));
        self::assertStringContainsString('15573  3024', $sheet);
        self::assertStringContainsString('<svg', $sheet);

        $single = $this->get('/c/' . $id . '/labels?box=' . $box)->body;
        self::assertSame(1, substr_count($single, 'class="box-label"'));

        $qr = $this->get('/b/' . $box . '/qr.svg');
        self::assertSame('image/svg+xml', $qr->header('Content-Type'));
        self::assertStringStartsWith('<svg', $qr->body);
    }

    public function testUnknownIdsAre404(): void
    {
        $this->login();

        self::assertSame(404, $this->get('/c/999')->status);
        self::assertSame(404, $this->get('/b/999')->status);
        self::assertSame(404, $this->get('/b/abc')->status);
        self::assertSame(404, $this->post('/lots/999/take', ['qty' => '1'])->status);
    }

    public function testImageFallsBackToPlaceholder(): void
    {
        $this->login();
        $image = $this->get('/img?part=3024&color=71');

        self::assertSame(200, $image->status);
        self::assertSame('image/svg+xml', $image->header('Content-Type'));
    }

    public function testImageRequestsLeaveTheSessionAlone(): void
    {
        $this->login();
        $created = $this->post('/collections', ['name' => 'Mine']);
        $this->get('/img?part=3024&color=71');
        $this->get('/img?part=3024&color=71');
        self::assertStringContainsString(
            'Undo',
            $this->get((string) $created->header('Location'))->body,
            'the message of the last change is still shown after the page loaded its pictures'
        );
    }

    public function testBusyDownloadsAnswerWithAPendingPicture(): void
    {
        $this->login();
        $locks = sys_get_temp_dir() . '/studbook-img-unused/.locks';
        if (!is_dir($locks)) {
            mkdir($locks, 0775, true);
        }
        $held = [];
        for ($i = 0; $i < ImageCache::MAX_PARALLEL; $i++) {
            $held[] = $handle = fopen($locks . '/fetch-' . $i . '.lock', 'c');
            flock($handle, LOCK_EX);
        }
        try {
            $image = $this->get('/img?part=3001&color=4');
        } finally {
            array_map('fclose', $held);
        }
        self::assertSame(303, $image->status);
        self::assertSame(ImageController::PENDING, $image->header('Location'));
        self::assertSame('no-store', $image->header('Cache-Control'));
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
