-- No column change required by this version's fix (it's a pure PHP/timing
-- change: the database table sweep now runs before the filesystem code
-- sweep, and the code sweep gets its own separate, smaller time cap).
-- Adds an index on `ignored` alone (previously only covered in composite
-- indexes), useful for a simple "show me everything currently ignored"
-- query without the linked/size qualifiers those require.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_ignored_only` (`ignored`);
