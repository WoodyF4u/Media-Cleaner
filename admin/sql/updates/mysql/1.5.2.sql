-- The indexes below were originally scheduled for 1.4.8/1.4.9/1.4.10, but
-- those version numbers were already marked as "applied" on sites that
-- installed an earlier (incorrect) release of this component, so Joomla
-- skipped re-running them once the content was corrected. Using a brand
-- new version number here guarantees this actually executes.
--
-- Named with a "_repair" suffix rather than reusing idx_name/idx_path/
-- idx_linked_ignored: those exact names are what 1.4.8/1.4.9/1.4.10 add on
-- a site that *wasn't* affected by the original bug, so reusing them here
-- caused "Duplicate key name" and failed installation outright for anyone
-- installing (or updating through) the whole version history in one go,
-- as happened on bmwcruiser.nl going straight to v1.12.0. A small amount
-- of redundant indexing for the narrow set of sites this was actually
-- meant to repair is a fine trade-off for installing cleanly everywhere
-- else.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_name_repair` (`name`(191));
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_path_repair` (`path`(191));
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_linked_ignored_repair` (`linked`, `ignored`);
