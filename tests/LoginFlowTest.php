<?php

declare(strict_types=1);

namespace Studbook\Tests;

use Studbook\App;
use Studbook\Auth\LoginThrottle;
use Studbook\Auth\UserRepository;
use Studbook\Config;
use Studbook\Http\Csrf;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\SettingRepository;

/** End-to-end login, logout and settings against the test database. */
final class LoginFlowTest extends DatabaseTestCase
{
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        (new UserRepository($this->pdo))->create('owner', 'correct horse battery');
        $_SESSION = [];
        $config = new Config([
            'APP_URL' => 'https://studbook.test',
            'APP_ENV' => 'production',
            'APP_DEFAULT_LANGUAGE' => 'en',
            'DB_HOST' => 'unused',
            'DB_NAME' => 'unused',
            'DB_USER' => 'unused',
            'DB_PASSWORD' => '',
        ], dirname(__DIR__));
        $this->app = new App($config, $this->pdo);
    }

    public function testSuccessfulLoginOpensPrivatePages(): void
    {
        $response = $this->post('/login', ['username' => 'owner', 'password' => 'correct horse battery']);

        self::assertSame(303, $response->status);
        self::assertSame('/', $response->header('Location'));
        $home = $this->get('/');
        self::assertSame(200, $home->status);
        self::assertStringContainsString('Collections', $home->body);
        self::assertStringContainsString('Log out', $home->body);
    }

    public function testWrongPasswordIsRejectedAndThrottled(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_PER_IP; $i++) {
            $response = $this->post('/login', ['username' => 'owner', 'password' => 'wrong']);
            self::assertSame(401, $response->status);
            self::assertStringContainsString('Wrong username or password', $response->body);
        }

        // Even the right password is refused while blocked.
        $blocked = $this->post('/login', ['username' => 'owner', 'password' => 'correct horse battery']);
        self::assertSame(429, $blocked->status);
        self::assertSame(303, $this->get('/')->status);
    }

    public function testCsrfTokenRotatesOnLogin(): void
    {
        $before = Csrf::token();
        $this->post('/login', ['username' => 'owner', 'password' => 'correct horse battery']);

        self::assertFalse(Csrf::isValid($before));
    }

    public function testLanguageSettingSwitchesTheUi(): void
    {
        $this->post('/login', ['username' => 'owner', 'password' => 'correct horse battery']);

        $saved = $this->post('/settings', ['ui_language' => 'hu']);
        self::assertSame(303, $saved->status);
        self::assertSame('hu', (new SettingRepository($this->pdo))->get(SettingRepository::UI_LANGUAGE));

        $page = $this->get('/settings');
        self::assertStringContainsString('<html lang="hu">', $page->body);
        self::assertStringContainsString('Beállítások', $page->body);
        self::assertStringContainsString('A beállítások elmentve.', $page->body);
    }

    public function testInvalidLanguageIsRejected(): void
    {
        $this->post('/login', ['username' => 'owner', 'password' => 'correct horse battery']);
        $this->post('/settings', ['ui_language' => 'xx']);

        self::assertNull((new SettingRepository($this->pdo))->get(SettingRepository::UI_LANGUAGE));
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->post('/login', ['username' => 'owner', 'password' => 'correct horse battery']);
        $this->post('/logout', []);

        self::assertSame(303, $this->get('/')->status);
    }

    private function get(string $path): Response
    {
        return $this->app->handle(new Request('GET', $path, server: ['REMOTE_ADDR' => '192.0.2.10']));
    }

    /** @param array<string, string> $data */
    private function post(string $path, array $data): Response
    {
        return $this->app->handle(new Request(
            'POST',
            $path,
            post: $data + [Csrf::FIELD => Csrf::token()],
            server: ['REMOTE_ADDR' => '192.0.2.10']
        ));
    }
}
