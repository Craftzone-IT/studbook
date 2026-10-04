# CLAUDE.md — Studbook

Guidance for Claude Code (and humans) working in this repository. Read this first, then `docs/`.

## What this is

Studbook is an open-source (AGPL-3.0), self-hosted PHP/MySQL web app for cataloguing a physical brick collection and finding out what can be built from it. One login per instance, several collections, no public registration.

Background, decisions and their reasons: `docs/decisions.md`. Data model: `docs/data-model.md`. Roadmap and issue specs: `docs/milestones.md`. Cloud development environment: `docs/cloud-dev.md`.

## Current status

- **Phase:** M0 installed and closed (#1). M1 runs on the host with the Rebrickable API (61,362/64,769 parts and 216/275 colours matched to BrickLink); #2 closes after the queued "Run import now" succeeds via cron. M2 merged (#13), to be tried on the host.
- **Done:** M0 (#8, #10, #14 Hestia layout), browser setup wizard (#9), M1 importer (#12) with Rebrickable API ids (#15), M2 collections and boxes (#13).
- **In progress:** per-page progress log for the API refresh (~17 s per page, ~20 min per refresh).
- **Next:** try M2 on the host; then M3 – Fast entry (#4).
- **Open questions:**
  - PHP on the maintainer's host is 8.3 (`/usr/bin/php8.3`); `proc_open` is disabled there (Composer warns, works).
  - Rebrickable API format confirmed on the host (2026-10-04); `rebrickable.com` is not reachable from the cloud dev environment unless added to its network allowlist.
  - BrickLink download format is confirmed (tab-delimited Parts with `Alternate Item Number`, Colors); "Include Year" must stay unticked.
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
- `main` is always deployable. Deployment is manual: the maintainer uploads from a local clone (Windows) over FTP into Hestia's `private/` (application) and `public_html/` (contents of `public/`), then runs `composer install --no-dev` over SSH as the Hestia user; database updates are applied on the `/setup` page or with `bin/migrate` (`docs/deploy-hestia.md`). There is no automatic deploy.
- Domain terms: **collection** (independent inventory), **storage/box** (physical container, always belongs to one collection), **owned set** (set kept as a unit: reference inventory + deltas), **loose lot** (part+colour+quantity in a box), **build** (target set being assembled), **allocation** (parts reserved for a build), **batch** (undo unit).
- Internal keys are Rebrickable IDs; the UI always shows BrickLink item numbers and colours.
