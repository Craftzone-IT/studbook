-- Initial schema: settings, the single login, and login throttling.
-- Owned-data and catalogue tables follow in later milestones (see docs/data-model.md).

CREATE TABLE setting (
    `key` VARCHAR(64) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    UNIQUE KEY uq_user_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempt (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY idx_login_attempt_ip_time (ip, attempted_at),
    KEY idx_login_attempt_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
