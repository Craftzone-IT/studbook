# Deploying to HestiaCP (example)

This is an **example** for the maintainer's setup: HestiaCP, MariaDB, NPMplus reverse proxy, code uploaded **by hand over FTP** from a local clone (Windows works fine, e.g. with WinSCP, FileZilla or Total Commander), dependencies installed and commands run on the server over **SSH**. Nothing here is required by the code: any host with PHP 8.2+, MySQL 8 / MariaDB 10.6+ and a way to run PHP on the command line works.

Placeholders: `USER` = Hestia user (e.g. `craftzone`), `DOMAIN` = your domain (e.g. `studbook.example.com`), `PHP` = the PHP CLI matching the domain's PHP version (e.g. `/usr/bin/php8.3`; plain `php` may be another version).

## Folder layout

Hestia gives every web domain this structure, and Studbook uses it as it is — no document-root changes:

```
/home/USER/web/DOMAIN/
├── private/        ← the application (the files from the repository)
│   ├── bin/ lang/ migrations/ src/ templates/ vendor/ …
│   ├── .env        ← configuration, never web-accessible
│   └── storage/    ← catalogue files, image cache, uploads
└── public_html/    ← the web root: a copy of the repository's public/ folder
    ├── index.php   ← finds the application in ../private/ by itself
    ├── .htaccess
    ├── robots.txt
    └── assets/
```

`public_html/index.php` looks for the application next to itself (`../`, the standard layout) and in `../private/` (this layout).

**Run every command as the Hestia user, not as root.** Files created by root (e.g. `vendor/`, `.env`, cached images) cannot be managed by the web server, which runs as `USER`. As root, prefix commands with `sudo -u USER -H`.

## 1. One-time server setup (Hestia panel)

