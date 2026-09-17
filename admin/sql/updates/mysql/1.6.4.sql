-- Adds a real, useful index (speeds up any future sorting by file size on
-- the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_size` (`size`);
