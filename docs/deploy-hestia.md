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
                 # DB_* from step 1.3; TRUSTED_PROXIES = the NPMplus IP if it runs on another host;
                 # SETUP_TOKEN = a random value of at least 16 characters
/usr/bin/php8.3 ~/.composer/composer install --no-dev --optimize-autoloader
```

(Instead of `cp` and `nano` you can also upload a filled-in `.env` over FTP; just never commit it.)

Then, in the browser:

1. Open `https://studbook.example.com/setup` and enter the `SETUP_TOKEN` value.
2. **Server check:** every line should say OK (`intl` is only recommended). Fix any error in `.env` or the Hestia panel, then press *Check again*. The storage folder is created automatically if possible.
3. **Database tables:** press *Apply database updates*.
4. **Your login:** choose username and password. You are logged in right away.
5. Remove the `SETUP_TOKEN` line from `.env`. The setup page no longer opens once a login exists, but the token is not needed any more either.

Pick the language under **Settings**.

Command-line alternative to steps 1–4: `/usr/bin/php8.3 bin/migrate` and `/usr/bin/php8.3 bin/create-user yourname`.

## 5. Every update

1. On your computer: update the clone to the latest `main` (section 2).
2. Upload the files (section 3).
3. Over SSH: `cd APP_DIR && /usr/bin/php8.3 ~/.composer/composer install --no-dev --optimize-autoloader`. Safe to run every time; it does nothing when `composer.lock` has not changed.
4. Log in. If the new version brings database updates, you are taken to the setup page; press *Apply database updates*. (Or over SSH: `/usr/bin/php8.3 bin/migrate`, which prints "Database is up to date." when there is nothing to apply.)

## 6. Troubleshooting

- **"Dependencies are missing"** — Composer has not been run on the server yet (section 4/5), or it failed; run it again and read its output.
- **"Missing required configuration keys"** — `.env` on the server lacks keys that a newer `.env.example` documents; copy them over.
- **Generic "Something went wrong" page** — details are in the domain's PHP error log (Hestia: `/var/log/apache2/domains/studbook.example.com.error.log` or the PHP-FPM log). Never set `APP_DEBUG=true` in production.
- **Setup page says "not set up yet" on a working site** — the app cannot reach the database or finds no login. Check `DB_*` in `.env`; the setup page (with `SETUP_TOKEN`) shows the exact database error.
- **A database update fails on the setup page** — the message is shown there and written to the PHP error log. Fix the cause, then apply again, or run `bin/migrate` over SSH for the full output.
- **Forgotten password** — `/usr/bin/php8.3 bin/create-user yourname --reset-password`.

## 7. Backups

Hestia's built-in backup covers the database and the domain folder (including `.env` and uploads). Catalogue data can always be re-imported.
