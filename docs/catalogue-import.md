# Catalogue import

Studbook keeps a local copy of the parts catalogue. It is rebuilt by the importer from two sources:

| Source | What | How it gets to the server |
| --- | --- | --- |
| Rebrickable CSV downloads | parts, colours, categories, part relationships, elements, sets, minifigs, inventories | downloaded automatically, **at most once per 24 hours** |
| BrickLink catalogue download | BrickLink item numbers and colour IDs | you download it with your own BrickLink account and upload it |

Internal keys are Rebrickable IDs; the BrickLink numbers are matched locally and shown in the UI.

## 1. BrickLink files (once, and again when you want newer numbers)

1. Log in at bricklink.com, open **Catalog → Download** (`https://www.bricklink.com/catalogDownload.asp`).
2. Download two lists:
   - **Item type: Parts**, format **Tab-Delimited File** (or XML). Tick "Include alternate item numbers" if offered; it improves matching.
   - **Colors** list, Tab-Delimited File (or XML).
3. Upload both files to the BrickLink folder on the server: `BRICKLINK_FILES_PATH` in `.env`, by default `storage/catalog/bricklink/`. File names do not matter; the importer recognises the files by their columns (`Number`/`Name` for parts, `Color ID`/`Color Name` for colours). Other files in the folder are ignored and mentioned in the import log.

Never commit these files or share them: BrickLink's terms do not allow passing on downloaded data.

Without BrickLink files the import still works, but BrickLink numbers stay empty and the admin page shows a warning.

## 2. Cron job

One cron job handles both the weekly import and imports requested with **Run import now** on the admin page (*Catalogue*):

```
*/15 * * * * /usr/bin/php8.3 /path/to/studbook/bin/import --cron --quiet
```

In Hestia: *Cron jobs → Add cron job*, command as above (with your PHP version and path), minute `*/15`, everything else `*`.

On each call it:

- runs an import queued from the admin page, or
- runs a scheduled import when the last successful one is older than `IMPORT_SCHEDULE_DAYS` (default 7), or
- does nothing.

The admin page warns when the cron job has not run in the last 90 minutes.

## 3. Running an import by hand

```bash
php bin/import            # full import now, with progress output
php bin/import --quiet    # only errors
```

Only one import runs at a time (a database lock); a second one exits immediately.

## What an import does

1. **Download:** each Rebrickable file is fetched again only when the local copy in `CATALOG_DOWNLOAD_PATH` is older than 24 hours (`IMPORT_MIN_INTERVAL_HOURS`, never less than 24). If a download fails, the older local copy is used and a warning is logged.
2. **Load:** everything is written into `*_new` copies of the `cat_*` tables. The site keeps reading the current tables.
3. **Match BrickLink numbers:**
   - colours by name (Rebrickable mostly uses BrickLink's colour names), with a small alias table and the RGB value to break ties;
   - parts by identical number first, then via BrickLink's alternate item numbers. Ambiguous cases are not guessed.
4. **Sizes:** width, length and height (in plates) are parsed from part names of common families (Brick, Plate, Tile, Slope, Wedge, Panel). Unclear names get no size.
5. **Inventories:** for every set and minifig, the default inventory (lowest version) is flattened into `cat_inventory`, including parts of the minifigs in the set (`from_minifig = 1`) and of sub-sets in multi-packs.
6. **Swap:** all `*_new` tables replace the live ones in one atomic `RENAME TABLE`. If anything fails before this step, the previous catalogue stays untouched.

A full import takes about 1.5 minutes on a small server (about 65,000 parts, 28,000 sets, 1.7 million inventory rows) and needs roughly 300 MB of free database space while both copies exist.

## Report

The admin page shows, for the last successful import:

- the number of parts, colours, sets, minifigs and inventory rows;
- how many colours and parts got a BrickLink number (same number, or via an alternate number);
- the unmatched colours, and examples of unmatched parts, leaving out prints and stickers, which are expected not to match.

The full log of every run is stored in `import_run.log`.
