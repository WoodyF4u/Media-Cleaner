-- Adds a real, useful index (speeds up filtering/searching by folder location).
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_path` (`path`(191));
