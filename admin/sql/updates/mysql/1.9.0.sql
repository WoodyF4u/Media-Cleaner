-- Adds a composite index matching the exact query shape of the new
-- frontend overview (site/src/View/Files/HtmlView.php): "not ignored,
-- optionally filtered by linked, sorted by size" - previously only
-- covered by the separate single-column `idx_ignored` and `idx_size`
-- indexes, which MySQL cannot always combine as efficiently as one
-- index built for this exact access pattern.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_ignored_linked_size` (`ignored`, `linked`, `size`);
