# Placement advice – "Where does it go?" (M7)

Implementation spec for milestone M7 (`docs/milestones.md`). Read `CLAUDE.md` and `docs/` first; all hard rules there apply (English in the repo, every UI string through `t()`, `en.php` + `hu.php` in the same PR, PSR-12, strict types, CSRF, login on every route, no data files in git).

## Goal

Type a part number, get the box it belongs in — also for parts that are not catalogued anywhere yet.

Today the only hint is `OwnedQueries::labelledBoxes()`: an exact match of the part number against `storage_label`, shown inside the add form and fast entry. A part that no box is labelled for (e.g. a first 1 × 3 tile) gets no advice at all. M7 adds a ranked advisor that falls back to "a box holding similar parts", plus a standalone page to ask it.

## Context: how boxes are organised

Boxes are sorted by part type, not by colour (`docs/decisions.md`, "Storage advice"). Each box carries a label list (`storage_label`) of the part numbers it holds; a new, still empty box gets a handful of seed labels describing its category (e.g. a tiles box labelled 3070b, 3069b, 63864, 2431, 6636, 4162, 3068b, 87079). Box names may start with a shelf number ("01 …", "K06 …" for jars), so name order is shelf order. The advisor therefore works from labels and the catalogue only — no per-box configuration.

Box names and seed labels are instance data. The maintainer applies them with a one-off SQL script outside the repo; never commit such scripts or their data (hard rule 3).

## No schema change

Everything is derived from existing tables: `storage`, `storage_label`, `loose_lot`, `cat_part` (`category_id`, `width`, `length`, `bl_num`), `cat_part_rel`, `cat_part_canon`, `cat_part_category`, `setting`. No migration.

## The advisor

New class `Studbook\Owned\PlacementAdvisor` (pure read queries, injected PDO, unit-tested against the test DB).

```php
/** @return list<array{box_id: int, name: string, type: string, reason: string, detail: array<string, string|int>}> */
public function advise(int $collectionId, string $rbNum, int $limit = 3): array
```

Input is an already resolved Rebrickable number (callers use `CatalogRepository::resolvePart()`, which accepts RB or BL numbers). Only non-Inbox boxes of the given collection are candidates. The result is ordered best first; the same box never appears twice (keep its best reason). Steps are evaluated in order; a box found by an earlier step always ranks above boxes from later steps.

| Step | Reason key | Rule | `detail` |
| --- | --- | --- | --- |
| 1 | `label` | The part number is on the box's label (exact, as `labelledBoxes`). | — |
| 2 | `label_variant` | Another part with the **same BrickLink number**, or in the same **mould/alternate group** (`cat_part_canon.variant`), is on the label (e.g. 3794b → box labelled 3794a). | `part` = the label part |
| 3 | `print_box` | The part is a **print or pattern** (it is the `child` of a `cat_part_rel` row with `rel_type` `P` or `T`) and the collection has a print box set (see Settings below). | — |
| 4 | `label_unprinted` | The part is a print/pattern and its unprinted parent (the `parent` of that relation, or anything in its `cat_part_canon.print` group) is on a label. | `part` = the label part |
| 5 | `stored` | Loose lots of this part (any colour) already sit in the box. | `qty` = total pieces |
| 6 | `similar` | The box's label lists parts of the **same `category_id`**. Rank by size distance to the nearest such label part (below), then by the number of same-category labels in the box (more wins), then by box name. | `part` = nearest label part |
| — | `no_box` | Nothing found: return an empty list; the page then suggests the collection's Inbox and names the part's category. | `category` |

**Size distance (step 6):** for parts where both `width` and `length` are known, compare them orientation-free: `|min(w₁,l₁) − min(w₂,l₂)| + |max(w₁,l₁) − max(w₂,l₂)|`. If either side has no dimensions (wheels, Technic, minifig parts), the distance is a constant larger than any real one (e.g. 99), so those boxes rank by label count and name only. `width`/`length` are `TINYINT UNSIGNED`: cast every operand to `SIGNED` before subtracting, otherwise MySQL raises "BIGINT UNSIGNED value is out of range".

