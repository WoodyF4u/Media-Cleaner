-- Adds a composite index that speeds up "who restored/quarantined this
-- exact original path" lookups on the quarantine screen (currently only
-- covered separately by `idx_original_path` and `idx_deleted_by`, forcing
-- an index merge instead of a single index scan).
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_deleted_by_original_path` (`deleted_by`, `original_path`(191));
