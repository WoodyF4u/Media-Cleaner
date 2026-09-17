-- No column/index change strictly required by this version's fixes (the
-- generic table sweep now uses SHOW TABLES/SHOW COLUMNS instead of
-- information_schema, and PHP-side glob matching instead of SQL LIKE -
-- both pure code changes). Adds an index on `size` alone (previously
-- only covered in composite indexes), useful for a simple "biggest
-- files first" sort without the ignored/linked/type qualifiers those
-- composites require.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_size_only` (`size`);
