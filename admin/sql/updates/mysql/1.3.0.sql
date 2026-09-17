CREATE TABLE IF NOT EXISTS `#__mediacleaner_quarantine` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `original_path` VARCHAR(1024) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `quarantine_path` VARCHAR(1024) NOT NULL,
    `size` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    `type` VARCHAR(10) NOT NULL,
    `deleted_at` DATETIME NOT NULL,
    `deleted_by` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
