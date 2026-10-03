# Data model (draft)

Draft from planning. Column lists are indicative; the authoritative schema is whatever the migrations create. Update this document in the same PR as any schema change.

## Catalogue (imported, read-only)

Rebuilt by the importer (`docs/catalogue-import.md`); never edited by hand. Internal keys are Rebrickable IDs. Identifier columns use `utf8mb4_bin`. No foreign keys, because the importer swaps whole tables.

| Table | Purpose / key columns |
| --- | --- |
| `cat_part` | `rb_num` (PK), `name`, `category_id`, `material`, `bl_num` + `bl_match` (`exact` / `alternate` / NULL), parsed `width`, `length`, `height_plates` (nullable; a brick is 3 plates) |
| `cat_part_category` | Rebrickable part categories |
| `cat_color` | `rb_id` (PK), `name`, `rgb`, `is_trans`, `bl_id`, `bl_name` |
| `cat_part_color` | part × colour pairs that exist (from elements and inventories) with an image URL where known; drives the colour picker and the image cache (M2) |
| `cat_part_rel` | `rel_type` (P print, M mould, A alternate, B sub-part, R pair, T pattern), `child`, `parent` — equivalence in "what can I build" |
| `cat_theme` | themes (hierarchy via `parent_id`) |
| `cat_set` | `set_num` (PK), `name`, `year`, `theme_id`, `num_parts`, `img_url` |
| `cat_minifig` | `fig_num` (PK), `name`, `num_parts`, `img_url` |
| `cat_inventory` | flattened default inventory of every set and minifig: `set_num`, `part`, `color_id`, `is_spare`, `from_minifig`, `quantity`. Minifig parts are flattened into the set with `from_minifig = 1` (so "ignore minifigures" is a filter); sub-sets of multi-packs are flattened one level deep. |
| `cat_bl_part`, `cat_bl_color` | the user's BrickLink lists as loaded (number, name, category, alternates; colour id, name, RGB) |
| `cat_image_cache` | planned (M2): part + colour → local file path, fetched-at |
| `i18n_color_name` | optional: language, colour id, translated name |
| `search_synonym` | optional: language, term, canonical term (e.g. `kocka` → `brick`, `piros` → `red`) |

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
| `owned_set` | `id`, `collection_id`, `set_num`, `state` (`sealed`, `built`, `disassembled`), `lock_mode` (`locked`, `lendable`; `lock` is a reserved word), `storage_id` nullable — table exists since M2 for the home page counts; managed in M4 |
| `owned_set_delta` | planned (M4): `owned_set_id`, `part`, `color`, `qty` (±) — only differences from the official inventory |
| `loose_lot` | `id`, `collection_id`, `storage_id`, `part`, `color_id`, `qty`, `source_set_id` nullable. Adding the same part + colour to a box merges into one lot. |
| `build` | planned (M5): `id`, `collection_id`, `set_num`, `state` (`planned`, `in_progress`, `done`) |
| `allocation` | planned (M5): `build_id`, `part`, `color`, `qty`, source: `loose_lot_id` or `owned_set_id` |
| `batch` | `id`, `created_at`, `description_key` + `description_params` (translated when shown), `reverted_at` |
| `batch_change` | journal: `batch_id`, `table_name`, `row_id`, `action` (`insert`, `update`, `delete`), `before_data`, `after_data` (JSON rows) |
| `import_run` | `id`, `trigger_type` (`cli`, `cron`, `manual`), `status` (`queued`, `running`, `success`, `failed`), `requested_at`, `started_at`, `finished_at`, `log`, `stats` (JSON report) |
| `cat_image_cache` | `part`, `color_id` (−1 = any colour), `file`, `content_type`, `status` (`ok`, `missing`, `error`), `fetched_at` |

**Undo:** every owned-data table carries `batch_id` (the batch that last wrote the row). All writes go through `Studbook\Owned\Batch`, which also journals the row before and after in `batch_change`. Reverting a batch replays the journal newest first: inserted rows are deleted, updated rows restored, deleted rows re-inserted with their old id. A revert is refused when a later batch changed one of the rows (its `batch_id` is no longer this batch, or a deleted row exists again), so a revert never overwrites newer changes.

## Derived values

- **Available from an owned set** = official inventory − spares + deltas − allocations.
- **Free loose quantity** = `loose_lot.qty` − allocations.
- **What can I build** for a target set, layered:
  1. free loose parts (selected collections),
  2. parts from lendable owned sets in selected collections,
  3. missing → BrickLink wanted-list XML.
  Parts related through `cat_part_rel` count as equivalent where sensible (prints off by default). Filters: theme, year, part count. Options: colour substitution, ignore minifigures. Output includes a pick list grouped by box.

## Lifecycle operations

- **Disassemble a set:** convert the owned set's effective inventory into `loose_lot` rows (with `source_set_id`) in one batch.
- **Finish a build:** allocations are consumed; optionally create an `owned_set` in state `built`.
- **Move between collections:** a set, a lot or a whole box; one batch.
- **Delete a collection:** only when empty, or with confirmation as a revertible batch.
