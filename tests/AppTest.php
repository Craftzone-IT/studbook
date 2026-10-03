<?php

declare(strict_types=1);

namespace Studbook\Tests;

use PHPUnit\Framework\TestCase;
use Studbook\App;
use Studbook\Config;
use Studbook\Http\Csrf;
use Studbook\Http\Request;

/** HTTP behaviour that does not need a database. */
final class AppTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $_SESSION = [];
        $config = new Config([
            'APP_URL' => 'https://studbook.test',
            'APP_ENV' => 'production',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_NAME' => 'none',
            'DB_USER' => 'none',
            'DB_PASSWORD' => '',
        ], dirname(__DIR__));
        $this->app = new App($config);
    }

    public function testPrivatePagesRedirectToLogin(): void
    {
        foreach (['/', '/settings'] as $path) {
            $response = $this->app->handle(new Request('GET', $path));

            self::assertSame(303, $response->status, $path);
            self::assertSame('/login', $response->header('Location'));
        }
    }

    public function testPrivatePostRedirectsToLoginBeforeAnythingElse(): void
    {
        $response = $this->app->handle(new Request('POST', '/settings', post: ['ui_language' => 'hu']));

        self::assertSame(303, $response->status);
    }

    public function testLoginPageIsPublicAndNotIndexed(): void
    {
        $response = $this->app->handle(new Request('GET', '/login'));

        self::assertSame(200, $response->status);
        self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        self::assertStringContainsString('name="_csrf"', $response->body);
        self::assertStringContainsString('Rebrickable', $response->body);
    }

    public function testLoginWithoutCsrfTokenIsRejected(): void
    {
        $response = $this->app->handle(new Request('POST', '/login', post: ['username' => 'a', 'password' => 'b']));

        self::assertSame(419, $response->status);
    }

    public function testRobotsDisallowsEverything(): void
    {
        $response = $this->app->handle(new Request('GET', '/robots.txt'));

        self::assertSame(200, $response->status);
        self::assertSame("User-agent: *\nDisallow: /\n", $response->body);
        self::assertSame(
            (string) file_get_contents(dirname(__DIR__) . '/public/robots.txt'),
            $response->body
        );
    }

    public function testUnknownRouteIs404AndWrongMethodIs405(): void
    {
        self::assertSame(404, $this->app->handle(new Request('GET', '/nope'))->status);
        self::assertSame(405, $this->app->handle(new Request('GET', '/logout'))->status);
    }

    public function testServerErrorsHideDetailsInProduction(): void
    {
        // A valid CSRF token gets past the check; the unreachable database then fails.
        $token = Csrf::token();
        $response = $this->app->handle(new Request(
            'POST',
            '/login',
            post: ['_csrf' => $token, 'username' => 'a', 'password' => 'b'],
            server: ['REMOTE_ADDR' => '127.0.0.1']
        ));

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('PDOException', $response->body);
        self::assertStringNotContainsString('#0 ', $response->body);
    }
}
