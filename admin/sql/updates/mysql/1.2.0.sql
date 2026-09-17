ALTER TABLE `#__mediacleaner_files`
    ADD COLUMN `linked` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `url`,
    ADD KEY `idx_linked` (`linked`);
