-- Adds a composite index matching the exact query shape introduced by the
-- confirmed/probable distinction (v1.10.0): "not confirmed-linked,
-- optionally filtered by linked, sorted by size" - previously only
-- covered by separate single-column indexes.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_linked_confidence_size` (`linked`, `link_confidence`, `size`);
