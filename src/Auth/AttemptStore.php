<?php

declare(strict_types=1);

namespace Studbook\Auth;

use DateTimeImmutable;

/** Storage for failed login attempts, used by {@see LoginThrottle}. */
interface AttemptStore
{
    public function record(string $ip, DateTimeImmutable $at): void;

    /** Failed attempts since `$since`; all IPs when `$ip` is null. */
    public function countSince(?string $ip, DateTimeImmutable $since): int;

    public function clear(string $ip): void;

    public function purgeBefore(DateTimeImmutable $before): void;
}
