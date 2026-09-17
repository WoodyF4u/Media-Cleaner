-- Adds a real, useful index (speeds up sorting/searching by file name).
-- Uses ADD KEY syntax, which Joomla's schema checker parses correctly
-- (unlike MODIFY COLUMN, used by mistake in an earlier release of this
-- file, which Joomla's parser could not read properly).
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_name` (`name`(191));
