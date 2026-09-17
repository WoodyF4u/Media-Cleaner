-- Adds a real, useful composite index (speeds up any future name+size
-- overview on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_original_name_size` (`original_name`, `size`);
