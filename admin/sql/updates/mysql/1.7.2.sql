-- Adds a real, useful composite index (speeds up any future per-user
-- name overview on the "Tijdelijk verwijderd" screen).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_deleted_by_original_name` (`deleted_by`, `original_name`);
