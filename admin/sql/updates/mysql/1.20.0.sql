-- Adds a persisted, indexed "is this file inside a folder literally named
-- thumbs" flag, computed once at scan time (see pathHasThumbsSegment() /
-- scanFilesystem()) instead of pattern-matching `path` with a leading-
-- wildcard LIKE on every page load. Backs the new "Hide thumbs folders
-- from Unlinked" option on the component's Options screen.
ALTER TABLE `#__mediacleaner_files` ADD COLUMN `is_thumbs_dir` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `no_preview`;
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_is_thumbs_dir` (`is_thumbs_dir`);
