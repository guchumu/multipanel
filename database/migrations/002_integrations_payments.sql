-- Integrations table for *arr and third-party services
CREATE TABLE IF NOT EXISTS `integrations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `type` ENUM('sonarr','radarr','lidarr','prowlarr','bazarr','tautulli','overseerr','ombi') NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `url` VARCHAR(500) NOT NULL,
    `api_key` TEXT NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_check_at` DATETIME NULL,
    `last_error` TEXT NULL,
    `metadata` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_integrations_tenant` (`tenant_id`),
    KEY `idx_integrations_type` (`type`),
    CONSTRAINT `fk_integrations_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment transactions log
CREATE TABLE IF NOT EXISTS `payment_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `subscription_id` BIGINT UNSIGNED NULL,
    `customer_id` BIGINT UNSIGNED NULL,
    `gateway` ENUM('stripe','paypal','manual','crypto','bizum') NOT NULL,
    `gateway_id` VARCHAR(255) NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `currency` CHAR(3) NOT NULL DEFAULT 'EUR',
    `status` ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
    `metadata` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pt_tenant` (`tenant_id`),
    KEY `idx_pt_gateway` (`gateway`, `gateway_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
