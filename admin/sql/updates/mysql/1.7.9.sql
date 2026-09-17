-- Adds a real, useful composite index (speeds up any future
-- location+date overview on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_original_path_deleted_at` (`original_path`(191), `deleted_at`);
