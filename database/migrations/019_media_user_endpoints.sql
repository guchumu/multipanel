-- Historial de IPs y dispositivos por usuario (hogar vs fuera)
CREATE TABLE IF NOT EXISTS `media_user_endpoints` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `media_user_id` BIGINT UNSIGNED NOT NULL,
    `ip` VARCHAR(45) NOT NULL DEFAULT '',
    `lan_ip` VARCHAR(45) NULL,
    `location` VARCHAR(16) NOT NULL DEFAULT 'UNKNOWN',
    `device_key` CHAR(40) NOT NULL,
    `device_name` VARCHAR(191) NULL,
    `product` VARCHAR(191) NULL,
    `platform` VARCHAR(191) NULL,
    `machine_id` VARCHAR(191) NULL,
    `kind` VARCHAR(16) NOT NULL DEFAULT 'unknown',
    `kind_locked` TINYINT(1) NOT NULL DEFAULT 0,
    `play_count` INT UNSIGNED NOT NULL DEFAULT 1,
    `first_seen_at` DATETIME NOT NULL,
    `last_seen_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_media_user_endpoints` (`media_user_id`, `ip`, `device_key`),
    KEY `idx_media_user_endpoints_tenant` (`tenant_id`, `last_seen_at`),
    KEY `idx_media_user_endpoints_kind` (`media_user_id`, `kind`),
    CONSTRAINT `fk_media_user_endpoints_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
