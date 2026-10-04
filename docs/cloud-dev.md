# Development in Claude Code cloud sessions

The project is developed with Claude Code cloud sessions (claude.ai/code, mobile app, or `claude --cloud`). Each session runs in a fresh Ubuntu 24.04 VM that already has **PHP 8.3 + Composer**, Docker, PostgreSQL and Redis — but **no MySQL/MariaDB**, no Tesseract and possibly not every PHP extension we need. The cloud environment's **setup script** adds them.

Reference: https://code.claude.com/docs/en/cloud-environments

## Cloud environment settings

Create a dedicated environment (e.g. `studbook`) at claude.ai/code → environment settings.

**Network access:** `Custom`, with *Also include default list of common package managers* checked (covers Packagist, Ubuntu apt mirrors, Docker Hub, GitHub), plus:

```
cdn.rebrickable.com
rebrickable.com
api.brickognize.com
```

These are only needed for manual end-to-end checks of the importer and photo recognition. Automated tests must not depend on them (use fixtures in `tests/fixtures/`).

**Environment variables:** none required. Do not put secrets here — anyone using the environment can read them. The app reads a local `.env` that the SessionStart hook creates from `.env.example` with dev values.

**Setup script** (runs as root before Claude starts; the result is cached for later sessions if it finishes within ~5 minutes):

```bash
#!/bin/bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

apt-get update -qq

# Database server matching the production host (HestiaCP uses MariaDB)
apt-get install -y -qq --no-install-recommends mariadb-server mariadb-client

# OCR engine for milestone M6 (English model is enough for part numbers)
apt-get install -y -qq --no-install-recommends tesseract-ocr tesseract-ocr-eng

# PHP extensions the app needs; install only if the preinstalled PHP lacks them
for ext in pdo_mysql intl gd mbstring xml curl zip; do
  php -m | grep -qi "^${ext}$" || MISSING="${MISSING:-} ${ext}"
done
if [ -n "${MISSING:-}" ]; then
  echo "Installing missing PHP extensions:${MISSING}"
  apt-get install -y -qq --no-install-recommends \
    php8.3-mysql php8.3-intl php8.3-gd php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip
fi

php -v | head -1
mariadb --version
tesseract --version | head -1
```

The cache stores files, not running processes, so the database server is started per session by a SessionStart hook (below).

## Per-session start (SessionStart hook)

`.claude/settings.json` has a SessionStart hook that runs `scripts/cloud-session-start.sh` **only when `CLAUDE_CODE_REMOTE=true`**. The script is idempotent and fast:

1. `service mariadb start`
2. create the `studbook` and `studbook_test` databases and a dev user if missing;
3. copy `.env.example` to `.env` with dev values if `.env` does not exist;
4. `composer install` (skipped when `vendor/` is up to date);
5. `php bin/migrate`;
6. export `STUDBOOK_TEST_DB_*` (via `CLAUDE_ENV_FILE`) so PHPUnit's database tests run against `studbook_test`.

Dev credentials are `studbook` / `studbook` on localhost only. Start the app with `php -S localhost:8080 -t public public/index.php`.

## Notes

- The production host runs whatever PHP version HestiaCP provides; keep code compatible with the minimum version stated in the README, even though the cloud VM has 8.3.
- Real catalogue data is not available in the VM by default. For importer development use small fixture files; for a full test import, download the Rebrickable CSVs in-session (allowed once per day) and place BrickLink files manually if needed — never commit them.
