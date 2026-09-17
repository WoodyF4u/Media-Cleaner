-- No column change required (pure PHP change: components/modules
-- restored to the filesystem code sweep, now that index-based matching
-- makes per-file checks cheap). Adds an index on `url` combined with
-- `linked`, useful for a future "external vs local URL, and is it in
-- use" breakdown.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_url_linked` (`url`(191), `linked`);
