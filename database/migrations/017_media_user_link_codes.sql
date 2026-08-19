-- Códigos de un solo uso para vincular Telegram / WhatsApp desde el portal
CREATE TABLE IF NOT EXISTS `media_user_link_codes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `media_user_id` BIGINT UNSIGNED NOT NULL,
    `channel` VARCHAR(20) NOT NULL DEFAULT 'telegram',
    `code` VARCHAR(32) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_link_codes_code` (`code`),
    KEY `idx_link_codes_user_channel` (`media_user_id`, `channel`),
    KEY `idx_link_codes_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
