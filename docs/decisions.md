# Decisions

Record of product and technical decisions made during planning (October 2026). Add new decisions at the end with a date; do not rewrite history — mark superseded decisions instead.

## Scope

- **Self-hosted, single user, several collections.** One login per instance. A "collection" is a freely created, independent inventory (e.g. "Mine", "Kid's", "For sale"). Not a fixed switcher in the header — collections are managed from the home page.
- **No public multi-user service.** Reasons: BrickLink's Terms of Service §8 forbid passing on data downloaded from BrickLink to third parties; the LEGO Fair Play policy restricts trademark use on public sites; EU sui generis database rights apply to bulk reuse of someone else's database. A private instance avoids all three.
- **Open source (AGPL-3.0).** The code is public; each installer downloads catalogue data themselves. The repository never contains data files.
- **Name: Studbook.** No "LEGO" in the name or logo (Fair Play). README carries the trademark disclaimer and Rebrickable attribution.
- **Not in scope (for now):** new/used condition, price tracking, weighing parts to count them, custom MOCs as build targets (the model allows adding them later).

## Catalogue data

- **Rebrickable CSV downloads** are the content source: parts, colours, part categories, elements (part+colour pairs that exist), part relationships (prints, moulds, alternates), sets, inventories. Updated daily by Rebrickable; automated download allowed at most once per day. Scraping is forbidden.
- **BrickLink catalogue download** (tab-delimited, free member account) provides BrickLink item numbers and colour IDs. The installer downloads it with their own account. The BrickLink API is not used (sellers only); BrickLink pages are never scraped.
- **Internal key = Rebrickable ID**, because inventories are keyed that way. **UI always shows BrickLink numbers and colours.** BL↔RB matching is done locally; the importer reports unmatched items.
- **Import schedule:** weekly cron, plus a manual "Run import now" button in the admin area. Each run is logged (`import_run`).
- **Images:** fetched on first display and cached locally (Rebrickable/LDraw images; Rebrickable's terms allow use on external sites).

## UI

- **Desktop first, fully responsive.** The main data entry flow is optimised for keyboard use on a PC; every screen must also work well on a phone, because the camera features (QR scanning, OCR, part photos) are used on mobile.
- **Bilingual from day one:** English (default) and Hungarian, chosen in Settings and stored in the `setting` table. Catalogue names stay in English; optional tables provide Hungarian colour names and search synonyms.
- **Search:** step-by-step picker (category → size → colour; size parsed from catalogue names, fallback to search for irregular parts) and free-text search (`3001 red`, `2x2 piros brick`).

## Entry model

- **A set is one unit:** stored as a reference to the official inventory plus deltas (missing/extra). Removing it is one action.
- **Box-based entry:** a box has a label listing part numbers (`storage_label`); the entry grid shows only those parts. Part stays selected, only the colour changes; colour list limited to colours the part exists in.
- **Labels from photos:** box lids and paper lists with handwritten part numbers are photographed; server-side OCR extracts numbers and validates them against the catalogue (typos, suffixed variants such as `3942c`, `30350b`, printed parts `…pb…`).
- **Unsorted parts:** search plus optional photo recognition (Brickognize); the app tells you which box a part belongs in. An "Inbox" box always exists.
- **Storage advice:** sort by part type, not by colour; only easily distinguishable parts share a box. After entry the app suggests splitting/merging boxes.
- **Undo:** every write carries a `batch_id`; any batch can be reverted.

## QR labels

- QR encodes a URL with the box ID only: `{APP_URL}/b/{box_id}`. Contents live in the database, so labels never need reprinting when contents change.
- URL form so the phone's native camera opens it; the in-app scanner extracts the ID from the same URL.
- Plain numeric IDs are fine because every page requires login.
- Box page: contents with images and BrickLink numbers, reservations marked, quick actions (add to this box, take out, move).
- Printable A4 label sheet: box name, QR code, part numbers written on the box (for humans).

## Brickognize (checked 2026-10-03)

- `POST https://api.brickognize.com/predict/` (multipart field `query_image`); sets: `/predict/sets/`. Docs: https://api.brickognize.com/docs
- No API key in public examples. The author has said it stays free for casual use and paid accounts are planned. No formal terms or rate limits found → call only on explicit user action, one request per photo, cache results.
- Returns item IDs with confidence scores; colour probably not returned → user picks colour from the part's existing colours.
- Has had outages (HTTP 500 for hours on 2026-09-28) → show an error and fall back to search; never block entry.
- Optional and switchable in `.env`.

## Hosting (maintainer's instance)

- HestiaCP, PHP + MySQL, behind an NPMplus reverse proxy, Let's Encrypt HTTPS.
- Login on every page, no extra protection layer. `noindex` + disallow-all `robots.txt`.
- Backups: HestiaCP's built-in backup is sufficient (catalogue data can always be re-imported).
- Development on GitHub; code written by Claude Code; issue → PR → review → merge. ~~Automatic deploy via GitHub Actions.~~ Superseded 2026-10-03: deployment is manual (see below).

## Hosting layout (2026-10-04)

- **Two supported layouts, detected by `public/index.php`:** the standard one (web root = `<app>/public/`) and the split one used by HestiaCP without changing the document root (application in `<domain>/private/`, contents of `public/` in `<domain>/public_html/`). The front controller looks for the application in `../` and `../private/`. No path is configured anywhere, so nothing instance-specific enters the code. Supersedes the earlier `v-change-web-domain-docroot` instructions.
- **Install errors never show server paths** to visitors; the details go to the PHP error log.
- **Commands run as the Hestia user** (`sudo -u USER -H`), never as root, so the web server can manage `vendor/`, `.env` and `storage/`. `bin/create-user` falls back to a visible password prompt when the host disables `shell_exec`.

## M2 collections and boxes (2026-10-03)

- **Undo journal:** every write goes through a batch that stamps `batch_id` and journals before/after rows (`batch_change`). Revert works newest first and is refused if a later batch touched the same rows, instead of silently overwriting newer data. Collection delete and box delete are ordinary batches, so "delete with confirmation" can be undone right away from the message.
- **Deleting a box moves its contents to the collection's Inbox**; the Inbox cannot be deleted. Nothing is lost by deleting a box.
- **Moving lots between collections** is left for M4 (one batch with sets and boxes); in M2 lots move between boxes of the same collection.
- **QR codes:** `chillerlan/php-qrcode` (MIT/Apache-2.0) renders SVG on the server; first runtime Composer dependency, because writing a QR encoder is not worth it. The QR encodes `{APP_URL}/b/{id}`.
- **Images:** fetched server-side on first display from the URL in `cat_part_color` (only `https://*.rebrickable.com`, ≤ 2 MB, image types only), stored under `IMAGE_CACHE_PATH`, served by `/img` behind the login. Missing images are retried after a week, temporary failures after an hour; a neutral placeholder is shown meanwhile.
- **Strict CSP stays:** colour swatches are tiny inline SVGs (`fill` attribute), not inline `style`, so `style-src` needs no `unsafe-inline`.
- **"Add here" without JavaScript:** part number (or a label tile) → colour page with only the colours the part exists in → quantity. The keyboard-optimised entry grid is M3.

## M1 catalogue import (2026-10-03)

- **No BrickLink IDs in Rebrickable's CSVs.** The downloads carry no external IDs, so BL numbers are matched locally: colours by normalised name (Rebrickable uses BrickLink's names almost everywhere), parts by identical number and then BrickLink's alternate item numbers. Ambiguous matches are not guessed. Prints usually stay unmatched; showing the BL number of the unprinted parent is left for later. Rebrickable's API would give exact external IDs but needs a per-user key; not used for now.
- **Inventories:** default inventory = lowest version. Minifig parts are flattened into the set with `from_minifig = 1`; sub-sets of multi-packs are flattened one level deep.
- **Heights are stored in plates** (`height_plates`), so bricks, plates and fractional bricks (2/3, 1 1/3) are whole numbers.
- **Imports run from cron only.** "Run import now" queues a run; `bin/import --cron` (every 15 minutes) runs queued imports and the weekly one. A web request would hit PHP time limits on shared hosting (an import takes ~1.5 minutes). The admin page warns when the cron job is not running.
- **Atomic swap:** `*_new` tables + one `RENAME TABLE`; no foreign keys on catalogue tables.

