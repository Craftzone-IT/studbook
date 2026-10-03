# Studbook

A self-hosted web app for cataloguing your brick collection — sets, loose parts and the boxes they live in — and for answering the question **"what can I build from what I already have?"**

> **Status:** early development. The skeleton (login, settings, bilingual UI) works; cataloguing features are not there yet. See [docs/milestones.md](docs/milestones.md) for the roadmap.

## Why

Existing tools are great for buying and selling, but cataloguing a real, physical collection is slow:

- adding a set by mistake means deleting its parts one by one;
- finding "a red 2x2 brick" in a huge catalogue is painful;
- nothing knows which physical box a part is sitting in.

Studbook is built around how a collection is actually stored: boxes labelled with part numbers, sets kept whole or taken apart, and a pile of unsorted pieces.

## Planned features

- **Box-based entry** — pick a box (or scan its QR code), see only the parts written on its lid, tap part → colour → quantity. The part stays selected so you only switch colours.
- **Sets as a single unit** — add a set by number, record only the differences (missing/extra parts), remove it in one click. Every change is batched and can be undone.
- **Step-by-step part picker** (category → size → colour) plus free-text search (`3001 red`, `2x2 red brick`).
- **Collections** — keep several independent collections (e.g. yours and your kid's), search across all of them, move items between them.
- **QR labels** — each box gets a printable label whose QR code opens the box's live contents.
- **"What can I build?"** — coverage of any official set from loose parts first, then from sets you allow borrowing from; missing parts exported as a BrickLink wanted-list XML; per-box pick list.
- **Camera features (optional)** — read handwritten box labels and part lists with server-side OCR; identify single parts from a photo via the [Brickognize](https://brickognize.com) API.
- **Bilingual UI** — English (default) and Hungarian, switchable in Settings. More languages welcome.

## Self-hosted, single user

Studbook is meant to run as **your own private instance**: one login, no public registration. It is not designed to be offered as a public service — the catalogue data it uses comes with licence terms that make that a bad idea (see below).

## Catalogue data

This repository contains **code only**. No catalogue data, images or downloads are included. After installing, you fetch the data yourself:

| Source | What it provides | How |
| --- | --- | --- |
| [Rebrickable](https://rebrickable.com/downloads/) | parts, colours, categories, sets, inventories, part relationships | Free CSV downloads, fetched automatically (at most once a day, per Rebrickable's rules) |
| [BrickLink](https://www.bricklink.com/catalogDownload.asp) | BrickLink item numbers and colour IDs | Catalogue download with **your own** free BrickLink account; place the files in the configured folder |

Please respect each provider's terms: do not scrape their websites and do not redistribute their data files.

Part data provided by [Rebrickable](https://rebrickable.com). Part recognition (optional) by [Brickognize](https://brickognize.com).

## Requirements

- PHP 8.2+ with `pdo_mysql` and `mbstring`, and Composer
- MySQL 8 / MariaDB 10.6+
- A cron job for the weekly catalogue import
- HTTPS (browsers only allow camera access on secure origins)
- Optional: Tesseract OCR on the server for the camera features
- Recommended: the PHP `intl` extension (locale-aware dates and numbers)

## Installation

1. Get the code and install dependencies:

   ```bash
   git clone https://github.com/Craftzone-IT/studbook.git
   cd studbook
   composer install --no-dev
   ```

2. Create an empty MySQL/MariaDB database (`utf8mb4`) and a user for it.
3. Copy `.env.example` to `.env` and fill it in. Every key is documented there; the app refuses to start when a required key is missing.
4. Create the tables and the single login:

   ```bash
   php bin/migrate
   php bin/create-user yourname     # asks for the password (min. 10 characters)
   ```

5. Point the web server's document root to `public/` (all other folders must not be web-accessible). Requests that are not existing files go to `public/index.php`; `public/.htaccess` does this for Apache, nginx needs `try_files $uri /index.php?$query_string;`.
6. Open the site, log in, and choose the language under **Settings**.

Run `php bin/migrate` again after every update. A forgotten password is reset with `php bin/create-user yourname --reset-password`.

For automatic deployment to a HestiaCP server with GitHub Actions, see [docs/deploy-hestia.md](docs/deploy-hestia.md).

### Local development

```bash
composer install
cp .env.example .env   # set APP_ENV=development, APP_URL=http://localhost:8080 and DB credentials
php bin/migrate && php bin/create-user dev
php -S localhost:8080 -t public public/index.php
```

Checks: `composer lint`, `php bin/check-translations`, `composer test`. Database tests run when `STUDBOOK_TEST_DB_DSN`, `STUDBOOK_TEST_DB_USER` and `STUDBOOK_TEST_DB_PASSWORD` point to a disposable test database (all its tables are dropped). Claude Code cloud sessions set this up automatically, see [docs/cloud-dev.md](docs/cloud-dev.md).

## Contributing

Issues and pull requests are welcome. All code, comments, commits and docs are in English. UI strings go through the translation system — see [CLAUDE.md](CLAUDE.md) for conventions.

## Licence

[GNU Affero General Public License v3.0](LICENSE). If you run a modified version of Studbook on a server that others can access, you must make your modified source available to them.

## Trademarks

LEGO® is a trademark of the LEGO Group, which does not sponsor, authorize or endorse this project. BrickLink® is a trademark of the LEGO Group. Rebrickable and Brickognize are the property of their respective owners. Studbook is an independent fan project.
