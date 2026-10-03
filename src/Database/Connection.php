<?php

declare(strict_types=1);

namespace Studbook\Database;

use PDO;
use Studbook\Config;

final class Connection
{
    public static function fromConfig(Config $config, string $prefix = 'DB_'): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->get($prefix . 'HOST'),
            $config->int($prefix . 'PORT', 3306),
            $config->get($prefix . 'NAME')
        );

        return self::create($dsn, $config->get($prefix . 'USER'), $config->get($prefix . 'PASSWORD'));
    }

    public static function create(string $dsn, string $user, string $password): PDO
    {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }
}
