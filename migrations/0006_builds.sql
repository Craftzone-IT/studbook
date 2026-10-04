-- M5: what can I build.
-- cat_set.need_qty = parts a set needs (spares excluded, minifig parts included),
-- need_fig_qty = how many of those come from minifigs; filled by the importer and once here.
-- cat_part_canon maps parts to the representative of their equivalence group
-- (variant: moulds and alternates; print: also prints and patterns). Parts without
-- relationships have no row. Filled by the importer, or on first use.
-- build / allocation: target sets being assembled and the parts reserved for them.

ALTER TABLE cat_set ADD COLUMN need_qty INT NOT NULL DEFAULT 0, ADD COLUMN need_fig_qty INT NOT NULL DEFAULT 0;

-- The (part, colour) index now covers the columns coverage needs, so the scan never reads table rows.
ALTER TABLE cat_inventory DROP KEY idx_cat_inventory_part,
    ADD KEY idx_cat_inventory_part (part, color_id, is_spare, from_minifig, set_num, quantity);

UPDATE cat_set s
JOIN (
    SELECT set_num, SUM(quantity) AS n, SUM(CASE WHEN from_minifig = 1 THEN quantity ELSE 0 END) AS f
    FROM cat_inventory WHERE is_spare = 0 GROUP BY set_num
) x ON x.set_num = s.set_num
SET s.need_qty = x.n, s.need_fig_qty = x.f;

CREATE TABLE cat_part_canon (
    part VARCHAR(64) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    variant VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    print VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    KEY idx_cat_part_canon_variant (variant),
    KEY idx_cat_part_canon_print (print)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE build (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    collection_id INT UNSIGNED NOT NULL,
    set_num VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    state VARCHAR(16) NOT NULL,
    options TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    batch_id INT UNSIGNED NULL,
    KEY idx_build_collection (collection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE allocation (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    build_id INT UNSIGNED NOT NULL,
    part VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    color_id INT NOT NULL,
    qty INT UNSIGNED NOT NULL,
    loose_lot_id INT UNSIGNED NULL,
    owned_set_id INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    batch_id INT UNSIGNED NULL,
    KEY idx_allocation_build (build_id),
    KEY idx_allocation_lot (loose_lot_id),
    KEY idx_allocation_set (owned_set_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
