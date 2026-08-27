-- Enlaces de un solo uso para cortar una reproducción desde WhatsApp (GET /k/{code})
CREATE TABLE IF NOT EXISTS `session_kill_links` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `server_id` BIGINT UNSIGNED NOT NULL,
    `session_id` VARCHAR(128) NOT NULL,
    `code` VARCHAR(32) NOT NULL,
    `reason_key` VARCHAR(20) NOT NULL DEFAULT '',
    `kill_message` VARCHAR(500) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_session_kill_links_code` (`code`),
    KEY `idx_session_kill_links_expires` (`expires_at`),
    KEY `idx_session_kill_links_server_session` (`server_id`, `session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
