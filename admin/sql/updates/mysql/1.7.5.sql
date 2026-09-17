-- Adds a real, useful composite index (speeds up any future name+date
-- overview on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_original_name_deleted_at` (`original_name`, `deleted_at`);
