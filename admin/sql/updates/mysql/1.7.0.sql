ALTER TABLE `#__mediacleaner_files`
    ADD COLUMN `no_preview` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `ignored`,
    ADD KEY `idx_no_preview` (`no_preview`);
