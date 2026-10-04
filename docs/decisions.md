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

## Pictures without blocking the site (2026-10-04)

- **Problem found on the host:** a page with many pictures not cached yet made the whole site hang. Every `/img` request held the PHP session lock for its whole run (so the pictures of a page, and the next click, waited for each other) and downloaded from Rebrickable inside the request; with HTTP/2 the browser asks for many at once, which used up every PHP-FPM worker.
- **Light routes:** `/img`, the QR SVG and the small entry lookups release the session right after the login check and skip the page set-up (migration check, user, flash messages; image requests used to consume the flash message of the page).
- **At most three downloads at a time** (file locks under `IMAGE_CACHE_PATH/.locks`, released even if PHP dies), with 3 s connect / 6 s total timeouts. A request that finds no free slot gets a redirect to a "pending" picture at once (`no-store`); `assets/app.js` asks again up to five times with growing pauses. Pictures that do not exist get the plain placeholder, cached for a day.
- **Downloaded in advance by cron:** `bin/import --cron` (every 15 minutes) fetches up to `IMAGE_PREFETCH_PER_RUN` (default 200) missing pictures, one at a time with a short pause: loose lots, box labels, set pictures, then the contents of owned sets. It stops when the web pages are downloading.

## One BrickLink number, several Rebrickable parts (2026-10-04)

- **Problem found on the host:** entering BrickLink 3003 did not offer Trans-Dark Blue or Glitter Trans-Dark Pink. Rebrickable keeps transparent 2 x 2 bricks as a separate mould, 6223, which BrickLink lists as 3003.
- **Decision:** the colour list of a part covers all Rebrickable parts with the same BrickLink number (`CatalogRepository::colorsForPart`), and each colour carries the Rebrickable part that exists in it; that part is what gets stored (entry, "add here", set deltas). The chosen part keeps its own colours; otherwise the most used one wins. Search lists a BrickLink number once.
- Internal keys stay Rebrickable ids, so inventories and "what can I build" are unaffected (6223 and 3003 are also mould variants there).

## M6 camera (2026-10-04)

- **QR scanner** (`/scan`): the browser's `BarcodeDetector` where available (Chrome on Android), otherwise the bundled jsQR 1.4.0 (Apache-2.0, `public/assets/vendor/jsqr/`, loaded only then; no build step). Only the box number is taken from a code and the scanner always navigates within the site. The phone's own camera app opens `/b/{id}` as well; the scanner saves switching apps.
- **OCR: Tesseract on the server**, chosen by the maintainer. It runs through `exec()` (many hosts disable it; the Settings page and the photo page say so instead of failing), `--psm 11` (sparse text), English model, 30 s timeout. The photo is turned upright, scaled to 2400 px, greyscale with more contrast, and deleted right after reading.
- **OCR findings (cloud dev, Tesseract 5.3):** printed part numbers in a photo-like test image (tilted, coloured paper, JPEG) are read exactly, including suffixes (`3942c`, `30350b`, `3068bpr0001`). Handwriting is not tested yet: Tesseract is known to be weak on it, so every reading is confirmed by the user. Numbers are validated against the catalogue (Rebrickable or BrickLink), common look-alikes are corrected (O→0, I/l→1, S→5, B→8, …), sizes such as `2x4` and words are ignored, and numbers that stay unknown are offered in an editable field. To be checked with real handwritten lids on the host.
- **Brickognize findings (2026-10-04, `POST /predict/parts/`, multipart `query_image`):** the answer is `{listing_id, bounding_box, items[]}` with `items[] = {id, name, img_url, category, type, score, external_sites[]}`. `id` is the **BrickLink** item number (the external site is BrickLink). **No colour** is returned, so the colour is picked in the next step (entry page with the part preselected). A Rebrickable thumbnail of the part from our own catalogue is shown, not Brickognize's image. A test photo of 3001 was recognised as 3001 with score 0.84.
- **Brickognize use:** off by default (`BRICKOGNIZE_ENABLED`); answers cached per photo for 30 days under `STORAGE_PATH/cache`; 10 s timeout; any failure shows a message and the search instead. Results credit Brickognize. Its terms of service could not be read from the dev environment; the maintainer should read them before switching it on.
- **Uploads:** large photos are shrunk in the browser to at most 2400 px before upload (keeps under typical PHP limits). Server side: JPEG, PNG or WebP up to 15 MB, accepted only when `is_uploaded_file()` confirms them. The copy that is read or sent to Brickognize is re-encoded with GD, so no EXIF data (such as location) leaves the server; the upload itself is never stored.

## M5 what can I build (2026-10-04)

