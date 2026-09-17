-- No column change strictly required by this version's rewrite (it's a
-- pure PHP change: index-based, extension-agnostic matching instead of
-- per-item text search). Adds a composite index matching the new debug
-- summary's most common lookup - "how many of each link_confidence value
-- from the most recent scan" - without needing the linked column too.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_confidence_scanned` (`link_confidence`, `scanned_at`);
