<?php

declare(strict_types=1);

namespace Studbook\Auth;

use DateTimeImmutable;
use PDO;

final class PdoAttemptStore implements AttemptStore
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $ip, DateTimeImmutable $at): void
    {
        $this->pdo->prepare('INSERT INTO login_attempt (ip, attempted_at) VALUES (?, ?)')
            ->execute([$ip, $at->format(self::FORMAT)]);
    }

    public function countSince(?string $ip, DateTimeImmutable $since): int
    {
        if ($ip === null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempt WHERE attempted_at >= ?');
            $stmt->execute([$since->format(self::FORMAT)]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempt WHERE ip = ? AND attempted_at >= ?');
            $stmt->execute([$ip, $since->format(self::FORMAT)]);
        }

        return (int) $stmt->fetchColumn();
    }

    public function clear(string $ip): void
    {
        $this->pdo->prepare('DELETE FROM login_attempt WHERE ip = ?')->execute([$ip]);
    }

    public function purgeBefore(DateTimeImmutable $before): void
    {
        $this->pdo->prepare('DELETE FROM login_attempt WHERE attempted_at < ?')
            ->execute([$before->format(self::FORMAT)]);
    }
}
