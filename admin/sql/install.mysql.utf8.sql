CREATE TABLE IF NOT EXISTS `#__mediacleaner_files` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `path` VARCHAR(1024) NOT NULL,
    `size` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    `type` VARCHAR(10) NOT NULL,
    `url` VARCHAR(1024) NOT NULL,
    `linked` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `link_confidence` VARCHAR(20) NOT NULL DEFAULT 'none',
    `ignored` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `no_preview` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `is_thumbs_dir` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `is_active_extension_asset` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `is_active_extension_images_dir` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `possibly_orphaned_extension` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `images_dir_extension_name` VARCHAR(255) NULL DEFAULT NULL,
    `images_dir_extension_removed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `asset_dir_extension_name` VARCHAR(255) NULL DEFAULT NULL,
    `asset_dir_extension_removed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    -- v2.7.8: `file_modified_at` is the file's own filesystem mtime (NOT
    -- when Media Cleaner scanned it - see `scanned_at` for that),
    -- captured so `is_system_asset_dir` files can be clustered by
    -- upload date to guess which ones were shipped together with the
    -- extension vs added later by hand - see
    -- Scanner::applyManualUploadOutlierDetection().
    `file_modified_at` DATETIME NULL DEFAULT NULL,
    -- Whether the folder path contains a segment like "assets"/"vendor"/
    -- "webfonts" (see Scanner::pathHasSystemAssetSegment()) - a generic,
    -- not-extension-specific signal that a file is bundled UI/library
    -- material rather than uploaded content, the same idea as
    -- `is_thumbs_dir` but for a different, equally common folder
    -- convention.
    `is_system_asset_dir` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    -- Among `is_system_asset_dir` files, whether this one's
    -- `file_modified_at` is a clear outlier from the rest of its folder
    -- (see Scanner::applyManualUploadOutlierDetection()) - a guess that
    -- it was added later by hand rather than shipped with the
    -- extension. Always 0 when `is_system_asset_dir` is 0.
    `likely_manual_upload` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `scanned_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_size` (`size`),
    KEY `idx_type` (`type`),
    KEY `idx_linked` (`linked`),
    KEY `idx_ignored` (`ignored`),
    KEY `idx_scanned_at` (`scanned_at`),
    KEY `idx_name` (`name`(191)),
    KEY `idx_path` (`path`(191)),
    KEY `idx_linked_ignored` (`linked`, `ignored`),
    KEY `idx_no_preview` (`no_preview`),
    KEY `idx_is_thumbs_dir` (`is_thumbs_dir`),
    KEY `idx_is_active_extension_asset` (`is_active_extension_asset`),
    KEY `idx_is_active_extension_images_dir` (`is_active_extension_images_dir`),
    KEY `idx_possibly_orphaned_extension` (`possibly_orphaned_extension`),
    KEY `idx_is_system_asset_dir` (`is_system_asset_dir`),
    KEY `idx_likely_manual_upload` (`likely_manual_upload`),
    KEY `idx_unlinked_view2` (`linked`, `ignored`, `is_thumbs_dir`, `is_active_extension_asset`, `is_active_extension_images_dir`),
    KEY `idx_system_asset_view` (`linked`, `ignored`, `is_system_asset_dir`, `likely_manual_upload`),
    -- Everything below this line was originally only added via
    -- admin/sql/updates/mysql/*.sql (v1.2.0 through v1.19.0), which only
    -- ever run when an EXISTING install is being updated - never on a
    -- fresh install, which only runs this file once. This base script
    -- had drifted out of sync with that update history since v1.9.0,
    -- so every fresh install was silently missing these 19 indexes -
    -- functionally fine, but flagged as "problems" by System Information
    -- -> Database, since that check compares the live table against
    -- every one of those update files regardless of whether this is a
    -- fresh install or an upgrade. Backfilled in v1.37.4 so a fresh
    -- install and a fully-updated existing site end up with byte-for-
    -- byte the same schema, matching each update file's own column list
    -- exactly (including the semantically-redundant `_repair` ones from
    -- v1.5.2, kept for the same reason: the checker verifies by index
    -- name, not by whether an equivalent index already exists).
    KEY `idx_link_confidence` (`link_confidence`),
    KEY `idx_linked_confidence_size` (`linked`, `link_confidence`, `size`),
    KEY `idx_type_linked_size` (`type`, `linked`, `size`),
    KEY `idx_scanned_at_link_confidence` (`scanned_at`, `link_confidence`),
    KEY `idx_files_name_lookup` (`name`),
    KEY `idx_size_only` (`size`),
    KEY `idx_scanned_at_only` (`scanned_at`),
    KEY `idx_ignored_only` (`ignored`),
    KEY `idx_no_preview_only` (`no_preview`),
    KEY `idx_path_full` (`path`(255)),
    KEY `idx_url_only` (`url`(255)),
    KEY `idx_confidence_scanned` (`link_confidence`, `scanned_at`),
    KEY `idx_name_size` (`name`, `size`),
    KEY `idx_url_linked` (`url`(191), `linked`),
    KEY `idx_linked_ignored_count` (`linked`, `ignored`),
    KEY `idx_ignored_linked_size` (`ignored`, `linked`, `size`),
    KEY `idx_name_repair` (`name`(191)),
    KEY `idx_path_repair` (`path`(191)),
    KEY `idx_linked_ignored_repair` (`linked`, `ignored`),
    KEY `idx_images_dir_extension_removed` (`images_dir_extension_removed`),
    KEY `idx_asset_dir_extension_removed` (`asset_dir_extension_removed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__mediacleaner_known_images_extensions` (
    `folder_name` VARCHAR(190) NOT NULL,
    `extension_name` VARCHAR(255) NOT NULL,
    `last_seen_installed` DATETIME NOT NULL,
    PRIMARY KEY (`folder_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__mediacleaner_known_asset_extensions` (
    `folder_type` VARCHAR(20) NOT NULL,
    `folder_key` VARCHAR(190) NOT NULL,
    `extension_name` VARCHAR(255) NOT NULL,
    `last_seen_installed` DATETIME NOT NULL,
    PRIMARY KEY (`folder_type`, `folder_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__mediacleaner_quarantine` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `original_path` VARCHAR(1024) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `quarantine_path` VARCHAR(1024) NOT NULL,
    `size` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    `type` VARCHAR(10) NOT NULL,
    `deleted_at` DATETIME NOT NULL,
    `deleted_by` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_deleted_at` (`deleted_at`),
    KEY `idx_original_name` (`original_name`),
    KEY `idx_type` (`type`),
    KEY `idx_deleted_by` (`deleted_by`),
    KEY `idx_type_deleted_by` (`type`, `deleted_by`),
    KEY `idx_original_name_type` (`original_name`, `type`),
    KEY `idx_deleted_at_type` (`deleted_at`, `type`),
    KEY `idx_original_path` (`original_path`(191)),
    KEY `idx_size` (`size`),
    KEY `idx_deleted_by_size` (`deleted_by`, `size`),
    KEY `idx_deleted_by_type` (`deleted_by`, `type`),
    KEY `idx_original_name_size` (`original_name`, `size`),
    KEY `idx_type_size` (`type`, `size`),
    KEY `idx_type_original_name` (`type`, `original_name`),
    KEY `idx_deleted_at_deleted_by` (`deleted_at`, `deleted_by`),
    KEY `idx_deleted_by_original_name` (`deleted_by`, `original_name`),
    KEY `idx_original_path_type` (`original_path`(191), `type`),
    KEY `idx_type_deleted_at` (`type`, `deleted_at`),
    KEY `idx_original_name_deleted_at` (`original_name`, `deleted_at`),
    KEY `idx_deleted_by_type_size` (`deleted_by`, `type`, `size`),
    KEY `idx_type_original_path` (`type`, `original_path`(191)),
    KEY `idx_original_name_type_size` (`original_name`, `type`, `size`),
    KEY `idx_original_path_deleted_at` (`original_path`(191), `deleted_at`),
    -- See the same note above the files table - backfilled in v1.37.4.
    KEY `idx_deleted_by_original_path` (`deleted_by`, `original_path`(191)),
    KEY `idx_deleted_at_original_name` (`deleted_at`, `original_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__mediacleaner_ignored` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `relative_path` VARCHAR(1024) NOT NULL,
    `ignored_at` DATETIME NOT NULL,
    -- v2.7.3: who/what made this file ignored - 'manual' (the "Negeren"
    -- action) or 'auto_thumbs' (auto-ignored on scan because it's an
    -- unlinked file in a 'thumbs' cache folder - see
    -- Scanner::applyAutoIgnoreThumbs()). `suppressed` lets a user's
    -- manual un-ignore of an 'auto_thumbs' row survive future rescans:
    -- instead of deleting the row (which would make the file look
    -- "never decided" again and get auto-ignored right back), it's kept
    -- with suppressed=1, which markIgnoredStatus() treats as "not
    -- ignored" while applyAutoIgnoreThumbs() treats as "already decided,
    -- leave it alone".
    `source` VARCHAR(20) NOT NULL DEFAULT 'manual',
    `suppressed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_relative_path` (`relative_path`(255)),
    KEY `idx_ignored_at` (`ignored_at`),
    -- See the same note above the files table - backfilled in v1.37.4.
    KEY `idx_relative_path_ignored_at` (`relative_path`(191), `ignored_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__mediacleaner_references` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `relative_path` VARCHAR(1024) NOT NULL,
    `source_type` VARCHAR(20) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `edit_url` VARCHAR(1024) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_relative_path` (`relative_path`(255)),
    KEY `idx_source_type` (`source_type`),
    KEY `idx_relative_source` (`relative_path`(191), `source_type`),
    -- See the same note above the files table - backfilled in v1.37.4.
    KEY `idx_edit_url` (`edit_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


