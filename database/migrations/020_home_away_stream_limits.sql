-- Límites de reproducción en casa / fuera por usuario
ALTER TABLE `media_users`
    ADD COLUMN `max_home_streams` TINYINT UNSIGNED NULL DEFAULT NULL AFTER `max_streams`,
    ADD COLUMN `max_away_streams` TINYINT UNSIGNED NULL DEFAULT NULL AFTER `max_home_streams`;
