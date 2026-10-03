# CLAUDE.md — Studbook

Guidance for Claude Code (and humans) working in this repository. Read this first, then `docs/`.

## What this is

Studbook is an open-source (AGPL-3.0), self-hosted PHP/MySQL web app for cataloguing a physical brick collection and finding out what can be built from it. One login per instance, several collections, no public registration.

Background, decisions and their reasons: `docs/decisions.md`. Data model: `docs/data-model.md`. Roadmap and issue specs: `docs/milestones.md`. Cloud development environment: `docs/cloud-dev.md`.

## Current status

- **Phase:** M0 and M1 code merged; both wait for verification on the host (#1, #2). M2 in progress. Issues exist for every milestone (#1 M0 … #7 M6) plus #9 (setup wizard, done).
- **Done:** M0 code (#8, #10), browser setup wizard (#9), M1 catalogue importer code (#12).
- **In progress:** M2 – Collections and boxes (#3): collection cards and CRUD (archive, delete as revertible batch), boxes with Inbox, box labels, box page `/b/{id}` with add/take out/move, QR label sheet, image cache, undo journal (`batch` / `batch_change`) with an Undo button in messages.
- **Next:** first install on the HestiaCP host (closes #1); real BrickLink files to check matching (#2); then M3 – Fast entry (#4), which adds the batch list and keyboard entry.
- **Open questions:**
  - PHP version on the maintainer's HestiaCP host (code targets 8.2+; CI tests 8.2 and 8.3).
  - Hestia custom document root for `public/` — confirm the `v-change-web-domain-docroot` approach in `docs/deploy-hestia.md` on the real host.
  - BrickLink catalogue download format: the reader accepts tab-delimited and XML and recognises files by columns (`Number`/`Name`, `Color ID`/`Color Name`), but has not seen a real file yet. Check with the maintainer's download and adjust `src/Catalog/BrickLinkCatalog.php` if needed.
  - How many non-print parts stay unmatched with real BrickLink data; whether a mould/print fallback via `cat_part_rel` is worth it.
  - Server-side OCR engine (Tesseract first; test on handwritten part numbers in M6).
  - Brickognize response format: which ID system, whether colour is returned (test call in M6).

## Hard rules

1. **English everywhere** in the repo: code, identifiers, comments, commit messages, PR descriptions, docs.
2. **No hard-coded UI text.** Every user-facing string goes through `t('key')`. Translation files per language (`lang/en.php`, `lang/hu.php`); English is the default and the fallback for missing keys. Add every new key to both files in the same PR.
3. **No data files in git.** Never commit Rebrickable CSVs, BrickLink catalogue files, cached images, database dumps or user uploads. They live under paths configured in `.env` and listed in `.gitignore`.
4. **Nothing instance-specific in code.** Domain, paths, DB credentials, feature toggles come from `.env` (`.env.example` documents every key). Deployment examples for HestiaCP go in `docs/`, never in code.
5. **Everything is login-protected.** All routes except the login page require authentication — including QR box pages (`/b/{id}`). Send `X-Robots-Tag: noindex` and serve a disallow-all `robots.txt`.
6. **Respect data providers.** Rebrickable downloads at most once per day (scheduled import runs weekly). Never scrape Rebrickable or BrickLink web pages. External calls (images, Brickognize) are server-side, cached, and must fail gracefully.
7. **Trademarks.** Never use "LEGO" in the product name, logo or as a noun-brand in UI copy; never ship the LEGO logo.
8. **Undo-able writes.** Every user-initiated change to owned data is written with a `batch_id` so it can be reverted as one unit.

## Stack and conventions

- PHP 8.2+, plain PHP with Composer autoloading (PSR-4, namespace `Studbook\`), no full-stack framework.
- MySQL 8 / MariaDB via PDO, prepared statements only, `utf8mb4`.
- Schema changes only through numbered migration files (`migrations/NNNN_description.sql`) run by a migration command; never edit an applied migration.
- Server-rendered HTML with progressive enhancement; small vanilla JS modules, no build step unless clearly justified. Mobile-first CSS, but optimise the desktop flow for keyboard-only entry.
- Security: CSRF tokens on all POST forms, `password_hash`/`password_verify`, session cookies `HttpOnly` + `Secure` + `SameSite=Lax`, output escaped by default.
- Tests: PHPUnit for domain logic (importer mapping, coverage calculation, undo). Keep tests runnable without network access.
- Code style: PSR-12. Strict types in every PHP file.

Directory layout (adjust if a better reason appears and document it):

```
public/        web root (index.php front controller, assets)
src/           application code (Studbook\…)
lang/          translation files
migrations/    SQL migrations
bin/           CLI entry points (migrate, import)
tests/         PHPUnit tests
docs/          project documentation
storage/       runtime data: catalogue files, image cache, uploads (git-ignored)
templates/     PHP view templates (escape with e(), translate with t())
scripts/       development helpers (cloud session setup)
```

Checks before every push: `composer lint`, `php bin/check-translations`, `composer test` (DB tests need `STUDBOOK_TEST_DB_*`, set automatically in cloud sessions).

## Workflow

- One issue → one branch → one PR. Keep PRs focused on a single milestone item.
- PR description: what and why, how it was tested, screenshots for UI changes (desktop + mobile).
- `main` is always deployable. Deployment is manual: the maintainer uploads from a local clone (Windows) over FTP, then runs `composer install --no-dev` and `php bin/migrate` on the server over SSH (`docs/deploy-hestia.md`). There is no automatic deploy.
- Domain terms: **collection** (independent inventory), **storage/box** (physical container, always belongs to one collection), **owned set** (set kept as a unit: reference inventory + deltas), **loose lot** (part+colour+quantity in a box), **build** (target set being assembled), **allocation** (parts reserved for a build), **batch** (undo unit).
- Internal keys are Rebrickable IDs; the UI always shows BrickLink item numbers and colours.
