-- Adds the "possibly belongs to extension" / "extension appears to have
-- been removed" hint for /images/<x> files (see
-- markActiveExtensionAssetStatus() / pathBelongsToKnownExtensionImagesFolder()
-- in Scanner.php). Unlike the existing possibly_orphaned_extension hint
-- (components/modules/plugins/templates only, matched purely by folder
-- shape - see 1.24.0.sql), an /images/<x> folder can just as easily be
-- ordinary site content (e.g. images/banners), so this one is only ever
-- shown when <x> genuinely matches a real extension name - either a
-- currently installed one (active or not, read live from
-- `#__extensions`) or one this component has itself previously confirmed
-- installed under that folder name (see the new
-- `#__mediacleaner_known_images_extensions` registry below), never a
-- bare pattern guess.
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `images_dir_extension_name` VARCHAR(255) NULL DEFAULT NULL AFTER `possibly_orphaned_extension`;
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `images_dir_extension_removed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `images_dir_extension_name`;
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_images_dir_extension_removed` (`images_dir_extension_removed`);

-- Registry of /images/<x> folder names this component has confirmed, on
-- some earlier scan, to belong to an installed extension - upserted on
-- every scan (see upsertKnownImagesExtensionFolders()). Kept even after
-- that extension is later uninstalled entirely, since that's precisely
-- what lets the "extension appears to have been removed" hint still name
-- it, rather than falling silent the moment #__extensions no longer has
-- a row for it.
CREATE TABLE IF NOT EXISTS `#__mediacleaner_known_images_extensions` (
    `folder_name` VARCHAR(190) NOT NULL,
    `extension_name` VARCHAR(255) NOT NULL,
    `last_seen_installed` DATETIME NOT NULL,
    PRIMARY KEY (`folder_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
