<?php

declare(strict_types=1);

namespace Studbook\Auth;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Limits failed logins per client IP and across all IPs (there is only one
 * account, so a distributed attack is limited too).
 */
final class LoginThrottle
{
    public const WINDOW_SECONDS = 900;
    public const MAX_PER_IP = 5;
    public const MAX_GLOBAL = 30;

    /** @var \Closure(): DateTimeImmutable */
    private \Closure $clock;

    /** @param (callable(): DateTimeImmutable)|null $clock */
    public function __construct(private readonly AttemptStore $store, ?callable $clock = null)
    {
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function isBlocked(string $ip): bool
    {
        $since = $this->windowStart();

        return $this->store->countSince($ip, $since) >= self::MAX_PER_IP
            || $this->store->countSince(null, $since) >= self::MAX_GLOBAL;
    }

    public function recordFailure(string $ip): void
    {
        $now = ($this->clock)();
        $this->store->purgeBefore($now->modify('-1 day'));
        $this->store->record($ip, $now);
    }

    public function recordSuccess(string $ip): void
    {
        $this->store->clear($ip);
    }

    private function windowStart(): DateTimeImmutable
    {
        return ($this->clock)()->modify(sprintf('-%d seconds', self::WINDOW_SECONDS));
    }
}
