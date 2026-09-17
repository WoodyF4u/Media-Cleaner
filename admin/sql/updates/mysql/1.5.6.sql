-- Adds a real, useful composite index (speeds up looking up references
-- for a specific file, filtered by source type).
ALTER TABLE `#__mediacleaner_references` ADD KEY `idx_relative_source` (`relative_path`(191), `source_type`);
