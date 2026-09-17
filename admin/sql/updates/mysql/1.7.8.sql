-- Adds a real, useful composite index (speeds up any future name+type+size
-- overview on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_original_name_type_size` (`original_name`, `type`, `size`);
