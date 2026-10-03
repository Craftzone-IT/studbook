# Deploying to HestiaCP (example)

This is an **example** for the maintainer's setup (HestiaCP, MariaDB, NPMplus reverse proxy). Nothing here is required by the code: any host with PHP 8.2+, MySQL 8 / MariaDB 10.6+ and SSH works. Verify paths and commands against your Hestia version.

Placeholders: `USER` = Hestia user, `studbook.example.com` = your domain.

## 1. One-time server setup

1. **Web domain:** create `studbook.example.com` in Hestia, enable SSL (Let's Encrypt). Behind NPMplus, terminate TLS in NPMplus and forward to the Hestia host.
2. **PHP:** select PHP 8.2 or newer for the domain (backend template). Required extensions: `pdo_mysql`, `mbstring`; recommended: `intl`.
3. **Database:** create a database and user in Hestia (e.g. `USER_studbook`). Use `utf8mb4`.
4. **SSH:** give the Hestia user a shell (*Users → Edit → SSH access: bash*) and add the deploy public key to `~USER/.ssh/authorized_keys`. Use a dedicated key pair for GitHub Actions only.
5. **Code location and document root:** the deploy uploads the repository to `DEPLOY_PATH`, for example `/home/USER/web/studbook.example.com/public_html/studbook`. Only its `public/` folder may be web-accessible, so point the domain's document root there:

   ```bash
   # as root on the Hestia server
   v-change-web-domain-docroot USER studbook.example.com studbook.example.com studbook/public
   ```

   (Or in the panel: *Web → Edit domain → Advanced options → Custom document root*.) Requests that are not static files must reach `public/index.php`: Hestia's nginx + Apache templates use `public/.htaccess`; the nginx + PHP-FPM templates already fall back to `index.php`.
6. **Configuration:** create `DEPLOY_PATH/.env` by hand from `.env.example` (production values, `APP_ENV=production`, `APP_DEBUG=false`, real DB credentials, `APP_URL=https://studbook.example.com`). If NPMplus runs on another host, put its IP in `TRUSTED_PROXIES`. The deploy never overwrites `.env` or `storage/`.
7. **Storage:** `mkdir -p DEPLOY_PATH/storage` and make it writable by PHP (in Hestia PHP runs as `USER`, so the default permissions are fine).

## 2. GitHub Actions secrets

Set these under *Repository → Settings → Secrets and variables → Actions* (or on the `production` environment). Without `DEPLOY_HOST`, `DEPLOY_USER` and `DEPLOY_PATH` the deploy job is skipped.

| Secret | Example | Notes |
| --- | --- | --- |
| `DEPLOY_HOST` | `hestia.example.com` | SSH host name or IP |
| `DEPLOY_PORT` | `22` | optional, default 22 |
| `DEPLOY_USER` | `USER` | the Hestia user |
| `DEPLOY_PATH` | `/home/USER/web/studbook.example.com/public_html/studbook` | absolute path, no trailing slash |
| `DEPLOY_SSH_KEY` | `-----BEGIN OPENSSH PRIVATE KEY-----…` | private key of the deploy key pair |
| `DEPLOY_KNOWN_HOSTS` | output of `ssh-keyscan -p 22 hestia.example.com` | pins the host key |
| `DEPLOY_PHP` | `/usr/bin/php8.3` | optional; PHP CLI matching the domain's PHP version, default `php` |

## 3. What a deploy does

On every push to `main` (`.github/workflows/deploy.yml`):

1. runs the CI workflow (lint, translation check, tests);
2. installs Composer dependencies without dev packages;
3. `rsync --delete` to `DEPLOY_PATH`, excluding the paths in `.deployignore` (`.env`, `storage/`, tests, CI files);
4. runs `php bin/migrate` on the server.

## 4. First login

After the first deploy, create the login over SSH:

```bash
cd /home/USER/web/studbook.example.com/public_html/studbook
/usr/bin/php8.3 bin/create-user yourname
```

## 5. Backups

Hestia's built-in backup covers the database and the domain folder (including `.env` and uploads). Catalogue data can always be re-imported.
