-- v2.9.2: Linkcontrole also checks links to other websites, and can be
-- narrowed to one source (articles, modules, an extension's tables). Only
-- additions - nothing existing is changed or dropped.
--
-- `detail`     what an external address answered ("HTTP 404", the
--              connection error, ...), shown next to the link.
-- `idx_table`  for the source filter (grouped by table).
ALTER TABLE `#__mediacleaner_links` ADD COLUMN `detail` VARCHAR(255) NOT NULL DEFAULT '' AFTER `kind`;
ALTER TABLE `#__mediacleaner_links` ADD KEY `idx_table` (`table_name`);