## M0 implementation (2026-10-03)

- **No runtime dependencies** besides PHP extensions: own small router, `.env` parser and migrator instead of a framework. Fewer moving parts on shared hosting; dev dependencies are PHPUnit and PHP_CodeSniffer only.
- **`ext-intl` is optional.** Dates and numbers use `IntlDateFormatter`/`NumberFormatter` when available and a small built-in table (en, hu) otherwise, so a host without intl still works.
- **Login rate limiting** is stored in the database (`login_attempt`): 5 failures per IP and 30 in total per 15 minutes. Behind a reverse proxy (NPMplus) the client IP comes from `X-Forwarded-For` only when the peer is listed in `TRUSTED_PROXIES`.
- **Session cookies** are `Secure` when `APP_URL` is `https://` (not detected from the request, which is unreliable behind a proxy). Sessions end after 7 days without activity.
- **Migrations:** one statement per `;` at the end of a line; applied migrations are checksummed and an edited one stops `bin/migrate`.
- ~~**Deploy** is rsync over SSH from GitHub Actions.~~ Superseded the same day: the maintainer prefers to upload by hand.
- **Browser setup (2026-10-03, #9):** `/setup` runs environment checks, migrations and creates the first login, so a first install needs only FTP, `.env` and `composer install`. Before a login exists it is protected by `SETUP_TOKEN` from `.env` (≥ 16 characters, compared with `hash_equals`, guesses rate-limited once the tables exist); without a token it only explains how to set one. Once a login exists, creating users there is impossible and pending migrations require being logged in; logged-in users are redirected to `/setup` while migrations are pending. The page returns 404 when nothing needs doing. The CLI scripts stay as alternatives.
- **Manual deployment (2026-10-03):** the maintainer uploads from a local clone (Windows) over FTP and runs `composer install --no-dev` and `php bin/migrate` on the server over SSH, so no PHP tooling is needed on the desktop and `vendor/` is never uploaded. GitHub Actions only runs CI (lint, translation check, tests) on PRs and on `main`. Reasons: full control over when the live site changes, no deploy credentials stored on GitHub.
- **Footer links the source code** (`APP_SOURCE_URL`) because AGPL-3.0 requires offering the source to users of a modified network version.
