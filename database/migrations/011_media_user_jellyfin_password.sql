-- Credenciales Jellyfin revelables por admin (cifrado con APP_KEY).
ALTER TABLE `media_users`
    ADD COLUMN `jellyfin_password_encrypted` TEXT NULL AFTER `password`;
