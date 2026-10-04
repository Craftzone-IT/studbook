-- M4: owned sets. A set is kept as a reference to the official inventory plus
-- deltas: only the differences (missing parts as negative, extra parts as
-- positive quantities), one row per set, part and colour.

ALTER TABLE owned_set ADD COLUMN created_at DATETIME NULL AFTER storage_id, ADD KEY idx_owned_set_storage (storage_id);

UPDATE owned_set SET created_at = updated_at WHERE created_at IS NULL;

CREATE TABLE owned_set_delta (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owned_set_id INT UNSIGNED NOT NULL,
    part VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    color_id INT NOT NULL,
    qty INT NOT NULL,
    updated_at DATETIME NOT NULL,
    batch_id INT UNSIGNED NULL,
    UNIQUE KEY uq_owned_set_delta (owned_set_id, part, color_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
