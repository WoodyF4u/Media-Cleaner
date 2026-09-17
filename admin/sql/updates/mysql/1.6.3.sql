-- Adds a real, useful index (speeds up any future lookup by original
-- location on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_original_path` (`original_path`(191));
