# Data model (draft)

Draft from planning. Column lists are indicative; the authoritative schema is whatever the migrations create. Update this document in the same PR as any schema change.

## Catalogue (imported, read-only)

Rebuilt by the importer; never edited by hand. Internal keys are Rebrickable IDs.

| Table | Purpose / key columns |
| --- | --- |
| `cat_part` | `rb_num` (PK), `bl_num`, `name`, `category_id`, parsed `width`, `length`, `height` (nullable) |
| `cat_part_category` | Rebrickable part categories |
| `cat_color` | `rb_id` (PK), `bl_id`, `name`, `rgb`, `is_trans` |
| `cat_part_color` | part × colour pairs that actually exist (from Rebrickable elements) — drives the colour picker |
| `cat_part_rel` | relationships between parts (print, mould, alternate, sub-part) — used for equivalence in "what can I build" |
| `cat_theme` | themes (hierarchy) |
| `cat_set` | `set_num` (PK), `name`, `year`, `theme_id`, `num_parts` |
| `cat_inventory` | set → part, colour, quantity, `is_spare` (minifig inventories flattened or linked — decide in M1) |
| `cat_image_cache` | part + colour → local file path, fetched-at; filled on first display |
| `i18n_color_name` | optional: language, colour id, translated name |
| `search_synonym` | optional: language, term, canonical term (e.g. `kocka` → `brick`, `piros` → `red`) |

BL↔RB matching: the importer fills `cat_part.bl_num` / `cat_color.bl_id` from the BrickLink catalogue files and writes a mismatch report (unmatched or ambiguous items) to the `import_run` log.

## Owned data

| Table | Purpose / key columns |
| --- | --- |
| `setting` | `key`, `value` — e.g. `ui_language` (default `en`) |
| `user` | single login (username, password hash, `last_login_at`) |
| `login_attempt` | failed logins (`ip`, `attempted_at`) for rate limiting; rows older than a day are purged |
| `schema_migration` | applied migrations (`version`, `name`, `checksum`, `applied_at`); created by `bin/migrate` |
| `collection` | `id`, `name`, `can_lend` (may "what can I build" borrow from it), `archived_at` |
| `storage` | `id`, `collection_id` NOT NULL, `name`, `type` (`large`, `small`, `jar`, `inbox`, `set_box`) |
| `storage_label` | `storage_id`, `part` — part numbers written on the box; source of the entry grid |
| `owned_set` | `id`, `collection_id`, `set_num`, `state` (`sealed`, `built`, `disassembled`), `lock` (`locked`, `lendable`), `storage_id` nullable |
| `owned_set_delta` | `owned_set_id`, `part`, `color`, `qty` (±) — only differences from the official inventory |
| `loose_lot` | `id`, `collection_id`, `storage_id`, `part`, `color`, `qty`, `source_set_id` nullable |
| `build` | `id`, `collection_id`, `set_num`, `state` (`planned`, `in_progress`, `done`) |
| `allocation` | `build_id`, `part`, `color`, `qty`, source: `loose_lot_id` or `owned_set_id` |
| `batch` | `id`, `created_at`, `description`, `reverted_at` |
| `import_run` | `id`, `started_at`, `finished_at`, `trigger` (`cron`, `manual`), `status`, `log` |

Every owned-data table carries a `batch_id`. Reverting a batch undoes all rows written in it (store enough information — e.g. a `batch_change` journal with before/after values — to revert updates and deletes, not only inserts).

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
