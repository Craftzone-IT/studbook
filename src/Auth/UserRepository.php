<?php

declare(strict_types=1);

namespace Studbook\Auth;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id: int, username: string, password_hash: string}|null */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, password_hash FROM user WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'password_hash' => (string) $row['password_hash'],
        ];
    }

    /** @return array{id: int, username: string}|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username FROM user WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['id' => (int) $row['id'], 'username' => (string) $row['username']] : null;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM user')->fetchColumn();
    }

    public function create(string $username, string $password): int
    {
        $this->pdo->prepare('INSERT INTO user (username, password_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP())')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updatePassword(int $id, string $password): void
    {
        $this->pdo->prepare('UPDATE user SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public function touchLogin(int $id): void
    {
        $this->pdo->prepare('UPDATE user SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }
}
