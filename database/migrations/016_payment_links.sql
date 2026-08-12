-- Short public payment links (redirect to Stripe checkout URL)
CREATE TABLE IF NOT EXISTS `payment_links` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `media_user_id` BIGINT UNSIGNED NULL,
    `code` VARCHAR(32) NOT NULL,
    `url` TEXT NOT NULL,
    `stripe_session_id` VARCHAR(255) NULL,
    `expires_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_payment_links_code` (`code`),
    KEY `idx_payment_links_tenant` (`tenant_id`),
    KEY `idx_payment_links_media_user` (`media_user_id`),
    KEY `idx_payment_links_expires` (`expires_at`),
    CONSTRAINT `fk_payment_links_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payment_links_media_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
