# Milestones

Each milestone becomes one or more GitHub issues; each issue becomes one PR. Acceptance criteria below are the issue bodies. Tick items off by closing the issue; keep this file as the plan, not the progress log (progress goes in `CLAUDE.md` → Current status).

Issues: one per milestone, Craftzone-IT/studbook#1 (M0) to #7 (M6), created on 2026-10-03.

---

## M0 – Skeleton

**Goal:** an empty but deployable, secure, bilingual app.

- [ ] Composer project, PSR-4 autoload (`Studbook\`), PSR-12, strict types, PHPUnit wired up.
- [ ] Front controller in `public/`, simple router, error handling (no stack traces in production).
- [ ] `.env` loading; `.env.example` documents every key; app refuses to start with missing required keys.
- [ ] Migration system: `bin/migrate` applies `migrations/NNNN_*.sql` in order and records them.
- [ ] Single-user login (password hash, session hardening, CSRF on all forms, login rate limiting). First user created via CLI (`bin/create-user`).
- [ ] All routes require login except `/login`; `X-Robots-Tag: noindex`; disallow-all `robots.txt`.
- [ ] i18n: `t('key', [...params])`, `lang/en.php` and `lang/hu.php`, English fallback, language chosen in a Settings page and stored in `setting`. Locale-aware date and number formatting.
- [ ] Layout: header, navigation, footer with Rebrickable attribution; responsive (desktop + 375 px phone).
- [ ] Health check: a CLI test that every key in `en.php` exists in `hu.php` and vice versa.
- [ ] GitHub Actions: lint + tests on PR and on `main`. Deployment is manual (FTP upload, then `composer install --no-dev` and `bin/migrate` over SSH), documented in `docs/deploy-hestia.md` (example for HestiaCP).

**Done when:** a fresh install from the README works, login works in both languages on desktop and phone, and the manual deployment in `docs/deploy-hestia.md` has been done once on the target host.

## M1 – Catalogue importer

**Goal:** a local, queryable catalogue with BrickLink numbers.

- [ ] Download Rebrickable CSV files (themes, colors, part_categories, parts, part_relationships, elements, sets, inventories, inventory_parts, inventory_sets, inventory_minifigs); never more than once per 24 h.
- [ ] Load BrickLink catalogue files from the configured folder (parts, colours; recognise files by their headers).
- [ ] Idempotent import into `cat_*` tables (temporary tables + swap, so the app keeps working during import).
- [ ] BL↔RB matching for parts and colours; mismatch report stored in `import_run.log` and shown in the admin area with counts.
- [ ] Parse width/length/height from part names for common families (Brick, Plate, Tile, Slope…); leave NULL otherwise.
- [ ] `bin/import` CLI (for cron) and an admin page with "Run import now", last runs and their status.
- [ ] Documentation: how to get the BrickLink files, cron example.

**Done when:** a full import finishes on the target host, and the report shows how many parts/colours have no BrickLink match.

## M2 – Collections and boxes

- [ ] Home page: collection cards (sets, loose parts count, boxes, last change) + "New collection".
- [ ] Collection CRUD: rename, archive, delete (empty only, or with confirmation as a revertible batch), `can_lend` flag.
- [ ] Box CRUD inside a collection; types; an "Inbox" box is created automatically per collection.
- [ ] Box labels: edit the list of part numbers on a box (validated against the catalogue, shown with BL numbers and images).
- [ ] Box page at `/b/{id}`: contents with images, quick actions (add here, take out, move).
- [ ] QR code generation (server-side, no external service) and a printable A4 label sheet (name, QR, part numbers).
- [ ] Image cache: fetch on first display, store under `storage/`, serve locally.

## M3 – Fast entry

- [ ] Box-based entry grid: only the box's labelled parts as tiles; part stays selected; colour picker limited to existing colours; quantity with +/− and keyboard input.
- [ ] Keyboard flow on desktop: no mouse needed for part → colour → quantity → next.
- [ ] Step-by-step picker: category → size → colour.
- [ ] Free-text search: part number, name, size patterns (`2x2`), colour names, EN/HU synonyms, typo tolerance.
- [ ] "Where does this go?" hint: when entering a part outside a labelled box, show which box(es) are labelled for it.
- [ ] Batches and undo: every entry session is a batch; recent batches listed with "Undo".

## M4 – Sets

- [ ] Add a set by number (with image and name preview); choose collection, state and lock.
- [ ] Record deltas (missing/extra parts) without editing the full list.
- [ ] Remove a set in one action (revertible).
- [ ] Disassemble a set into loose lots (choose target box(es)), one batch.
- [ ] Move sets, lots and whole boxes between collections.

## M5 – What can I build?

- [ ] Coverage calculation per target set: loose → lendable sets → missing, across selected collections.
- [ ] Part equivalence via `cat_part_rel` (configurable), optional colour substitution, ignore minifigures.
- [ ] Results list with filters (theme, year, part count) and sorting by coverage; must be fast enough to scan all sets (pre-computation or caching allowed).
- [ ] Builds and allocations: start a build, reserve parts, release or consume them.
- [ ] Pick list grouped by box; BrickLink wanted-list XML export of missing parts.

## M6 – Camera

- [ ] In-app QR scanner (opens `/b/{id}`).
- [ ] Photo upload of a box lid or paper list → server-side OCR (Tesseract) → candidate part numbers validated against the catalogue → user confirms → saved as box labels.
- [ ] Single-part photo → Brickognize → top candidates with images → user picks part and colour. Timeouts, error handling and fallback to search; feature can be disabled in `.env`.
- [ ] Record findings about OCR accuracy and the Brickognize response format in `docs/decisions.md`.
