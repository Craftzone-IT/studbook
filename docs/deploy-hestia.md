# Deploying to HestiaCP (example)

This is an **example** for the maintainer's setup: HestiaCP, MariaDB, NPMplus reverse proxy, code uploaded **by hand over FTP** from a local clone (Windows works fine: e.g. WinSCP or FileZilla), dependencies installed and commands run on the server over **SSH**. No PHP or Composer is needed on your computer. Nothing here is required by the code: any host with PHP 8.2+, MySQL 8 / MariaDB 10.6+ and a way to run PHP on the command line works. Verify paths and commands against your Hestia version.

Placeholders: `USER` = Hestia user, `studbook.example.com` = your domain, `APP_DIR` = the folder the app is uploaded to, e.g. `/home/USER/web/studbook.example.com/public_html/studbook`.

## 1. One-time server setup

1. **Web domain:** create `studbook.example.com` in Hestia, enable SSL (Let's Encrypt). Behind NPMplus, terminate TLS in NPMplus and forward to the Hestia host.
2. **PHP:** select PHP 8.2 or newer for the domain. Required extensions: `pdo_mysql`, `mbstring`; recommended: `intl`.
3. **Database:** create a database and user in Hestia (e.g. `USER_studbook`). Use `utf8mb4`.
4. **SSH:** give the Hestia user a shell (*Users → Edit → SSH access: bash*). Note the PHP CLI binary matching the domain's PHP version, e.g. `/usr/bin/php8.3` (`php` alone may be a different version).
5. **Composer:** install it for the Hestia user (as root: `v-add-user-composer USER`); it ends up in `~/.composer/composer`. Check with `/usr/bin/php8.3 ~/.composer/composer --version`. If you prefer, download `composer.phar` from getcomposer.org into your home folder instead.
6. **Document root:** only the app's `public/` folder may be web-accessible. Point the domain's document root there:

   ```bash
   # as root on the Hestia server
   v-change-web-domain-docroot USER studbook.example.com studbook.example.com studbook/public
   ```

   (Or in the panel: *Web → Edit domain → Advanced options → Custom document root*.) Requests that are not static files must reach `public/index.php`: Hestia's nginx + Apache templates use `public/.htaccess`; the nginx + PHP-FPM templates already fall back to `index.php`.

## 2. Before uploading (on your computer)

In your local clone, switch to the latest `main` (`git checkout main`, `git pull`, or the same in your Git GUI). Nothing else is needed locally.

## 3. What to upload

Upload these to `APP_DIR` (FTP client in "overwrite if newer" or "overwrite" mode):

| Upload | Do **not** upload |
| --- | --- |
| `bin/`, `lang/`, `migrations/`, `public/` (including `.htaccess`), `src/`, `templates/` | `.env` (if you have one locally) |
| `composer.json`, `composer.lock`, `.env.example`, `LICENSE`, `README.md` | `storage/` contents (the server has its own data) |
| | `vendor/` (installed on the server by Composer) |
| | `.git/`, `.github/`, `.claude/`, `tests/`, `scripts/`, `docs/` (not needed on the server) |

Make sure hidden files are shown in the FTP client so `public/.htaccess` gets uploaded.

If a file was deleted or renamed in the repository, delete the old copy on the server too (most often in `src/` or `templates/`). Leftover files are not executed directly, but they can confuse later updates.

## 4. First installation (once, over SSH)

```bash
cd APP_DIR
cp .env.example .env
nano .env        # APP_ENV=production, APP_DEBUG=false, APP_URL=https://studbook.example.com,
                 # DB_* from step 1.3; TRUSTED_PROXIES = the NPMplus IP if it runs on another host
mkdir -p storage
/usr/bin/php8.3 ~/.composer/composer install --no-dev --optimize-autoloader
/usr/bin/php8.3 bin/migrate
/usr/bin/php8.3 bin/create-user yourname
```

Then open `https://studbook.example.com`, log in, and pick the language under **Settings**.

## 5. Every update

1. On your computer: update the clone to the latest `main` (section 2).
2. Upload the files (section 3).
3. Over SSH:

   ```bash
   cd APP_DIR
   /usr/bin/php8.3 ~/.composer/composer install --no-dev --optimize-autoloader
   /usr/bin/php8.3 bin/migrate
   ```

   Both are safe to run every time: Composer does nothing when `composer.lock` has not changed, and `bin/migrate` prints "Database is up to date." when there is nothing to apply.

## 6. Troubleshooting

- **"Dependencies are missing"** — Composer has not been run on the server yet (section 4/5), or it failed; run it again and read its output.
- **"Missing required configuration keys"** — `.env` on the server lacks keys that a newer `.env.example` documents; copy them over.
- **Generic "Something went wrong" page** — details are in the domain's PHP error log (Hestia: `/var/log/apache2/domains/studbook.example.com.error.log` or the PHP-FPM log). Never set `APP_DEBUG=true` in production.
- **Forgotten password** — `/usr/bin/php8.3 bin/create-user yourname --reset-password`.

## 7. Backups

Hestia's built-in backup covers the database and the domain folder (including `.env` and uploads). Catalogue data can always be re-imported.
