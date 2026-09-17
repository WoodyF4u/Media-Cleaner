-- Adds a real, useful composite index (speeds up quarantine lookups
-- combining type and who removed the file).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_type_deleted_by` (`type`, `deleted_by`);
