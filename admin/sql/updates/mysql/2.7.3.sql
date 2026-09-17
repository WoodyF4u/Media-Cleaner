-- Supports the new "auto-negeer thumbnail-cachebestanden" feature: an
-- unlinked file in a 'thumbs' folder now gets moved straight into
-- "Genegeerde media" on scan, instead of sitting in "Niet-gekoppeld"
-- with just an explanatory note (see Scanner::applyAutoIgnoreThumbs()).
--
-- `source` records whether a `#__mediacleaner_ignored` row came from the
-- user's own "Negeren" action ('manual') or from this automatic pass
-- ('auto_thumbs'). `suppressed` lets a user's manual un-ignore of an
-- 'auto_thumbs' row survive future rescans: the row is kept (not
-- deleted) with suppressed=1, so a rescan's auto-ignore pass sees "this
-- file was already decided" and leaves it alone, while
-- markIgnoredStatus() still treats it as "not ignored" so the file
-- shows up under Niet-gekoppeld again as the user intended.
--
-- Existing rows (all pre-2.7.3 ignores) default to source='manual',
-- suppressed=0 - correct, since auto-ignoring didn't exist before this
-- version, so every existing ignored file really was ignored by hand.
ALTER TABLE `#__mediacleaner_ignored` ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER `ignored_at`;
ALTER TABLE `#__mediacleaner_ignored` ADD COLUMN `suppressed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `source`;
