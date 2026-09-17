-- Adds a real, useful composite index (speeds up any future date+type
-- filtering on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_deleted_at_type` (`deleted_at`, `type`);
