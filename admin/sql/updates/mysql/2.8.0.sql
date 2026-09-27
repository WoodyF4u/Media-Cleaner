-- Supports the new "afgeleide bestanden" feature: generated thumbnails
-- and resized copies of another scanned file (e.g. Event Gallery's
-- /images/eventgallery_generated/<map>/nocrop_512_<foto>.jpg next to
-- /images/eventgallery/<map>/<foto>.jpg) are now recognised generically
-- and follow their original's linked status, instead of always landing
-- under "Niet gekoppeld" - see Scanner::applyDerivedFileLinking().
--
-- `derived_from`   relative path of the original file, NULL if none found.
-- `derived_status` 'linked_original' | 'unlinked_original' |
--                  'missing_original', NULL for ordinary files.
--
-- Existing rows get NULL for both - correct until the next scan, which
-- script.php already requests automatically after every update.
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `derived_from` VARCHAR(1024) NULL DEFAULT NULL AFTER `likely_manual_upload`;
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `derived_status` VARCHAR(20) NULL DEFAULT NULL AFTER `derived_from`;
ALTER TABLE `#__mediacleaner_files` ADD INDEX `idx_derived_status` (`derived_status`);
