CREATE TABLE IF NOT EXISTS `#__mediacleaner_references` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `relative_path` VARCHAR(1024) NOT NULL,
    `source_type` VARCHAR(20) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `edit_url` VARCHAR(1024) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_relative_path` (`relative_path`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
