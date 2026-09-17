-- Adds a real, useful index (speeds up any future filtering by who
-- removed a file, e.g. for a multi-admin audit view).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_deleted_by` (`deleted_by`);
