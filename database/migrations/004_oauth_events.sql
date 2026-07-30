-- OAuth SSO accounts linked to panel users
CREATE TABLE IF NOT EXISTS `oauth_accounts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `provider` VARCHAR(50) NOT NULL,
    `provider_user_id` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NULL,
    `avatar` VARCHAR(500) NULL,
    `access_token` TEXT NULL,
    `refresh_token` TEXT NULL,
    `expires_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_oauth_provider_user` (`provider`, `provider_user_id`),
    KEY `idx_oauth_user` (`user_id`),
    CONSTRAINT `fk_oauth_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