1. **Web domain:** create `DOMAIN`, enable SSL (Let's Encrypt). Behind NPMplus, terminate TLS in NPMplus and forward to the Hestia host.
2. **PHP:** select PHP 8.2 or newer for the domain. Required extensions: `pdo_mysql`, `mbstring`; recommended: `intl`.
3. **Database:** create a database and user (e.g. `USER_studbook`). Use `utf8mb4`.
4. **Default files:** delete Hestia's placeholder `index.html` from `public_html/` (it would be served instead of the app).
5. **Composer** for the Hestia user (as root, once): `v-add-user-composer USER`. It ends up in `/home/USER/.composer/composer`.

## 2. Uploading (from your computer)

Update your local clone to the latest `main` (`git pull`, or the same in your Git GUI). Then upload over FTP:

| Local folder or file | Upload to |
| --- | --- |
| `bin/`, `lang/`, `migrations/`, `src/`, `templates/`, `composer.json`, `composer.lock`, `.env.example` | `DOMAIN/private/` |
| contents of `public/` (`index.php`, `.htaccess`, `robots.txt`, `assets/`) | `DOMAIN/public_html/` |

Do **not** upload: `vendor/` (Composer installs it on the server), `.env` (if you have a local one), `storage/`, `.git/`, `.github/`, `.claude/`, `tests/`, `scripts/`, `docs/`. Show hidden files in the FTP client so `.htaccess` is uploaded.

When a file was deleted or renamed in the repository, delete the old copy on the server too (most often in `src/` or `templates/`).

## 3. First installation (SSH, as root)

```bash
cd /home/USER/web/DOMAIN/private
cp .env.example .env
nano .env
#   APP_URL=https://DOMAIN
#   APP_ENV=production, APP_DEBUG=false
#   APP_TIMEZONE=Europe/Budapest (or yours)
#   DB_NAME / DB_USER / DB_PASSWORD from step 1.3
#   TRUSTED_PROXIES = the NPMplus IP (127.0.0.1 if it runs on the same machine)
#   SETUP_TOKEN = output of: openssl rand -hex 16
#   REBRICKABLE_API_KEY = your own key from rebrickable.com → Settings → API (recommended)
mkdir -p storage
chown -R USER:USER .
chmod 600 .env
sudo -u USER -H PHP /home/USER/.composer/composer install --no-dev --optimize-autoloader
```

Then, in the browser:

1. Open `https://DOMAIN/setup` and enter the `SETUP_TOKEN` value.
2. **Server check:** every line should say OK (`intl` is only recommended). Fix any error in `.env` or the Hestia panel, then press *Check again*.
3. **Database tables:** press *Apply database updates*.
4. **Your login:** choose username and password. You are logged in right away.
5. Remove the `SETUP_TOKEN` line from `.env`.

Pick the language under **Settings**.

Command-line alternative to steps 1–4: `sudo -u USER -H PHP bin/migrate` and `sudo -u USER -H PHP bin/create-user yourname`.

### Catalogue

1. Add the cron job for the catalogue import (Hestia: *Cron jobs*, it runs as `USER`), every 15 minutes:
   `PHP /home/USER/web/DOMAIN/private/bin/import --cron --quiet`
   The same job also downloads pictures of your parts and sets in advance (`IMAGE_PREFETCH_PER_RUN` in `.env`, 200 per run by default), so pages open with their pictures ready.
2. Upload your BrickLink parts and colours lists to `DOMAIN/private/storage/catalog/bricklink/`.
3. Open *Catalogue* in the app and press *Run import now*; it starts within 15 minutes.

Details: [catalogue-import.md](catalogue-import.md).

### Camera features (optional)

Both are switched off by default; the Settings page shows whether they work.

**Reading part numbers from photos (OCR)** runs Tesseract on the server:

1. As root: `apt install tesseract-ocr` (the English model comes with it).
2. PHP must allow `exec()` for this site. Hestia's PHP-FPM templates may list it under `disable_functions`. If the Settings page says *exec() disabled*: copy the PHP-FPM template the domain uses (`/usr/local/hestia/data/templates/web/php-fpm/`, e.g. `default.tpl`) to `studbook.tpl`, remove `exec` from its `disable_functions` line, then choose `studbook` as **Backend template PHP-FPM** in the web domain's advanced options. Only `exec` is needed; Studbook runs nothing but the Tesseract binary named in `.env`, with escaped arguments.
3. In `.env`: `OCR_ENABLED=true` (and `TESSERACT_BINARY` if `which tesseract` is not `/usr/bin/tesseract`).

**Part recognition** sends the photo of a single part to the Brickognize API (`api.brickognize.com`). Read Brickognize's terms of service first, then set `BRICKOGNIZE_ENABLED=true`. Nothing is installed on the server; the server needs outgoing HTTPS.

Photos are shrunk in the browser before upload; without JavaScript, phone photos can exceed PHP's upload limit. If uploads fail, raise `upload_max_filesize` and `post_max_size` (e.g. 16M) in the domain's PHP settings. Photos are deleted right after reading; nothing is kept.

## 4. Every update

1. Update the local clone and upload the files (section 2).
2. Over SSH: `cd /home/USER/web/DOMAIN/private && sudo -u USER -H PHP /home/USER/.composer/composer install --no-dev --optimize-autoloader`. Safe to run every time; it does nothing when `composer.lock` has not changed.
3. Log in. If the new version brings database updates, you are taken to the setup page; press *Apply database updates*. (Or over SSH: `sudo -u USER -H PHP bin/migrate`.)

## 5. Troubleshooting

- **"Studbook is not installed completely"** — `public_html/index.php` found no application in `../` or `../private/`, or `vendor/` is missing there. The domain's PHP error log names the exact path (Hestia: `/var/log/apache2/domains/DOMAIN.error.log` or the PHP-FPM log). Usually Composer has not been run yet (section 3).
- **"Could not open input file: /root/.composer/composer"** — the command ran as root with `~`; use the full path `/home/USER/.composer/composer` and `sudo -u USER -H` as shown above.
- **"proc_open is disabled"** from Composer — only a warning; the install works.
- **`PHP Warning: … Unable to load dynamic library 'pdo_sqlite'`** — harmless, the host's PHP configuration enables a module that is not installed. Studbook does not need it (`phpdismod -v 8.3 -s cli pdo_sqlite` removes the warning).
- **"Missing required configuration keys"** — `.env` lacks keys that a newer `.env.example` documents; copy them over.
- **Generic "Something went wrong" page** — details are in the PHP error log. Never set `APP_DEBUG=true` in production.
- **Forgotten password** — `sudo -u USER -H PHP bin/create-user yourname --reset-password`. If the host disables `shell_exec`, the password prompt is visible while typing.

## 6. Backups

Hestia's built-in backup covers the database and the domain folder (including `private/.env` and uploads). Catalogue data can always be re-imported.
