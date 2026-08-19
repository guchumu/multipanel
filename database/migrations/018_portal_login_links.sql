-- Enlaces mágicos al portal (login sin contraseña)
CREATE TABLE IF NOT EXISTS `portal_login_links` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `media_user_id` BIGINT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `purpose` VARCHAR(20) NOT NULL DEFAULT 'home',
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME NULL,
    `last_used_at` DATETIME NULL,
    `use_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_portal_login_links_hash` (`token_hash`),
    KEY `idx_portal_login_links_user` (`media_user_id`),
    KEY `idx_portal_login_links_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
