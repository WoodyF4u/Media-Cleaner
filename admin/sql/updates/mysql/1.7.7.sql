-- Adds a real, useful composite index (speeds up any future
-- type+location overview on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_type_original_path` (`type`, `original_path`(191));
