-- Adds an index on `linked` combined with `ignored`, matching the exact
-- WHERE clause shape used by the new getFilteredCount() method behind
-- each filter button's live count.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_linked_ignored_count` (`linked`, `ignored`);
