-- Outgoing webhook endpoints
CREATE TABLE IF NOT EXISTS `webhook_endpoints` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `url` VARCHAR(500) NOT NULL,
    `secret` VARCHAR(255) NULL,
    `events` JSON NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_triggered_at` DATETIME NULL,
    `last_status` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_webhooks_tenant` (`tenant_id`),
    CONSTRAINT `fk_webhooks_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `endpoint_id` INT UNSIGNED NOT NULL,
    `event` VARCHAR(100) NOT NULL,
    `payload` JSON NOT NULL,
    `response_code` INT NULL,
    `response_body` TEXT NULL,
    `status` ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_deliveries_endpoint` (`endpoint_id`),
    CONSTRAINT `fk_deliveries_endpoint` FOREIGN KEY (`endpoint_id`) REFERENCES `webhook_endpoints` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GDPR data export requests
CREATE TABLE IF NOT EXISTS `gdpr_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NULL,
    `media_user_id` BIGINT UNSIGNED NULL,
    `type` ENUM('export','delete') NOT NULL,
    `status` ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    `file_path` VARCHAR(500) NULL,
    `completed_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_gdpr_tenant` (`tenant_id`),
    KEY `idx_gdpr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
