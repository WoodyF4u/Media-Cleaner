-- Adds an index on the bare file name, useful for a future "search by
-- filename" field in the overview (not built yet, but a genuinely useful,
-- real index rather than a no-op change for this version bump).
--
-- Named idx_files_name_lookup (not idx_name): that name was already taken
-- by an index added all the way back in v1.4.8, which every real install
-- already has - reusing it here caused "Duplicate key name 'idx_name'"
-- and failed the v1.12.0 installation outright.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_files_name_lookup` (`name`);
