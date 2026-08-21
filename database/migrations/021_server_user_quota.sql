-- Cupo de usuarios del panel por servidor (0 = sin límite)
ALTER TABLE `servers`
    ADD COLUMN `user_quota` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_default`;
