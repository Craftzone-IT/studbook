#!/usr/bin/env bash
# Prepares a Claude Code cloud session (see docs/cloud-dev.md). Idempotent.
# Runs only when CLAUDE_CODE_REMOTE=true (guarded in .claude/settings.json).
set -euo pipefail

cd "$(dirname "$0")/.."

DEV_DB_USER=studbook
DEV_DB_PASSWORD=studbook

# 1. Database server
if ! mariadb-admin ping --silent >/dev/null 2>&1; then
  service mariadb start >/dev/null
fi

# 2. Databases and dev user
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS studbook CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS studbook_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DEV_DB_USER}'@'localhost' IDENTIFIED BY '${DEV_DB_PASSWORD}';
CREATE USER IF NOT EXISTS '${DEV_DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DEV_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON studbook.* TO '${DEV_DB_USER}'@'localhost', '${DEV_DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON studbook_test.* TO '${DEV_DB_USER}'@'localhost', '${DEV_DB_USER}'@'127.0.0.1';
SQL

# 3. Local .env with development values
if [ ! -f .env ]; then
  sed \
    -e 's|^APP_URL=.*|APP_URL=http://localhost:8080|' \
    -e 's|^APP_ENV=.*|APP_ENV=development|' \
    -e 's|^APP_DEBUG=.*|APP_DEBUG=true|' \
    -e 's|^DB_HOST=.*|DB_HOST=127.0.0.1|' \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DEV_DB_PASSWORD}|" \
    .env.example > .env
fi

# 4. Dependencies (skipped when vendor/ is newer than composer.lock)
if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
  COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress --quiet
fi

# 5. Schema
php bin/migrate

# Database tests run against studbook_test.
if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
  {
    echo "export STUDBOOK_TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=studbook_test;charset=utf8mb4'"
    echo "export STUDBOOK_TEST_DB_USER=${DEV_DB_USER}"
    echo "export STUDBOOK_TEST_DB_PASSWORD=${DEV_DB_PASSWORD}"
  } >> "$CLAUDE_ENV_FILE"
fi

echo "Studbook dev environment ready (php -S localhost:8080 -t public public/index.php)."
