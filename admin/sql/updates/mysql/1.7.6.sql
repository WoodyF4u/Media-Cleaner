-- Adds a real, useful composite index (speeds up any future
-- per-user type+size overview on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_deleted_by_type_size` (`deleted_by`, `type`, `size`);
