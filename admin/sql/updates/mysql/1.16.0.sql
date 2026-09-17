-- No column change required (both fixes are pure PHP/matching-logic
-- changes). Adds an index on `path` alone (previously only covered with a
-- length prefix in idx_path/idx_path_repair), useful now that BA Gallery
-- support means more lookups filtering by exact folder location.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_path_full` (`path`(255));
