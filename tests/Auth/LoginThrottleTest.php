<?php

declare(strict_types=1);

namespace Studbook\Tests\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Studbook\Auth\LoginThrottle;

final class LoginThrottleTest extends TestCase
{
    private InMemoryAttemptStore $store;
    private DateTimeImmutable $now;
    private LoginThrottle $throttle;

    protected function setUp(): void
    {
        $this->store = new InMemoryAttemptStore();
        $this->now = new DateTimeImmutable('2026-10-03 12:00:00');
        $this->throttle = new LoginThrottle($this->store, fn (): DateTimeImmutable => $this->now);
    }

    public function testBlocksAnIpAfterTooManyFailures(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_PER_IP - 1; $i++) {
            $this->throttle->recordFailure('1.1.1.1');
        }
        self::assertFalse($this->throttle->isBlocked('1.1.1.1'));

        $this->throttle->recordFailure('1.1.1.1');
        self::assertTrue($this->throttle->isBlocked('1.1.1.1'));
        self::assertFalse($this->throttle->isBlocked('2.2.2.2'));
    }

    public function testBlockExpiresAfterTheWindow(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_PER_IP; $i++) {
            $this->throttle->recordFailure('1.1.1.1');
        }
        $this->now = $this->now->modify('+' . (LoginThrottle::WINDOW_SECONDS + 1) . ' seconds');

        self::assertFalse($this->throttle->isBlocked('1.1.1.1'));
    }

    public function testGlobalLimitBlocksDistributedAttempts(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_GLOBAL; $i++) {
            $this->throttle->recordFailure('10.0.' . intdiv($i, 250) . '.' . ($i % 250));
        }

        self::assertTrue($this->throttle->isBlocked('192.0.2.1'));
    }

    public function testSuccessClearsTheIp(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_PER_IP; $i++) {
            $this->throttle->recordFailure('1.1.1.1');
        }
        $this->throttle->recordSuccess('1.1.1.1');

        self::assertFalse($this->throttle->isBlocked('1.1.1.1'));
    }
}
