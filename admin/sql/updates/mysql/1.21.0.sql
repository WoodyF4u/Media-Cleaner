-- Adds a persisted, indexed "is this file inside an active component's or
-- template's own asset folder" flag, computed once at scan time (see
-- pathBelongsToActiveExtension() / markActiveExtensionAssetStatus())
-- against `#__extensions`, instead of re-querying enabled extensions on
-- every page load. Backs the new "Hide active component/template assets
-- from Unlinked" option on the component's Options screen.
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `is_active_extension_asset` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_thumbs_dir`;
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_is_active_extension_asset` (`is_active_extension_asset`);
