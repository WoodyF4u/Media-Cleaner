-- Adds two persisted, indexed flags, computed once at scan time (see
-- markActiveExtensionAssetStatus()):
-- - `is_active_extension_images_dir`: file sits under /images/<x>, where
--   <x> matches a currently enabled component (full element or bare
--   name) - backs the new "Hide active extensions' /images/ folders from
--   Unlinked" option.
-- - `possibly_orphaned_extension`: file sits in a folder shaped like an
--   extension's own asset folder (components/modules/plugins/templates)
--   that does NOT match any currently active extension - shown as a
--   hint next to the file's location in the Unlinked overview.
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `is_active_extension_images_dir` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_active_extension_asset`;
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `possibly_orphaned_extension` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_active_extension_images_dir`;
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_is_active_extension_images_dir` (`is_active_extension_images_dir`);
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_possibly_orphaned_extension` (`possibly_orphaned_extension`);

-- Widen the existing composite index to match the Unlinked view's WHERE
-- clause now that a third exclusion can be active at once (see
-- applyUnlinkedViewExclusions()).
--
-- Originally tried to do this in place (DROP `idx_unlinked_view` + ADD it
-- back under the same name, 5 columns). That DROP turned out to be
-- unsafe (see 1.27.0.sql) and was replaced by a pure ADD under a new
-- name, `idx_unlinked_view2`. Retroactively corrected here too in
-- v1.28.0, for the same reason as 1.22.0.sql above: this only changes
-- what the System Information -> Database checker compares this
-- already-applied file against, not anything that gets re-executed.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_unlinked_view2` (`linked`, `ignored`, `is_thumbs_dir`, `is_active_extension_asset`, `is_active_extension_images_dir`);
