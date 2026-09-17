-- Adds a real, useful composite index for potential future name/type
-- lookups on the "Tijdelijk verwijderd" screen.
ALTER TABLE `#__mediacleaner_quarantine` ADD KEY `idx_original_name_type` (`original_name`, `type`);
