-- Adds a real, useful composite index (speeds up any future
-- date+user overview on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_deleted_at_deleted_by` (`deleted_at`, `deleted_by`);
