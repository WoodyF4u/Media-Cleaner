-- Adds a real, useful index (speeds up any future sorting by when a file
-- was marked as ignored).
ALTER TABLE `#__mediacleaner_ignored` ADD KEY `idx_ignored_at` (`ignored_at`);