- **Which parts count:** the loose parts and unlocked (`lendable`) sets of the collection the build is for, plus those of other collections that allow lending (`collection.can_lend`) and are selected. Locked sets never count. Parts reserved by another build do not count.
- **Coverage** of a set = Σ over (part group, colour) of min(needed, available), first from loose parts, then from sets. Spares are never needed; minifigure parts optionally ignored. Each set is evaluated on its own; sets do not compete for parts in the scan (only builds reserve).
- **Interchangeable parts** come from Rebrickable's part relationships, grouped by union-find into `cat_part_canon`: "variants" (mould variants and alternates, the default) and "prints" (also printed/patterned versions count as the plain part). Exact matching is the third option. Pairs and sub-parts are never interchangeable.
- **Colour substitution** is informational: an extra percentage "with any colour"; builds only reserve the exact colour.
- **Performance without a search engine:** the available parts go into temporary tables; only inventory rows of those parts are read, through a covering `(part, color_id, is_spare, from_minifig, set_num, quantity)` index (replaces the old `(part, color_id)` index), and aggregated in PHP. Above 750k matching rows the whole inventory is streamed in primary-key order instead, so memory stays flat. On the dev catalogue (28k sets, 1.7M inventory rows) a scan takes 0.2–1 s; results are cached per options, owned-data version (latest batch, undo count) and catalogue version under `STORAGE_PATH/cache`.
- **Builds** reserve parts (`allocation`) per actual lot or set, loose parts first (boxes by name), then sets. "Reserve newly available parts" tops up later. The pick list is grouped by box and by set. Finishing takes the parts out (sets get "missing" deltas) and can add the model as a built set. Taking parts out of a reserved lot is not blocked; the pick list warns, and finishing takes what is left.
- **Wanted list:** BrickLink XML (`ITEMTYPE` P, BrickLink part and colour ids, `MINQTY`) for the missing parts of a set or a build; parts or colours without a BrickLink id are listed in an XML comment.
- **Results** default to sets with at least 25 parts and hide sets already owned; filters for theme (with sub-themes), years, part count and minimum coverage.

## M4 sets (2026-10-04)

- **One row per physical copy.** Adding "2 × 6000" creates two `owned_set` rows in one batch, so each copy can have its own state, box and missing parts.
- **States:** `sealed`, `built`, `disassembled` (taken apart but kept together, e.g. in its bags or box). Breaking a set up into loose parts is a separate action, not a state.
- **Spares** are listed but not counted in the set's contents (the build does not need them). Breaking up offers to add them as loose parts (on by default, since they are physically there).
- **Deltas** are entered as "missing" or "extra" with part, colour and quantity; changes to the same part and colour add up, a delta back at zero is deleted, and a set cannot miss more than its official quantity. Extra parts may be of any colour the part exists in.
- **Breaking up merges into ordinary lots** (`source_set_id` stays NULL): one lot per part and colour per box keeps entry, take-out and moving simple. The origin is in the batch history, and the whole break-up can be undone.
- **Parts go where they are labelled:** each part goes into the first box (by name) of the collection labelled for it, the rest into a chosen box (the Inbox by default).
- **Moving across collections:** lots can now move into any box of any active collection; a whole box moves with its labels, lots and the sets kept in it; a set that moves leaves its box (boxes belong to one collection). The Inbox never moves.
- **Set pictures** go through the same image cache as part pictures (`/img?set=…`, colour id −2 in `cat_image_cache`), from `cat_set.img_url`.

## M3 fast entry (2026-10-04)

- **One entry session = one batch** per box, kept in the PHP session and continued for 30 minutes of inactivity. Undoing it removes everything entered in that session; a new addition after an undo starts a new batch. Single-step changes elsewhere stay one batch each. `/history` lists the latest 50 batches with Undo.
- **Search runs in PHP + SQL, no search engine:** size patterns (`2x4`, either orientation, via the parsed `width`/`length`), part numbers (Rebrickable or BrickLink, exact or prefix, or a number in the name such as "45°"), colour phrases (longest match, BrickLink or Rebrickable name) and name words. Hungarian and variant words go through `search_synonym`; typing without accents works when the accentless form is unambiguous. Typos are corrected against the words of all part and colour names (optimal string alignment distance, 1 edit up to 5 letters, 2 above; ties go to the more frequent word); the vocabulary is cached per import run under `STORAGE_PATH/cache`. Results rank exact numbers first, then by `popularity`. A dedicated engine (MySQL FULLTEXT, Meilisearch) is not worth the hosting requirements at this catalogue size.
- **Keyboard flow:** part field → Enter → colour filter (preselected when the query named a colour) → Enter → quantity → Enter adds; the part stays selected and focus returns to the colour filter. Enter waits for the results of the full query, so fast typing does not pick a stale result. Esc goes back a step, `/` jumps to the part field. The colour filter understands Hungarian colour words.
- **Colours are listed by how often the part appears in that colour** in set inventories, so the likely colour is near the top.
- **"Where does this go?"**: when the part has a label in another box of the same collection, entry shows those boxes; the plain add form shows the same hint.
- **Step-by-step picker** (category → size → part, then the existing colour step) works without JavaScript; categories and parts with no set appearances are hidden from the category list.

## Rebrickable API for BrickLink ids (2026-10-04)

- **Why:** the first real import on the maintainer's host matched only 147 of 275 colours and 11,844 of 64,769 parts by number. Of the 61,469 parts that occur in set inventories, 50,203 had no BrickLink number: about 43,500 printed parts (numbered differently on both sites) and 6,644 mould variants (`3040b`↔`3040`, `3001a`↔`3001old`). Guessing these would show wrong numbers, which breaks the rule that the UI always shows BrickLink numbers.
- **Decision:** when `REBRICKABLE_API_KEY` is set, the importer reads the official BrickLink ids from the Rebrickable API (`lego/parts/?inc_part_details=1`, `lego/colors/`). It is an official, key-based API, not scraping (hard rule 6); requests are spaced one second apart, 429 responses are honoured, and the result is cached and refreshed at most once per day, like the CSV downloads. The key is the user's own and lives only in `.env`.
- **Fallbacks:** without a key, or when the API fails, matching uses the BrickLink files (and an older cached API result if one exists). The BrickLink files stay useful for BrickLink names and categories.
- Unmatched colours after name matching were almost all special ranges (HO, Modulex, Duplo, Clikits, Fabuland, glitter variants).

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

- ~~Rebrickable's API is not used for now.~~ Superseded 2026-10-04, see "Rebrickable API for BrickLink ids" below.
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
