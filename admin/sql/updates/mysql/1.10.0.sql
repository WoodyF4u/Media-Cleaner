-- Adds the "link_confidence" column ('confirmed' / 'probable' / 'none')
-- introduced by the generic table/filesystem sweep, distinguishing a
-- certain full-path match from a weaker filename-only match. Existing
-- rows all came from the old, path-only matching logic, so every
-- currently "linked" row is backfilled as 'confirmed' (accurate for how
-- they were actually determined) and every "unlinked" row as 'none'.
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `link_confidence` VARCHAR(20) NOT NULL DEFAULT 'none' AFTER `linked`;
UPDATE `#__mediacleaner_files` SET `link_confidence` = 'confirmed' WHERE `linked` = 1;
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_link_confidence` (`link_confidence`);
