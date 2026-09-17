-- Adds an index on `scanned_at` combined with `link_confidence`, useful
-- for spotting how the confirmed/probable/none breakdown of the most
-- recent scan compares to earlier ones over time.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_scanned_at_link_confidence` (`scanned_at`, `link_confidence`);
