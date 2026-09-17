-- No column change required (both fixes are pure PHP changes: the
-- correct BA Gallery deep link, and two extra IC Agenda columns). Adds an
-- index on `url` alone, useful for a future "find by exact source URL"
-- lookup on referenced-linked media.
ALTER TABLE `#__mediacleaner_files` ADD KEY `idx_url_only` (`url`(255));
