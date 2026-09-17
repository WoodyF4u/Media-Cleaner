-- Extends the "possibly belongs to extension" / "extension appears to
-- have been removed" naming already built for /images/<x> files (see
-- 2.1.0.sql) to /components/<x>, /modules/<x>, /templates/<x> and
-- /plugins/<group>/<element> too - previously those four only got the
-- generic, unnamed possibly_orphaned_extension flag (see 1.24.0.sql).
-- Also fixes the underlying is_active_extension_asset detection for
-- these same four folders to use the same loosened, normalise+prefix-
-- boundary matching already used for /images/<x> (see
-- pathBelongsToActiveExtension() / matchFolderSegmentToExtension() in
-- Scanner.php), since the same "on-disk folder name doesn't exactly
-- match the extension's element" mismatch class found in
-- /images/jch_optimize_backup_images/ (2.2.2.sql) turned out to affect
-- these folders as well - found in practice under /templates/.
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `asset_dir_extension_name` VARCHAR(255) NULL DEFAULT NULL AFTER `images_dir_extension_removed`;
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `asset_dir_extension_removed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `asset_dir_extension_name`;
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_asset_dir_extension_removed` (`asset_dir_extension_removed`);

-- Registry of /components/, /modules/, /templates/ and /plugins/ folder
-- names this component has confirmed, on some earlier scan, to belong
-- to an installed extension - the asset-folder counterpart of
-- `#__mediacleaner_known_images_extensions` (2.1.0.sql). Kept as a
-- separate table (rather than reusing that one) because a component,
-- module, plugin and template can plausibly share the same folder or
-- element name - `folder_type` keeps those namespaces apart.
CREATE TABLE IF NOT EXISTS `#__mediacleaner_known_asset_extensions` (
    `folder_type` VARCHAR(20) NOT NULL,
    `folder_key` VARCHAR(190) NOT NULL,
    `extension_name` VARCHAR(255) NOT NULL,
    `last_seen_installed` DATETIME NOT NULL,
    PRIMARY KEY (`folder_type`, `folder_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
