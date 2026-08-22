-- Campaña de reenganche a usuarios caducados (invitar a volver / prueba)
CREATE TABLE IF NOT EXISTS `media_user_reengage` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `media_user_id` BIGINT UNSIGNED NOT NULL,
    `send_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_sent_at` DATETIME NULL,
    `last_kind` VARCHAR(20) NOT NULL DEFAULT 'invite',
    `converted_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_reengage_user` (`media_user_id`),
    KEY `idx_reengage_tenant` (`tenant_id`),
    CONSTRAINT `fk_reengage_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reengage_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
