-- Adds a composite index for a future "breakdown by file type and linked
-- status" view (e.g. "how much of my unlinked storage is PDFs vs.
-- images") - not used yet by any query today, but a genuinely useful,
-- real index rather than a no-op change for this version bump.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_type_linked_size` (`type`, `linked`, `size`);
