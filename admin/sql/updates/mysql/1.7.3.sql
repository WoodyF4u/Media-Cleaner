-- Adds a real, useful composite index (speeds up any future
-- location+type overview on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_original_path_type` (`original_path`(191), `type`);
