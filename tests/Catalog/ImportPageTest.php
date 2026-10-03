<?php

declare(strict_types=1);

namespace Studbook\Tests\Catalog;

use Studbook\App;
use Studbook\Auth\UserRepository;
use Studbook\Catalog\ImportRunRepository;
use Studbook\Config;
use Studbook\Http\Csrf;
use Studbook\Http\Request;
use Studbook\Tests\DatabaseTestCase;

final class ImportPageTest extends DatabaseTestCase
{
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        (new UserRepository($this->pdo))->create('owner', 'correct horse battery');
        $_SESSION = [];
        $this->app = new App(new Config([
            'APP_URL' => 'https://studbook.test',
            'APP_ENV' => 'production',
            'DB_HOST' => 'x',
            'DB_NAME' => 'x',
            'DB_USER' => 'x',
            'DB_PASSWORD' => '',
        ], dirname(__DIR__, 2)), $this->pdo);
    }

    public function testPageRequiresLogin(): void
    {
        self::assertSame('/login', $this->app->handle(new Request('GET', '/admin/import'))->header('Location'));
    }

    public function testRunNowQueuesAnImportAndShowsCronHelp(): void
    {
        $this->post('/login', ['username' => 'owner', 'password' => 'correct horse battery']);

        $page = $this->app->handle(new Request('GET', '/admin/import'));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('bin/import --cron', $page->body);
        self::assertStringContainsString('Run import now', $page->body);

        self::assertSame(303, $this->post('/admin/import/run')->status);
        self::assertNotNull((new ImportRunRepository($this->pdo))->nextQueued());
        $queued = $this->app->handle(new Request('GET', '/admin/import'));
        self::assertStringContainsString('An import is queued', $queued->body);
        self::assertStringNotContainsString('Run import now</button>', $queued->body);
    }

    public function testReportShowsLastSuccessfulRun(): void
    {
        $runs = new ImportRunRepository($this->pdo);
        $id = $runs->queue('cli');
        $runs->start($id);
        $runs->finish($id, ImportRunRepository::SUCCESS, 'log', [
            'counts' => ['cat_part' => 64769, 'cat_set' => 28441],
            'bricklink' => ['parts' => 0, 'colors' => 0],
            'colors' => ['total' => 275, 'matched' => 0, 'unmatched' => []],
            'parts' => [
                'matched_exact' => 0,
                'matched_alternate' => 0,
                'unmatched' => 64769,
                'unmatched_samples' => [],
            ],
            'duration_seconds' => 85,
        ]);
        $this->post('/login', ['username' => 'owner', 'password' => 'correct horse battery']);

        $page = $this->app->handle(new Request('GET', '/admin/import'))->body;
        self::assertStringContainsString('64,769', $page);
        self::assertStringContainsString('No BrickLink parts', $page);
        self::assertStringContainsString('85 s', $page);
    }

    /** @param array<string, string> $data */
    private function post(string $path, array $data = []): \Studbook\Http\Response
    {
        return $this->app->handle(new Request(
            'POST',
            $path,
            post: $data + [Csrf::FIELD => Csrf::token()],
            server: ['REMOTE_ADDR' => '192.0.2.40']
        ));
    }
}
