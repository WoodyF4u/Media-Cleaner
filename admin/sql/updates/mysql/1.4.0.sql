ALTER TABLE `#__mediacleaner_files`
    ADD COLUMN `ignored` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `linked`,
    ADD KEY `idx_ignored` (`ignored`);

CREATE TABLE IF NOT EXISTS `#__mediacleaner_ignored` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `relative_path` VARCHAR(1024) NOT NULL,
    `ignored_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_relative_path` (`relative_path`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
