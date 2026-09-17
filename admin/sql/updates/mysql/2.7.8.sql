-- Supports the new "systeembestanden apart zetten" feature: files sitting
-- in a generic bundled-asset folder (e.g. .../assets/images, .../vendor,
-- .../webfonts - see Scanner::pathHasSystemAssetSegment()) can be
-- pre-selected and ignored in one click, separately from files that were
-- likely added later by hand into that same folder (see
-- Scanner::applyManualUploadOutlierDetection()).
--
-- `file_modified_at` is the file's own filesystem mtime, captured fresh on
-- every scan - distinct from `scanned_at`, which only says when Media
-- Cleaner last looked at it. Needed to tell "shipped together with the
-- extension" files apart from "added later" ones by date clustering.
--
-- Existing rows (scanned before this version) get NULL/0 for all three -
-- correct, since none of this could be computed retroactively without
-- re-reading the files from disk. A rescan fills them in properly.
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `file_modified_at` DATETIME NULL DEFAULT NULL AFTER `asset_dir_extension_removed`;
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `is_system_asset_dir` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `file_modified_at`;
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `likely_manual_upload` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_system_asset_dir`;
ALTER TABLE `#__mediacleaner_files` ADD INDEX `idx_is_system_asset_dir` (`is_system_asset_dir`);
ALTER TABLE `#__mediacleaner_files` ADD INDEX `idx_likely_manual_upload` (`likely_manual_upload`);
ALTER TABLE `#__mediacleaner_files` ADD INDEX `idx_system_asset_view` (`linked`, `ignored`, `is_system_asset_dir`, `likely_manual_upload`);
