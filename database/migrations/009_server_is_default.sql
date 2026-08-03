-- Default server star (Plex/Jellyfin) for auto-registration
ALTER TABLE `servers`
    ADD COLUMN `is_default` TINYINT(1) NOT NULL DEFAULT 0 AFTER `settings`;
