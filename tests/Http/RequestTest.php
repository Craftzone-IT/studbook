<?php

declare(strict_types=1);

namespace Studbook\Tests\Http;

use PHPUnit\Framework\TestCase;
use Studbook\Http\Request;

final class RequestTest extends TestCase
{
    public function testForwardedForIsIgnoredFromUntrustedPeer(): void
    {
        $request = new Request('GET', '/', server: [
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        self::assertSame('203.0.113.5', $request->clientIp(['10.0.0.1']));
    }

    public function testForwardedForIsUsedBehindTrustedProxy(): void
    {
        $request = new Request('GET', '/', server: [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7',
        ]);

        // The right-most untrusted address is the one the proxy saw; the left part is client-controlled.
        self::assertSame('198.51.100.7', $request->clientIp(['10.0.0.1']));
    }

    public function testNonStringInputIsIgnored(): void
    {
        $request = new Request('POST', '/', post: ['name' => ['array']]);

        self::assertSame('', $request->input('name'));
    }
}
