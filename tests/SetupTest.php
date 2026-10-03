<?php

declare(strict_types=1);

namespace Studbook\Tests;

use Studbook\App;
use Studbook\Auth\UserRepository;
use Studbook\Config;
use Studbook\Http\Csrf;
use Studbook\Http\Request;
use Studbook\Http\Response;

/** Browser setup (/setup) against an empty test database. */
final class SetupTest extends DatabaseTestCase
{
    private const TOKEN = 'test-setup-token-1234567890';

    public function testWithoutTokenOnlyExplainsHowToEnableSetup(): void
    {
        $app = $this->app('');

        $page = $this->get($app, '/setup');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('SETUP_TOKEN=', $page->body);
        self::assertStringNotContainsString('name="token"', $page->body);

        self::assertSame(403, $this->post($app, '/setup/migrate')->status);
        self::assertSame(404, $this->post($app, '/setup/token', ['token' => ''])->status);
        self::assertSame([], $this->tables());
    }

    public function testTooShortTokenIsNotAccepted(): void
    {
        $app = $this->app('short');

        self::assertStringContainsString('too short', $this->get($app, '/setup')->body);
        self::assertSame(404, $this->post($app, '/setup/token', ['token' => 'short'])->status);
    }

    public function testWrongTokenIsRejected(): void
    {
        $app = $this->app(self::TOKEN);

        self::assertStringContainsString('name="token"', $this->get($app, '/setup')->body);
        self::assertSame(403, $this->post($app, '/setup/token', ['token' => 'wrong-token-wrong-token'])->status);
        self::assertSame(403, $this->post($app, '/setup/migrate')->status);
        self::assertSame([], $this->tables());
    }

    public function testFullSetupFlow(): void
    {
        $app = $this->app(self::TOKEN);

        self::assertSame(303, $this->post($app, '/setup/token', ['token' => self::TOKEN])->status);
        $wizard = $this->get($app, '/setup');
        self::assertStringContainsString('Database connection', $wizard->body);
        self::assertStringContainsString('0001_initial.sql', $wizard->body);
        self::assertStringNotContainsString('name="password_repeat"', $wizard->body);

        // The user cannot be created before the tables exist.
        $this->post($app, '/setup/user', $this->userForm());
        self::assertSame(303, $this->post($app, '/setup/migrate')->status);
        self::assertContains('user', $this->tables());
        $afterMigrate = $this->get($app, '/setup');
        self::assertStringContainsString('database update(s) applied', $afterMigrate->body);
        self::assertStringContainsString('name="password_repeat"', $afterMigrate->body);

        $created = $this->post($app, '/setup/user', $this->userForm());
        self::assertSame(303, $created->status);
        self::assertSame('/', $created->header('Location'));
        self::assertSame(1, (new UserRepository($this->pdo))->count());

        // Logged in right away; the setup page is gone for good.
        $home = $this->get($app, '/');
        self::assertSame(200, $home->status);
        self::assertStringContainsString('Setup complete', $home->body);
        self::assertSame(404, $this->get($app, '/setup')->status);
        self::assertSame(404, $this->post($app, '/setup/user', $this->userForm('second'))->status);
        self::assertSame(1, (new UserRepository($this->pdo))->count());
    }

    public function testInvalidUserInputIsRejected(): void
    {
        $app = $this->app(self::TOKEN);
        $this->post($app, '/setup/token', ['token' => self::TOKEN]);
        $this->post($app, '/setup/migrate');

        $this->post($app, '/setup/user', ['password_repeat' => 'different password'] + $this->userForm());
        self::assertStringContainsString('do not match', $this->get($app, '/setup')->body);

        $this->post($app, '/setup/user', ['password' => 'short', 'password_repeat' => 'short'] + $this->userForm());
        self::assertStringContainsString('at least 10 characters', $this->get($app, '/setup')->body);

        $this->post($app, '/setup/user', ['username' => 'no spaces allowed'] + $this->userForm());
        self::assertSame(0, (new UserRepository($this->pdo))->count());
    }

    public function testChangingTheTokenEndsTheSetupSession(): void
    {
        $this->post($this->app(self::TOKEN), '/setup/token', ['token' => self::TOKEN]);

        $other = $this->app('another-token-another-token');
        self::assertSame(403, $this->post($other, '/setup/migrate')->status);
    }

    public function testLoginPagePointsToSetupWhileNoUserExists(): void
    {
        $app = $this->app('');
        self::assertStringContainsString('/setup', $this->get($app, '/login')->body);

        $this->migrate();
        (new UserRepository($this->pdo))->create('owner', 'correct horse battery');
        self::assertStringNotContainsString('href="/setup"', $this->get($app, '/login')->body);
    }

    public function testPendingMigrationsAfterUpdateNeedLoginNotToken(): void
    {
        $this->migrate();
        (new UserRepository($this->pdo))->create('owner', 'correct horse battery');
        // Simulate an update that ships a migration not applied yet.
        $this->pdo->exec('DELETE FROM schema_migration');
        $app = $this->app(self::TOKEN);

        self::assertSame('/login', $this->get($app, '/setup')->header('Location'));
        self::assertSame(404, $this->post($app, '/setup/token', ['token' => self::TOKEN])->status);

        $this->post($app, '/login', ['username' => 'owner', 'password' => 'correct horse battery']);
        // Private pages send the owner to the setup page until migrations ran.
        self::assertSame('/setup', $this->get($app, '/')->header('Location'));
        $page = $this->get($app, '/setup');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('0001_initial.sql', $page->body);
        self::assertStringNotContainsString('name="password_repeat"', $page->body);
    }

    private function app(string $token): App
    {
        $config = new Config([
            'APP_URL' => 'https://studbook.test',
            'APP_ENV' => 'production',
            'DB_HOST' => 'unused',
            'DB_NAME' => 'studbook_test',
            'DB_USER' => 'unused',
            'DB_PASSWORD' => '',
            'STORAGE_PATH' => sys_get_temp_dir(),
            'SETUP_TOKEN' => $token,
        ], dirname(__DIR__));

        return new App($config, $this->pdo);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    /** @return array<string, string> */
    private function userForm(string $username = 'owner'): array
    {
        return [
            'username' => $username,
            'password' => 'correct horse battery',
            'password_repeat' => 'correct horse battery',
        ];
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_map('strval', $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function get(App $app, string $path): Response
    {
        return $app->handle(new Request('GET', $path, server: ['REMOTE_ADDR' => '192.0.2.20']));
    }

    /** @param array<string, string> $data */
    private function post(App $app, string $path, array $data = []): Response
    {
        return $app->handle(new Request(
            'POST',
            $path,
            post: $data + [Csrf::FIELD => Csrf::token()],
            server: ['REMOTE_ADDR' => '192.0.2.20']
        ));
    }
}
