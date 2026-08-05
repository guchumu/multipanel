-- Membership presence after force-sync against Plex/Jellyfin user lists.
ALTER TABLE `media_users`
    ADD COLUMN `on_server` TINYINT(1) NULL DEFAULT NULL AFTER `external_id`,
    ADD COLUMN `membership_synced_at` DATETIME NULL AFTER `on_server`;
