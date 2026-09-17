-- Adds a composite index that speeds up "is this path already ignored,
-- and since when" lookups (used whenever the file list re-checks a path
-- against the ignored table), instead of relying on `idx_relative_path`
-- alone and a separate row scan for `ignored_at`.
ALTER TABLE `#__mediacleaner_ignored` ADD KEY `idx_relative_path_ignored_at` (`relative_path`(191), `ignored_at`);
