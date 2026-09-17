-- Adds a real, useful index (speeds up any future filtering by reference
-- source type, e.g. showing only "article" references).
ALTER TABLE `#__mediacleaner_references` ADD KEY `idx_source_type` (`source_type`);
