-- Adds a real, useful composite index (speeds up any future type+size
-- overview on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_type_size` (`type`, `size`);
