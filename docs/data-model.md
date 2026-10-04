# Data model (draft)

Draft from planning. Column lists are indicative; the authoritative schema is whatever the migrations create. Update this document in the same PR as any schema change.

## Catalogue (imported, read-only)

Rebuilt by the importer (`docs/catalogue-import.md`); never edited by hand. Internal keys are Rebrickable IDs. Identifier columns use `utf8mb4_bin`. No foreign keys, because the importer swaps whole tables.

| Table | Purpose / key columns |
| --- | --- |
| `cat_part` | `rb_num` (PK), `name`, `category_id`, `material`, `bl_num` + `bl_match` (`api` / `exact` / `alternate` / NULL), parsed `width`, `length`, `height_plates` (nullable; a brick is 3 plates), `popularity` (number of sets, not minifigs, the part appears in; ranks search and picker results) |
| `cat_part_category` | Rebrickable part categories |
| `cat_color` | `rb_id` (PK), `name`, `rgb`, `is_trans`, `bl_id`, `bl_name` |
| `cat_part_color` | part × colour pairs that exist (from elements and inventories) with an image URL where known; drives the colour picker and the image cache (M2) |
| `cat_part_rel` | `rel_type` (P print, M mould, A alternate, B sub-part, R pair, T pattern), `child`, `parent` — equivalence in "what can I build" |
| `cat_part_canon` | `part`, `variant`, `print`: representative of the part's equivalence group (union of M + A relationships; with P + T added for `print`), the most popular member. Only parts in a group have a row. Built by the importer, or on first use after migration 0006 |
| `cat_theme` | themes (hierarchy via `parent_id`) |
| `cat_set` | `set_num` (PK), `name`, `year`, `theme_id`, `num_parts`, `img_url`, `need_qty` (parts needed: spares excluded, minifig parts included), `need_fig_qty` (of those, from minifigs) |
| `cat_minifig` | `fig_num` (PK), `name`, `num_parts`, `img_url` |
| `cat_inventory` | flattened default inventory of every set and minifig: `set_num`, `part`, `color_id`, `is_spare`, `from_minifig`, `quantity`. Minifig parts are flattened into the set with `from_minifig = 1` (so "ignore minifigures" is a filter); sub-sets of multi-packs are flattened one level deep. |
| `cat_bl_part`, `cat_bl_color` | the user's BrickLink lists as loaded (number, name, category, alternates; colour id, name, RGB) |
| `cat_image_cache` | planned (M2): part + colour → local file path, fetched-at |
| `i18n_color_name` | optional: language, colour id, translated name |
| `search_synonym` | `term` (PK, `utf8mb4_bin` so `kerek` and `kerék` stay different), `canonical` (one or more English words), `language`; seeded by migration 0004 (e.g. `kocka` → `brick`, `kékesszürke` → `bluish gray`). Not touched by the importer. |

BL↔RB matching: the importer fills `cat_part.bl_num` / `cat_color.bl_id` from the BrickLink catalogue files and writes the counts and unmatched examples to `import_run.stats` (shown on the admin page).

## Owned data

