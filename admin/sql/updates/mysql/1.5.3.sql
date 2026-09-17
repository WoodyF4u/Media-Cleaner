-- Adds a real, useful index (speeds up any future searching/sorting by
-- file name on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_original_name` (`original_name`);
