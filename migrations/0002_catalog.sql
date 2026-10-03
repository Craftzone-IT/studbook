-- Catalogue tables (rebuilt by the importer, never edited by hand) and import runs.
-- Identifiers use binary collation: part numbers like "3001a" and "3001A" must not collide.
-- No foreign keys: the importer swaps whole tables with RENAME TABLE.

CREATE TABLE cat_color (
    rb_id INT NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    rgb CHAR(6) NOT NULL,
    is_trans TINYINT(1) NOT NULL DEFAULT 0,
    bl_id INT NULL,
    bl_name VARCHAR(100) NULL,
    KEY idx_cat_color_bl (bl_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cat_part_category (
    id INT NOT NULL PRIMARY KEY,
    name VARCHAR(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cat_part (
    rb_num VARCHAR(64) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    name VARCHAR(500) NOT NULL,
    category_id INT NOT NULL,
    material VARCHAR(50) NULL,
    bl_num VARCHAR(64) COLLATE utf8mb4_bin NULL,
    bl_match VARCHAR(16) NULL,
    width TINYINT UNSIGNED NULL,
    length TINYINT UNSIGNED NULL,
    height_plates SMALLINT UNSIGNED NULL,
    KEY idx_cat_part_bl (bl_num),
    KEY idx_cat_part_category (category_id),
    KEY idx_cat_part_size (width, length)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cat_part_color (
    part VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    color_id INT NOT NULL,
    img_url VARCHAR(500) NULL,
    PRIMARY KEY (part, color_id),
    KEY idx_cat_part_color_color (color_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cat_part_rel (
    rel_type CHAR(1) NOT NULL,
    child VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    parent VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    PRIMARY KEY (rel_type, child, parent),
    KEY idx_cat_part_rel_child (child),
    KEY idx_cat_part_rel_parent (parent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cat_theme (
    id INT NOT NULL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    parent_id INT NULL,
    KEY idx_cat_theme_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cat_set (
    set_num VARCHAR(64) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    year SMALLINT NULL,
    theme_id INT NULL,
    num_parts INT NOT NULL DEFAULT 0,
    img_url VARCHAR(500) NULL,
    KEY idx_cat_set_theme (theme_id),
    KEY idx_cat_set_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cat_minifig (
    fig_num VARCHAR(64) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    num_parts INT NOT NULL DEFAULT 0,
    img_url VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Flattened default inventory (lowest version) of every set and minifig.
-- Minifig parts and parts of sub-sets are included; from_minifig marks the former.
CREATE TABLE cat_inventory (
    set_num VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    part VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    color_id INT NOT NULL,
    is_spare TINYINT(1) NOT NULL,
    from_minifig TINYINT(1) NOT NULL,
    quantity INT NOT NULL,
    PRIMARY KEY (set_num, part, color_id, is_spare, from_minifig),
    KEY idx_cat_inventory_part (part, color_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- BrickLink catalogue as loaded from the user's own download (for matching and display).
CREATE TABLE cat_bl_color (
    bl_id INT NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    rgb CHAR(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cat_bl_part (
    bl_num VARCHAR(64) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    name VARCHAR(500) NOT NULL,
    category VARCHAR(200) NULL,
    alternates VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_run (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    trigger_type VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL,
    requested_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    log MEDIUMTEXT NULL,
    stats MEDIUMTEXT NULL,
    KEY idx_import_run_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
