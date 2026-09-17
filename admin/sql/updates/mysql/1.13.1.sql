-- Adds an index on `scanned_at` alone (previously only covered in
-- composite indexes), useful for a simple "show me the most recent scan's
-- results" query without the link_confidence qualifier those require.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_scanned_at_only` (`scanned_at`);
