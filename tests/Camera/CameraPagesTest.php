<?php

declare(strict_types=1);

namespace Studbook\Tests\Camera;

use Studbook\App;
use Studbook\Auth\UserRepository;
use Studbook\Camera\TesseractOcr;
use Studbook\Config;
use Studbook\Http\Csrf;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Tests\Owned\OwnedTestCase;

final class CameraPagesTest extends OwnedTestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO cat_part (rb_num, name, category_id, bl_num) VALUES
            ('3942c', 'Cone 2 x 2 x 2', 20, '3942c')");
        (new UserRepository($this->pdo))->create('owner', 'correct horse battery');
        $_SESSION = [];
        $this->storage = sys_get_temp_dir() . '/studbook-cam-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (['/uploads/tmp', '/uploads', '/cache', ''] as $dir) {
            array_map('unlink', array_filter(glob($this->storage . $dir . '/*') ?: [], 'is_file'));
            @rmdir($this->storage . $dir);
        }
        parent::tearDown();
    }

    public function testPagesRequireLogin(): void
    {
        $app = $this->app();
        foreach (['/scan', '/b/1/labels/photo', '/identify'] as $path) {
            self::assertSame('/login', $this->get($app, $path)->header('Location'), $path);
        }
    }

    public function testDisabledFeaturesExplainThemselves(): void
    {
        $app = $this->app();
        $this->login($app);
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $box = $this->queries->inboxId($id);

        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/scan.js');
        self::assertStringContainsString('BarcodeDetector', $script);
        self::assertStringContainsString('data-jsqr-url', $this->get($app, '/scan')->body);
        self::assertStringContainsString('switched off', $this->get($app, '/b/' . $box . '/labels/photo')->body);
        self::assertStringContainsString('switched off', $this->get($app, '/identify')->body);
        self::assertStringNotContainsString('/labels/photo', $this->get($app, '/b/' . $box)->body, 'no link when off');
        self::assertStringContainsString('Camera features', $this->get($app, '/settings')->body);
    }

    public function testLabelsFromAPhoto(): void
    {
        $ocr = new TesseractOcr(TesseractOcrTest::binary());
        if (!$ocr->status()['available'] || !is_file(TesseractOcrTest::FONT)) {
            self::markTestSkipped('Tesseract or the test font is not installed.');
        }
        $app = $this->app(['OCR_ENABLED' => 'true', 'TESSERACT_BINARY' => TesseractOcrTest::binary()]);
        $this->login($app);
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $box = $this->queries->inboxId($id);
        $this->owned->setLabels($box, ['3001']);
        self::assertStringContainsString('/labels/photo', $this->get($app, '/b/' . $box)->body);
        self::assertStringContainsString('ready (Tesseract', $this->get($app, '/settings')->body);

        $photo = sys_get_temp_dir() . '/studbook-list-' . bin2hex(random_bytes(4)) . '.png';
        TesseractOcrTest::writeList($photo);
        $review = $this->post($app, '/b/' . $box . '/labels/photo', [], ['photo' => self::upload($photo)]);
        unlink($photo);
        self::assertSame(200, $review->status, (string) $review->header('Location'));
        self::assertStringContainsString('value="3024" checked', $review->body);
        self::assertStringContainsString('value="3942c" checked', $review->body);
        self::assertStringContainsString('value="3794b" checked', $review->body, 'BrickLink 15573 = Rebrickable 3794b');
        self::assertMatchesRegularExpression('/value="3001"\s*>/', $review->body, 'labels on the box start unticked');
        self::assertSame([], glob($this->storage . '/uploads/tmp/*') ?: [], 'the working copy is deleted');

        $saved = $this->post($app, '/b/' . $box . '/labels/photo/save', [
            'parts' => ['3024', '3942c'],
            'extra' => '3794b nope-1',
            'mode' => 'append',
        ]);
        self::assertSame('/b/' . $box, $saved->header('Location'));
        self::assertSame(['3001', '3024', '3942c', '3794b'], $this->queries->labels($box));
        $page = $this->get($app, '/b/' . $box)->body;
        self::assertStringContainsString('nope-1', $page, 'unknown numbers are reported');

        $this->post($app, '/b/' . $box . '/labels/photo/save', ['parts' => ['3942c'], 'mode' => 'replace']);
        self::assertSame(['3942c'], $this->queries->labels($box));

        $bad = $this->post($app, '/b/' . $box . '/labels/photo', []);
        self::assertSame('/b/' . $box . '/labels/photo', $bad->header('Location'));
        $form = $this->get($app, '/b/' . $box . '/labels/photo')->body;
        self::assertStringContainsString('Choose or take a photo', $form);
    }

    public function testRecognitionFailureFallsBackToSearch(): void
    {
        $app = $this->app([
            'BRICKOGNIZE_ENABLED' => 'true',
            'BRICKOGNIZE_API_URL' => 'https://127.0.0.1:1',
            'BRICKOGNIZE_TIMEOUT_SECONDS' => '2',
        ]);
        $this->login($app);
        $photo = sys_get_temp_dir() . '/studbook-part-' . bin2hex(random_bytes(4)) . '.jpg';
        $image = imagecreatetruecolor(64, 64);
        imagejpeg($image, $photo);

        $response = $this->post($app, '/identify', [], ['photo' => self::upload($photo)]);
        unlink($photo);
        self::assertSame('/identify', $response->header('Location'));
        $page = $this->get($app, '/identify')->body;
        self::assertStringContainsString('Use the search instead', $page);
    }

    /** @return array{tmp_name: string, name: string, size: int, error: int} */
    private static function upload(string $path): array
    {
        return [
            'tmp_name' => $path,
            'name' => basename($path),
            'size' => (int) filesize($path),
            'error' => UPLOAD_ERR_OK,
        ];
    }

    /** @param array<string, string> $env */
    private function app(array $env = []): App
    {
        return new App(new Config($env + [
            'APP_URL' => 'https://studbook.test',
            'APP_ENV' => 'production',
            'DB_HOST' => 'x',
            'DB_NAME' => 'x',
            'DB_USER' => 'x',
            'DB_PASSWORD' => '',
            'IMAGE_CACHE_PATH' => sys_get_temp_dir() . '/studbook-img-unused',
            'STORAGE_PATH' => $this->storage,
            'UPLOAD_PATH' => $this->storage . '/uploads',
        ], dirname(__DIR__, 2)), $this->pdo);
    }

    private function login(App $app): void
    {
        $this->post($app, '/login', ['username' => 'owner', 'password' => 'correct horse battery']);
    }

    private function get(App $app, string $path): Response
    {
        $parts = parse_url($path);
        parse_str($parts['query'] ?? '', $query);

        return $app->handle(new Request('GET', $parts['path'], query: $query, server: ['REMOTE_ADDR' => '192.0.2.50']));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array{tmp_name: string, name: string, size: int, error: int}> $files
     */
    private function post(App $app, string $path, array $data = [], array $files = []): Response
    {
        return $app->handle(new Request(
            'POST',
            $path,
            post: $data + [Csrf::FIELD => Csrf::token()],
            server: ['REMOTE_ADDR' => '192.0.2.50'],
            files: $files
        ));
    }
}
