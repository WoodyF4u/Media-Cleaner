-- No column change required (pure PHP fix: per-column scanning instead
-- of joining a row's columns into one string before matching). Adds an
-- index on `sizeKB`-equivalent `size` combined with `name`, useful for a
-- future "biggest files with this name pattern" lookup.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_name_size` (`name`, `size`);
