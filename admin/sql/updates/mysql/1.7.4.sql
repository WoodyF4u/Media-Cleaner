-- Adds a real, useful composite index (speeds up any future
-- type+date overview on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_type_deleted_at` (`type`, `deleted_at`);