| Table | Purpose / key columns |
| --- | --- |
| `setting` | `key`, `value` — e.g. `ui_language` (default `en`), `import_cron_seen_at` |
| `user` | single login (username, password hash, `last_login_at`) |
| `login_attempt` | failed logins (`ip`, `attempted_at`) for rate limiting; rows older than a day are purged |
| `schema_migration` | applied migrations (`version`, `name`, `checksum`, `applied_at`); created by `bin/migrate` |
| `collection` | `id`, `name`, `can_lend` (may "what can I build" borrow from it), `archived_at`, `created_at`, `updated_at` |
| `storage` | `id`, `collection_id`, `name`, `type` (`large`, `small`, `jar`, `set_box`, `inbox`); one `inbox` per collection, created with it, never deleted |
| `storage_label` | `id`, `storage_id`, `part` (Rebrickable number), `position` — part numbers written on the box; source of the quick-pick tiles and the printed label |
| `owned_set` | `id`, `collection_id`, `set_num`, `state` (`sealed`, `built`, `disassembled` = taken apart but kept together as a unit), `lock_mode` (`locked`, `lendable`; `lock` is a reserved word), `storage_id` nullable (box it is kept in, same collection), `created_at`. One row per physical copy. |
| `owned_set_delta` | `owned_set_id`, `part`, `color_id`, `qty` (negative = missing, positive = extra); unique per set, part and colour — only differences from the official inventory |
| `loose_lot` | `id`, `collection_id`, `storage_id`, `part`, `color_id`, `qty`, `source_set_id` nullable (unused so far, see `docs/decisions.md`, M4). Adding the same part + colour to a box merges into one lot. |
| `build` | `id`, `collection_id` (the collection it is built for), `set_num`, `state` (`active`, `done`), `options` (JSON: collections used, equivalence mode, minifigs, colour substitution), `created_at` |
| `allocation` | `build_id`, `part` (the actual part reserved, may be an equivalent), `color_id`, `qty`, source: `loose_lot_id` or `owned_set_id`. Only active builds have allocations |
| `batch` | `id`, `created_at`, `description_key` + `description_params` (translated when shown), `reverted_at` |
| `batch_change` | journal: `batch_id`, `table_name`, `row_id`, `action` (`insert`, `update`, `delete`), `before_data`, `after_data` (JSON rows) |
| `import_run` | `id`, `trigger_type` (`cli`, `cron`, `manual`), `status` (`queued`, `running`, `success`, `failed`), `requested_at`, `started_at`, `finished_at`, `log`, `stats` (JSON report) |
| `cat_image_cache` | `part`, `color_id` (−1 = any colour), `file`, `content_type`, `status` (`ok`, `missing`, `error`), `fetched_at` |

**Entry sessions:** fast entry (M3) writes all additions to one box into one batch (`description_key` `batch.entry_session`, params `box`, `box_id`, `lots`, `parts`) until the user leaves the box for 30 minutes or the session is undone; `BatchService::append` adds to it under a row lock and refuses a reverted batch.

**Undo:** every owned-data table carries `batch_id` (the batch that last wrote the row). All writes go through `Studbook\Owned\Batch`, which also journals the row before and after in `batch_change`. Reverting a batch replays the journal newest first: inserted rows are deleted, updated rows restored, deleted rows re-inserted with their old id. A revert is refused when a later batch changed one of the rows (its `batch_id` is no longer this batch, or a deleted row exists again), so a revert never overwrites newer changes.

## Derived values

- **Contents of an owned set** = official inventory (minifig parts included, spares not) + deltas.
- **Available from an owned set** = contents − allocations; only for `lendable` sets of the build's collection, or of other selected collections that may lend (`can_lend`).
- **Free loose quantity** = `loose_lot.qty` − allocations (never below 0).
- **What can I build** for a target set, layered:
  1. free loose parts (selected collections),
  2. parts from lendable owned sets in selected collections,
  3. missing → BrickLink wanted-list XML.
  Parts related through `cat_part_rel` count as equivalent where sensible (prints off by default). Filters: theme, year, part count. Options: colour substitution, ignore minifigures. Output includes a pick list grouped by box.

## Lifecycle operations

- **Break up a set:** its contents (optionally plus spares) become loose lots, each part into the first box labelled for it or a chosen box, merged with existing lots; the set and its deltas are removed. One batch.
- **Finish a build:** reserved loose parts are taken out of their lots, parts reserved from sets are recorded there as missing (deltas), allocations are deleted, the build becomes `done`; optionally an `owned_set` (built, locked) is created. One batch. **Cancelling** deletes the build and its allocations (one batch).
- **Move between collections:** a set (leaves its box), a lot (into any box of any collection), or a whole box with its labels, lots and the sets kept in it (not the Inbox); one batch.
- **Delete a collection:** only when empty, or with confirmation as a revertible batch.
