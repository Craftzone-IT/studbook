<?php

declare(strict_types=1);

namespace Studbook\Tests;

use PHPUnit\Framework\TestCase;
use Studbook\App;
use Studbook\Config;
use Studbook\Http\Csrf;
use Studbook\Http\Request;

/** A first install with wrong database settings still reaches the setup checks. */
final class SetupWithoutDatabaseTest extends TestCase
{
    public function testSetupShowsTheDatabaseErrorAndBlocksMigrations(): void
    {
        $_SESSION = [];
        $app = new App(new Config([
            'APP_URL' => 'https://studbook.test',
            'APP_ENV' => 'production',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_NAME' => 'none',
            'DB_USER' => 'none',
            'DB_PASSWORD' => '',
            'STORAGE_PATH' => sys_get_temp_dir(),
            'SETUP_TOKEN' => 'test-setup-token-1234567890',
        ], dirname(__DIR__)));

        $login = $app->handle(new Request('GET', '/login'));
        self::assertStringContainsString('/setup', $login->body);

        $token = $app->handle(new Request(
            'POST',
            '/setup/token',
            post: ['token' => 'test-setup-token-1234567890', Csrf::FIELD => Csrf::token()],
            server: ['REMOTE_ADDR' => '192.0.2.30']
        ));
        self::assertSame(303, $token->status);

        $page = $app->handle(new Request('GET', '/setup'));
        self::assertSame(200, $page->status);
        self::assertMatchesRegularExpression('/check-error.*Database connection/s', $page->body);
        self::assertStringContainsString('Available once the database connection works', $page->body);
        self::assertStringNotContainsString('/setup/migrate', $page->body);
    }
}
