-- v2.9.0: new Verkleinen tab and storage folder (see StorageLocator,
-- CompressJob, ReferenceRewriter, DuplicateFinder). Only new tables -
-- nothing existing is changed or dropped.
--
-- `#__mediacleaner_state`          small key/value store (where the
--                                  storage folder is, etc.) - kept out of
--                                  the component params, because saving
--                                  Opties replaces those completely.
-- `#__mediacleaner_compress`       one row per file made smaller/converted:
--                                  what was done, sizes, where the original
--                                  is kept, and whether it was restored.
-- `#__mediacleaner_compress_refs`  every database value changed to point at
--                                  a converted file, with its old value, so
--                                  "Herstellen" can put it back exactly.
-- `#__mediacleaner_duplicates`     result of the last "Dubbele kopieën" search.
CREATE TABLE IF NOT EXISTS `#__mediacleaner_state` (
    `name` VARCHAR(100) NOT NULL,
    `value` MEDIUMTEXT NOT NULL,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__mediacleaner_compress` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `relative_path` VARCHAR(1024) NOT NULL,
    `new_path` VARCHAR(1024) NOT NULL DEFAULT '',
    `action` VARCHAR(20) NOT NULL,
    `file_type` VARCHAR(10) NOT NULL,
    `status` VARCHAR(20) NOT NULL,
    `old_size` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    `new_size` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    `old_px` VARCHAR(30) NOT NULL DEFAULT '',
    `new_px` VARCHAR(30) NOT NULL DEFAULT '',
    `info` VARCHAR(255) NOT NULL DEFAULT '',
    `reason` VARCHAR(512) NOT NULL DEFAULT '',
    `original_stored` VARCHAR(1024) NOT NULL DEFAULT '',
    `ref_count` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `job_id` CHAR(32) NOT NULL DEFAULT '',
    `created` DATETIME NOT NULL,
    `created_by` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `restored` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_relative_path` (`relative_path`(191)),
    KEY `idx_new_path` (`new_path`(191)),
    KEY `idx_status` (`status`),
    KEY `idx_job_status` (`job_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__mediacleaner_compress_refs` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `compress_id` INT(11) UNSIGNED NOT NULL,
    `table_name` VARCHAR(190) NOT NULL,
    `pk_column` VARCHAR(64) NOT NULL,
    `pk_value` VARCHAR(190) NOT NULL,
    `column_name` VARCHAR(64) NOT NULL,
    `old_value` LONGTEXT NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_compress_id` (`compress_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__mediacleaner_duplicates` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `relative_path` VARCHAR(1024) NOT NULL,
    `twin_path` VARCHAR(1024) NOT NULL,
    `size` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    `hash` CHAR(40) NOT NULL,
    `found_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_relative_path` (`relative_path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
