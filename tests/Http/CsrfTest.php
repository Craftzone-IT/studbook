<?php

declare(strict_types=1);

namespace Studbook\Tests\Http;

use PHPUnit\Framework\TestCase;
use Studbook\Http\Csrf;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testTokenIsStableAndValidated(): void
    {
        $token = Csrf::token();

        self::assertSame(64, strlen($token));
        self::assertSame($token, Csrf::token());
        self::assertTrue(Csrf::isValid($token));
        self::assertFalse(Csrf::isValid('wrong'));
        self::assertFalse(Csrf::isValid(''));
    }

    public function testNoTokenMeansNothingIsValid(): void
    {
        self::assertFalse(Csrf::isValid(''));
    }

    public function testResetRotatesToken(): void
    {
        $old = Csrf::token();
        Csrf::reset();

        self::assertFalse(Csrf::isValid($old));
        self::assertNotSame($old, Csrf::token());
    }
}
