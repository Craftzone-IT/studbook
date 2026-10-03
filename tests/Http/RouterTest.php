<?php

declare(strict_types=1);

namespace Studbook\Tests\Http;

use PHPUnit\Framework\TestCase;
use Studbook\Http\Response;
use Studbook\Http\Router;

final class RouterTest extends TestCase
{
    public function testMatchesStaticAndParameterisedRoutes(): void
    {
        $router = new Router();
        $router->get('/', static fn (): Response => Response::text('home'));
        $router->get('/b/{id}', static fn (): Response => Response::text('box'));

        $home = $router->match('GET', '/');
        self::assertIsArray($home);
        self::assertFalse($home[0]->public);

        $box = $router->match('GET', '/b/42');
        self::assertIsArray($box);
        self::assertSame(['id' => '42'], $box[1]);
    }

    public function testDistinguishesUnknownPathFromWrongMethod(): void
    {
        $router = new Router();
        $router->post('/login', static fn (): Response => Response::text(''), public: true);

        self::assertNull($router->match('GET', '/nope'));
        self::assertFalse($router->match('GET', '/login'));
        self::assertTrue($router->match('POST', '/login')[0]->public);
    }

    public function testHeadIsTreatedAsGet(): void
    {
        $router = new Router();
        $router->get('/', static fn (): Response => Response::text(''));

        self::assertIsArray($router->match('HEAD', '/'));
    }

    public function testParametersDoNotSpanSegmentsAndPatternIsAnchored(): void
    {
        self::assertNull(Router::matchPattern('/b/{id}', '/b/1/2'));
        self::assertNull(Router::matchPattern('/b/{id}', '/x/b/1'));
        self::assertNull(Router::matchPattern('/a.b', '/axb'));
        self::assertNull(Router::matchPattern('/b/{id}', '/b/5abc'));
        self::assertSame(['slug' => '5abc'], Router::matchPattern('/b/{slug}', '/b/5abc'));
    }
}
