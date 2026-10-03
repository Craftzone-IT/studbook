<?php

declare(strict_types=1);

namespace Studbook\Tests\Auth;

use DateTimeImmutable;
use Studbook\Auth\AttemptStore;

final class InMemoryAttemptStore implements AttemptStore
{
    /** @var list<array{ip: string, at: DateTimeImmutable}> */
    private array $attempts = [];

    public function record(string $ip, DateTimeImmutable $at): void
    {
        $this->attempts[] = ['ip' => $ip, 'at' => $at];
    }

    public function countSince(?string $ip, DateTimeImmutable $since): int
    {
        return count(array_filter(
            $this->attempts,
            static fn (array $a): bool => ($ip === null || $a['ip'] === $ip) && $a['at'] >= $since
        ));
    }

    public function clear(string $ip): void
    {
        $this->attempts = array_values(array_filter($this->attempts, static fn (array $a): bool => $a['ip'] !== $ip));
    }

    public function purgeBefore(DateTimeImmutable $before): void
    {
        $this->attempts = array_values(
            array_filter($this->attempts, static fn (array $a): bool => $a['at'] >= $before)
        );
    }
}
