-- Adds an index on `deleted_at` combined with `original_name`, useful for
-- the quarantine screen's "most recently removed, by name" lookups -
-- previously these two columns were only indexed separately.
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_deleted_at_original_name` (`deleted_at`, `original_name`);
