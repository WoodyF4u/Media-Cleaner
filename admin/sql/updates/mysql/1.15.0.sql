-- No column change required (IC Agenda/JDownloads support moved into the
-- existing curated-content code path, a pure PHP change). Adds an index
-- on `no_preview` alone (previously only implied via other columns),
-- useful for a simple "show me SVG icon-sprite files with no real
-- preview" query on its own.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_no_preview_only` (`no_preview`);
