-- Adds a real, useful index (speeds up any future filtering by file type
-- on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_type` (`type`);
