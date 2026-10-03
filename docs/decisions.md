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
- Development on GitHub; code written by Claude Code; issue → PR → review → merge → automatic deploy via GitHub Actions.
