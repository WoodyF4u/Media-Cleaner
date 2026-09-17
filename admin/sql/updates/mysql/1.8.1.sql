-- Adds an index on `edit_url` so the "jump to source" lookups on the
-- references table (used whenever the Files view resolves which article,
-- module, etc. a file is linked from) no longer need a full table scan
-- when filtering or joining on that column.
ALTER TABLE `#__mediacleaner_references` ADD KEY `idx_edit_url` (`edit_url`(191));
