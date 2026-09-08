-- Log de cortes de reproducción (Transcode salvable y límites de streams)
CREATE TABLE IF NOT EXISTS `playback_cut_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `media_user_id` BIGINT UNSIGNED NULL,
    `server_id` BIGINT UNSIGNED NULL,
    `username` VARCHAR(255) NULL,
    `kind` VARCHAR(40) NOT NULL,
    `title` VARCHAR(500) NULL,
    `session_id` VARCHAR(128) NULL,
    `client_message` VARCHAR(500) NULL,
    `cut_why` VARCHAR(500) NOT NULL,
    `detail_json` JSON NULL,
    `killed` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_playback_cut_logs_tenant` (`tenant_id`, `created_at`),
    KEY `idx_playback_cut_logs_user` (`media_user_id`, `created_at`),
    KEY `idx_playback_cut_logs_kind` (`tenant_id`, `kind`, `created_at`),
    CONSTRAINT `fk_playback_cut_logs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_playback_cut_logs_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_playback_cut_logs_server` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