Expected results for a typical box layout (verified against the maintainer's data; rebuild them as fixtures in the tests, with English box names):

| Input | Expected best box | Reason |
| --- | --- | --- |
| 3070b | tiles box (labelled 3070b) | `label` |
| 3794b | small plates box (labelled 3794a, 15573) | `label_variant` (same mould group) |
| 26603 Tile 2 × 3 | tiles box | `similar` (distance 1) |
| 3033 Plate 6 × 10 | large plates box (3030, 3036) | `similar` (distance 2), ahead of the 2 × 6–2 × 12 plates box (distance 4) |
| 4070 Brick 1 × 1 with headlight | brackets / studs-on-side jar (87087, 4733) | `similar` (Bricks Special, distance 0) |
| 30363 Slope 18° 4 × 2 | slopes box | `similar` |
| 2453b Brick 1 × 1 × 5 | small bricks box (3005) | `similar` |
| 32054 Technic pin long | Technic box | `similar` (no dimensions) |
| 32028 Plate 1 × 2 door rail | small plates box and clip plates jar tie at distance 0 → both shown, ordered by label count, then name | `similar` |

## Settings: print box per collection

Some collections keep printed and patterned parts in a separate box. On the box page (edit form) add a checkbox "Printed parts go here". Store it as `setting` key `print_box.{collection_id}` = box id; at most one box per collection (ticking it on another box moves it). Unticking deletes the key. Deleting or moving the box clears it. This is a setting, not owned data, so it is not batched (same as the UI language).

## UI

### Page `/where`

- Route `GET /where` (`WhereController`), login-protected like every page. Nav item `nav.where` placed after `nav.search`.
- Form: part number field (autofocus, `autocomplete="off"`, `inputmode="text"`) and, when there is more than one active collection, a collection select (default: the collection used last on this page, kept in the session; otherwise the first active one). Query parameters `part` and `c`, so results are linkable.
- Result, top to bottom:
  - part picture (existing image cache), BrickLink number + name, category;
  - **best box** large: box name (link to `/b/{id}`), reason sentence (e.g. "Labelled for 3794a", "Holds similar parts: Tile 1 x 2", "Already holds 12 pieces"), and a primary button **Add here** → existing `/b/{id}/add?part={rb_num}` (colour + quantity form);
  - up to two alternatives as a short list with the same reason text and their own *Add here* links;
  - `no_box`: "No box holds {category} parts yet" + *Add to Inbox* link.
  - Unknown number: the existing `box.part_unknown` style error, field kept with the input selected.
- Keyboard flow for sorting a pile: after a result, the field is filled with the last number and fully selected, so typing the next number replaces it; Enter submits. Works without JavaScript (plain GET form).
- Mobile: single column, the best box first and the *Add here* button reachable with a thumb.

### Entry points

- Home page: a compact "Where does it go?" field (same GET form) above the collection list.
- `/identify` (Brickognize) results: each candidate part gets a "Where does it go?" link to `/where?part=…`.
- `/search` results: same link per part.

### Existing hints use the advisor

Replace the `labelledBoxes()` hint in `BoxController::addForm()` and `EntryController::hint()` with `PlacementAdvisor::advise()`: if the current box is among the results, show nothing (as today); otherwise show the best result with its reason. Keep `labelledBoxes()` if still used elsewhere; otherwise remove it.

## Translations

Add to `lang/en.php` and `lang/hu.php` (wording is a suggestion; Hungarian must be natural):

| Key | en | hu |
| --- | --- | --- |
| `nav.where` | Where does it go? | Hova tegyem? |
| `where.title` | Where does it go? | Hova tegyem? |
| `where.part_label` | Part number (BrickLink or Rebrickable) | Alkatrészszám (BrickLink vagy Rebrickable) |
| `where.submit` | Find box | Keresés |
| `where.best` | Put it in | Ide tedd |
| `where.alternatives` | Also possible | Ide is mehet |
| `where.add_here` | Add here | Felvitel ide |
| `where.reason.label` | Labelled for this part | A doboz címkéjén szerepel |
| `where.reason.label_variant` | Labelled for {part} (same part, other number) | A címkén szerepel: {part} (ugyanaz az alkatrész, más szám) |
| `where.reason.print_box` | Printed parts go here | Mintás alkatrészek doboza |
| `where.reason.label_unprinted` | Labelled for the unprinted {part} | A címkén szerepel a minta nélküli {part} |
| `where.reason.stored` | Already holds {qty} pieces | Már van benne {qty} db |
| `where.reason.similar` | Holds similar parts: {part} | Hasonló alkatrészek vannak benne: {part} |
| `where.no_box` | No box holds {category} parts yet. | Még nincs doboz a(z) {category} kategóriának. |
| `where.add_inbox` | Add to Inbox | Felvitel az Inboxba |
| `where.link` | Where does it go? | Hova tegyem? |
| `box.print_box` | Printed parts go here | Ide kerülnek a mintás alkatrészek |

`{part}` is shown as the BrickLink number and name (UI shows BrickLink numbers, internal keys stay Rebrickable).

## Tests

- `tests/Owned/PlacementAdvisorTest.php` (DB test, fixtures in the style of `OwnedTestCase`): one test per step, plus ordering (earlier step beats later step, no duplicate boxes), Inbox never suggested, other collections ignored, unsigned-dimension subtraction (a smaller part next to a larger label must not error), parts without dimensions, tie order, print box setting set/unset.
- Page tests (`tests/Owned/WherePagesTest.php`): login required, unknown part, `no_box`, *Add here* link target, collection select only with more than one active collection, hints in add form and fast entry come from the advisor.
- `php bin/check-translations` passes.

## Out of scope

- Pasting a list of many numbers at once (possible follow-up: one result row per number).
- Suggesting splits or merges of boxes (`docs/decisions.md`, "Storage advice").
- Colour-based placement; the collection is sorted by shape.
- Any change to how labels are written (auto-labelling stays as it is).

## Acceptance criteria (mirrored in `docs/milestones.md`)

- [ ] `PlacementAdvisor` with the six steps above, size distance with signed arithmetic, Inbox excluded, collection-scoped; unit tests for each step.
- [ ] `/where` page with linkable `part`/`c` parameters, best box + up to two alternatives with reasons, *Add here* to the existing add form, `no_box` → Inbox, keyboard flow for repeated lookups, no JavaScript required.
- [ ] Entry points: home page field, nav item, links from `/identify` and `/search`.
- [ ] Add form and fast entry hints use the advisor.
- [ ] "Printed parts go here" checkbox on the box page, stored as `print_box.{collection_id}` in `setting`, cleared when the box is deleted or moved.
- [ ] All strings in `en.php` and `hu.php`; `docs/decisions.md` gets a short "Placement advice" entry (steps, why labels and categories instead of per-box rules); `docs/data-model.md` documents the `print_box.{collection_id}` setting; `CLAUDE.md` current status updated.
- [ ] `composer lint`, `php bin/check-translations`, `composer test` green; screenshots desktop + mobile in the PR.

**Done when:** on the maintainer's instance, typing 26603, 3033, 4070 and 3794b on `/where` suggests the tiles box, the large plates box, the studs-on-side jar and the small plates box respectively, and *Add here* lands on the right colour/quantity form.
