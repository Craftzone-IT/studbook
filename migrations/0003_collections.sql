-- Owned data for M2: collections, boxes (storage), box labels, loose lots,
-- owned sets (counted on the home page; managed in M4), undo batches, image cache.
-- Every owned-data row carries the batch that last wrote it; batch_change
-- journals each write so a whole batch can be reverted.

CREATE TABLE batch (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL,
    description_key VARCHAR(100) NOT NULL,
    description_params TEXT NULL,
    reverted_at DATETIME NULL,
    KEY idx_batch_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE batch_change (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    batch_id INT UNSIGNED NOT NULL,
    table_name VARCHAR(64) NOT NULL,
    row_id INT UNSIGNED NOT NULL,
    action VARCHAR(8) NOT NULL,
    before_data MEDIUMTEXT NULL,
    after_data MEDIUMTEXT NULL,
    KEY idx_batch_change_batch (batch_id, id),
    KEY idx_batch_change_row (table_name, row_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collection (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    can_lend TINYINT(1) NOT NULL DEFAULT 0,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    batch_id INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE storage (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    collection_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    batch_id INT UNSIGNED NULL,
    KEY idx_storage_collection (collection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE storage_label (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    storage_id INT UNSIGNED NOT NULL,
    part VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    batch_id INT UNSIGNED NULL,
    UNIQUE KEY uq_storage_label (storage_id, part),
    KEY idx_storage_label_part (part)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loose_lot (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    collection_id INT UNSIGNED NOT NULL,
    storage_id INT UNSIGNED NOT NULL,
    part VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    color_id INT NOT NULL,
    qty INT UNSIGNED NOT NULL,
    source_set_id INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    batch_id INT UNSIGNED NULL,
    KEY idx_loose_lot_storage (storage_id),
    KEY idx_loose_lot_collection (collection_id),
    KEY idx_loose_lot_part (part, color_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE owned_set (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    collection_id INT UNSIGNED NOT NULL,
    set_num VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    state VARCHAR(16) NOT NULL,
    lock_mode VARCHAR(16) NOT NULL,
    storage_id INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    batch_id INT UNSIGNED NULL,
    KEY idx_owned_set_collection (collection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cat_image_cache (
    part VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    color_id INT NOT NULL,
    file VARCHAR(100) NULL,
    content_type VARCHAR(50) NULL,
    status VARCHAR(10) NOT NULL,
    fetched_at DATETIME NOT NULL,
    PRIMARY KEY (part, color_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
