-- v2.9.1: new Linkcontrole tab (see LinkChecker, LinkFixer). Only new
-- tables - nothing existing is changed or dropped.
--
-- `#__mediacleaner_links`          result of the last check: one row per
--                                  broken reference per database value,
--                                  with where it is and possible repairs.
-- `#__mediacleaner_links_ignored`  references marked "Negeren"; kept apart
--                                  so a new check never forgets them.
-- `#__mediacleaner_link_fixes`     every repair, with the value before and
--                                  after, so it can be undone exactly.
CREATE TABLE IF NOT EXISTS `#__mediacleaner_links` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `hash` CHAR(40) NOT NULL,
    `table_name` VARCHAR(190) NOT NULL,
    `pk_column` VARCHAR(64) NOT NULL DEFAULT '',
    `pk_value` VARCHAR(190) NOT NULL DEFAULT '',
    `column_name` VARCHAR(64) NOT NULL,
    `url` VARCHAR(2048) NOT NULL,
    `path` VARCHAR(1024) NOT NULL,
    `base` VARCHAR(10) NOT NULL DEFAULT 'site',
    `kind` VARCHAR(20) NOT NULL,
    `element` VARCHAR(20) NOT NULL DEFAULT '',
    `popup` TINYINT(1) NOT NULL DEFAULT 0,
    `inner_path` VARCHAR(1024) NOT NULL DEFAULT '',
    `occurrences` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `location_type` VARCHAR(30) NOT NULL DEFAULT '',
    `location_title` VARCHAR(255) NOT NULL DEFAULT '',
    `location_url` VARCHAR(512) NOT NULL DEFAULT '',
    `item_state` INT(11) NULL DEFAULT NULL,
    `suggestions` MEDIUMTEXT NOT NULL,
    `best` TINYINT(4) NOT NULL DEFAULT -1,
    `ignored` TINYINT(1) NOT NULL DEFAULT 0,
    `found_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_hash` (`hash`),
    KEY `idx_list` (`ignored`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__mediacleaner_links_ignored` (
    `hash` CHAR(40) NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__mediacleaner_link_fixes` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `link_hash` CHAR(40) NOT NULL,
    `table_name` VARCHAR(190) NOT NULL,
    `pk_column` VARCHAR(64) NOT NULL DEFAULT '',
    `pk_value` VARCHAR(190) NOT NULL DEFAULT '',
    `column_name` VARCHAR(64) NOT NULL,
    `url` VARCHAR(2048) NOT NULL,
    `new_url` VARCHAR(2048) NOT NULL DEFAULT '',
    `action` VARCHAR(20) NOT NULL,
    `target_path` VARCHAR(1024) NOT NULL DEFAULT '',
    `copied_file` VARCHAR(1024) NOT NULL DEFAULT '',
    `old_value` LONGTEXT NOT NULL,
    `new_value` LONGTEXT NOT NULL,
    `entry` MEDIUMTEXT NOT NULL,
    `location_title` VARCHAR(255) NOT NULL DEFAULT '',
    `created` DATETIME NOT NULL,
    `created_by` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `undone` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_link_hash` (`link_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
