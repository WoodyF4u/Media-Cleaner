-- Adds a real, useful composite index (speeds up the combined
-- linked + ignored filter used by the overview).
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_linked_ignored` (`linked`, `ignored`);
