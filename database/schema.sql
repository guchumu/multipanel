-- MultiPanel ERP - Database Schema
-- MySQL 8.0+
-- Version: 1.0.0

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `multipanel`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `multipanel`;

-- ============================================================
-- CORE: Tenants (Multi-empresa)
-- ============================================================

CREATE TABLE IF NOT EXISTS `tenants` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `domain` VARCHAR(255) NULL,
    `logo` VARCHAR(500) NULL,
    `status` ENUM('active','suspended','trial','cancelled') NOT NULL DEFAULT 'active',
    `plan` VARCHAR(50) NOT NULL DEFAULT 'free',
    `settings` JSON NULL,
    `license_key` VARCHAR(255) NULL,
    `license_expires_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tenants_uuid` (`uuid`),
    UNIQUE KEY `uk_tenants_slug` (`slug`),
    KEY `idx_tenants_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- RBAC: Roles & Permissions
-- ============================================================

CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NULL,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `level` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_roles_tenant_slug` (`tenant_id`, `slug`),
    KEY `idx_roles_tenant` (`tenant_id`),
    CONSTRAINT `fk_roles_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `description` TEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_permissions_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- USERS: Panel administrators & staff
-- ============================================================

CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NULL,
    `uuid` CHAR(36) NOT NULL,
    `role_id` INT UNSIGNED NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `username` VARCHAR(100) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `first_name` VARCHAR(100) NULL,
    `last_name` VARCHAR(100) NULL,
    `avatar` VARCHAR(500) NULL,
    `phone` VARCHAR(30) NULL,
    `locale` VARCHAR(10) NOT NULL DEFAULT 'es',
    `timezone` VARCHAR(50) NOT NULL DEFAULT 'Europe/Madrid',
    `status` ENUM('active','inactive','suspended','pending') NOT NULL DEFAULT 'pending',
    `email_verified_at` DATETIME NULL,
    `two_factor_secret` VARCHAR(255) NULL,
    `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `two_factor_recovery` JSON NULL,
    `last_login_at` DATETIME NULL,
    `last_login_ip` VARCHAR(45) NULL,
    `password_changed_at` DATETIME NULL,
    `remember_token` VARCHAR(100) NULL,
    `metadata` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_uuid` (`uuid`),
    UNIQUE KEY `uk_users_tenant_email` (`tenant_id`, `email`),
    UNIQUE KEY `uk_users_tenant_username` (`tenant_id`, `username`),
    KEY `idx_users_role` (`role_id`),
    KEY `idx_users_status` (`status`),
    KEY `idx_users_tenant` (`tenant_id`),
    CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` TEXT NULL,
    `device` VARCHAR(100) NULL,
    `last_activity` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sessions_token` (`token`),
    KEY `idx_sessions_user` (`user_id`),
    KEY `idx_sessions_expires` (`expires_at`),
    CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `channel` ENUM('email','telegram') NOT NULL DEFAULT 'email',
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_password_resets_email` (`email`),
    KEY `idx_password_resets_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MEDIA SERVERS: Plex & Jellyfin
-- ============================================================

CREATE TABLE IF NOT EXISTS `servers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `uuid` CHAR(36) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `type` ENUM('plex','jellyfin') NOT NULL,
    `url` VARCHAR(500) NOT NULL,
    `port` INT UNSIGNED NOT NULL DEFAULT 32400,
    `ssl` TINYINT(1) NOT NULL DEFAULT 0,
    `token` TEXT NULL,
    `api_key` TEXT NULL,
    `machine_id` VARCHAR(255) NULL,
    `version` VARCHAR(50) NULL,
    `icon` VARCHAR(500) NULL,
    `location` VARCHAR(255) NULL,
    `status` ENUM('online','offline','error','maintenance','syncing') NOT NULL DEFAULT 'offline',
    `health_score` TINYINT UNSIGNED NOT NULL DEFAULT 100,
    `cpu_usage` DECIMAL(5,2) NULL,
    `ram_usage` DECIMAL(5,2) NULL,
    `disk_usage` DECIMAL(5,2) NULL,
    `disk_total_gb` DECIMAL(12,2) NULL,
    `disk_used_gb` DECIMAL(12,2) NULL,
    `bandwidth_mbps` DECIMAL(10,2) NULL,
    `active_sessions` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_users` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_libraries` INT UNSIGNED NOT NULL DEFAULT 0,
    `check_interval_minutes` INT UNSIGNED NOT NULL DEFAULT 5,
    `last_sync_at` DATETIME NULL,
    `last_check_at` DATETIME NULL,
    `last_error` TEXT NULL,
    `settings` JSON NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_servers_uuid` (`uuid`),
    KEY `idx_servers_tenant` (`tenant_id`),
    KEY `idx_servers_type` (`type`),
    KEY `idx_servers_status` (`status`),
    CONSTRAINT `fk_servers_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `libraries` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` BIGINT UNSIGNED NOT NULL,
    `external_id` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `path` TEXT NULL,
    `item_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `metadata` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_libraries_server_external` (`server_id`, `external_id`),
    KEY `idx_libraries_server` (`server_id`),
    CONSTRAINT `fk_libraries_server` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MEDIA USERS: Managed Plex/Jellyfin accounts
-- ============================================================

CREATE TABLE IF NOT EXISTS `media_users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `uuid` CHAR(36) NOT NULL,
    `server_id` BIGINT UNSIGNED NULL,
    `external_id` VARCHAR(255) NULL,
    `on_server` TINYINT(1) NULL DEFAULT NULL,
    `membership_synced_at` DATETIME NULL,
    `email` VARCHAR(255) NULL,
    `telegram_chat_id` VARCHAR(50) NULL,
    `username` VARCHAR(100) NOT NULL,
    `password` VARCHAR(255) NULL,
    `jellyfin_password_encrypted` TEXT NULL,
    `display_name` VARCHAR(255) NULL,
    `avatar` VARCHAR(500) NULL,
    `status` ENUM('active','suspended','pending','invited','blocked','expired','deleted') NOT NULL DEFAULT 'pending',
    `role` ENUM('admin','user','guest','kid') NOT NULL DEFAULT 'user',
    `locale` VARCHAR(10) NOT NULL DEFAULT 'es',
    `timezone` VARCHAR(50) NOT NULL DEFAULT 'Europe/Madrid',
    `max_streams` TINYINT UNSIGNED NULL DEFAULT NULL,
    `max_devices` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `max_quality` VARCHAR(20) NULL,
    `parental_control` TINYINT(1) NOT NULL DEFAULT 0,
    `parental_pin` VARCHAR(10) NULL,
    `expires_at` DATETIME NULL,
    `invited_at` DATETIME NULL,
    `invitation_token` VARCHAR(255) NULL,
    `last_login_at` DATETIME NULL,
    `last_playback_at` DATETIME NULL,
    `last_login_ip` VARCHAR(45) NULL,
    `last_country` VARCHAR(2) NULL,
    `last_device` VARCHAR(255) NULL,
    `total_plays` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_hours` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `notes` TEXT NULL,
    `metadata` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_media_users_uuid` (`uuid`),
    KEY `idx_media_users_tenant` (`tenant_id`),
    KEY `idx_media_users_server` (`server_id`),
    KEY `idx_media_users_status` (`status`),
    KEY `idx_media_users_email` (`email`),
    KEY `idx_media_users_expires` (`expires_at`),
    CONSTRAINT `fk_media_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_media_users_server` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `media_user_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `media_user_id` BIGINT UNSIGNED NULL,
    `channel` VARCHAR(30) NOT NULL DEFAULT 'telegram',
    `message_type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NULL,
    `body` TEXT NOT NULL,
    `telegram_chat_id` VARCHAR(50) NULL,
    `status` ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_messages_user` (`media_user_id`),
    KEY `idx_messages_chat` (`telegram_chat_id`),
    KEY `idx_messages_sent` (`sent_at`),
    CONSTRAINT `fk_messages_media_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `playback_stop_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(120) NOT NULL,
    `body` TEXT NOT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_playback_stop_messages_tenant` (`tenant_id`),
    KEY `idx_playback_stop_messages_default` (`tenant_id`, `is_default`),
    CONSTRAINT `fk_playback_stop_messages_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `media_user_libraries` (
    `media_user_id` BIGINT UNSIGNED NOT NULL,
    `library_id` BIGINT UNSIGNED NOT NULL,
    `access_type` ENUM('allow','deny') NOT NULL DEFAULT 'allow',
    PRIMARY KEY (`media_user_id`, `library_id`),
    CONSTRAINT `fk_mul_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mul_library` FOREIGN KEY (`library_id`) REFERENCES `libraries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tags` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `color` VARCHAR(7) NOT NULL DEFAULT '#6c757d',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tags_tenant_name` (`tenant_id`, `name`),
    CONSTRAINT `fk_tags_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `media_user_tags` (
    `media_user_id` BIGINT UNSIGNED NOT NULL,
    `tag_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`media_user_id`, `tag_id`),
    CONSTRAINT `fk_mut_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mut_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groups` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `settings` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_groups_tenant_name` (`tenant_id`, `name`),
    CONSTRAINT `fk_groups_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `media_user_groups` (
    `media_user_id` BIGINT UNSIGNED NOT NULL,
    `group_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`media_user_id`, `group_id`),
    CONSTRAINT `fk_mug_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mug_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CRM & BILLING
-- ============================================================

CREATE TABLE IF NOT EXISTS `customers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `uuid` CHAR(36) NOT NULL,
    `media_user_id` BIGINT UNSIGNED NULL,
    `email` VARCHAR(255) NOT NULL,
    `first_name` VARCHAR(100) NULL,
    `last_name` VARCHAR(100) NULL,
    `company` VARCHAR(255) NULL,
    `phone` VARCHAR(30) NULL,
    `address` TEXT NULL,
    `city` VARCHAR(100) NULL,
    `country` VARCHAR(2) NULL,
    `tax_id` VARCHAR(50) NULL,
    `status` ENUM('active','inactive','prospect','churned') NOT NULL DEFAULT 'prospect',
    `notes` TEXT NULL,
    `metadata` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_customers_uuid` (`uuid`),
    KEY `idx_customers_tenant` (`tenant_id`),
    KEY `idx_customers_media_user` (`media_user_id`),
    CONSTRAINT `fk_customers_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_customers_media_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_plans` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `currency` CHAR(3) NOT NULL DEFAULT 'EUR',
    `interval` ENUM('daily','weekly','monthly','quarterly','yearly','lifetime') NOT NULL DEFAULT 'monthly',
    `trial_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `max_streams` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `max_devices` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `features` JSON NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_plans_tenant_slug` (`tenant_id`, `slug`),
    CONSTRAINT `fk_plans_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `customer_id` BIGINT UNSIGNED NOT NULL,
    `plan_id` INT UNSIGNED NOT NULL,
    `media_user_id` BIGINT UNSIGNED NULL,
    `status` ENUM('active','trialing','past_due','cancelled','expired','paused') NOT NULL DEFAULT 'trialing',
    `gateway` ENUM('stripe','paypal','manual','crypto','bizum') NULL,
    `gateway_id` VARCHAR(255) NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `currency` CHAR(3) NOT NULL DEFAULT 'EUR',
    `starts_at` DATETIME NOT NULL,
    `ends_at` DATETIME NULL,
    `trial_ends_at` DATETIME NULL,
    `cancelled_at` DATETIME NULL,
    `metadata` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_subscriptions_tenant` (`tenant_id`),
    KEY `idx_subscriptions_customer` (`customer_id`),
    KEY `idx_subscriptions_status` (`status`),
    KEY `idx_subscriptions_ends` (`ends_at`),
    CONSTRAINT `fk_subscriptions_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_subscriptions_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_subscriptions_plan` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`),
    CONSTRAINT `fk_subscriptions_media_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `customer_id` BIGINT UNSIGNED NOT NULL,
    `subscription_id` BIGINT UNSIGNED NULL,
    `number` VARCHAR(50) NOT NULL,
    `status` ENUM('draft','sent','paid','overdue','cancelled','refunded') NOT NULL DEFAULT 'draft',
    `subtotal` DECIMAL(10,2) NOT NULL,
    `tax` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `total` DECIMAL(10,2) NOT NULL,
    `currency` CHAR(3) NOT NULL DEFAULT 'EUR',
    `due_date` DATE NULL,
    `paid_at` DATETIME NULL,
    `gateway` VARCHAR(50) NULL,
    `gateway_payment_id` VARCHAR(255) NULL,
    `pdf_path` VARCHAR(500) NULL,
    `metadata` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_invoices_number` (`tenant_id`, `number`),
    KEY `idx_invoices_customer` (`customer_id`),
    KEY `idx_invoices_status` (`status`),
    CONSTRAINT `fk_invoices_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_invoices_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_invoices_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AUTOMATION
-- ============================================================

CREATE TABLE IF NOT EXISTS `automation_rules` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `trigger_event` VARCHAR(100) NOT NULL,
    `conditions` JSON NOT NULL,
    `actions` JSON NOT NULL,
    `priority` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_run_at` DATETIME NULL,
    `run_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_automation_tenant` (`tenant_id`),
    KEY `idx_automation_trigger` (`trigger_event`),
    KEY `idx_automation_active` (`is_active`),
    CONSTRAINT `fk_automation_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NULL,
    `queue` VARCHAR(50) NOT NULL DEFAULT 'default',
    `type` VARCHAR(100) NOT NULL,
    `payload` JSON NOT NULL,
    `status` ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `error` TEXT NULL,
    `scheduled_at` DATETIME NULL,
    `started_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_jobs_queue_status` (`queue`, `status`),
    KEY `idx_jobs_scheduled` (`scheduled_at`),
    KEY `idx_jobs_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- NOTIFICATIONS
-- ============================================================

CREATE TABLE IF NOT EXISTS `notification_channels` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `type` ENUM('email','telegram','discord','slack','webhook','push') NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `config` JSON NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nc_tenant` (`tenant_id`),
    CONSTRAINT `fk_nc_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NULL,
    `channel_id` INT UNSIGNED NULL,
    `type` VARCHAR(100) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `data` JSON NULL,
    `status` ENUM('pending','sent','failed','read') NOT NULL DEFAULT 'pending',
    `sent_at` DATETIME NULL,
    `read_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_tenant` (`tenant_id`),
    KEY `idx_notifications_user` (`user_id`),
    KEY `idx_notifications_status` (`status`),
    CONSTRAINT `fk_notifications_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SUPPORT TICKETS
-- ============================================================

CREATE TABLE IF NOT EXISTS `tickets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `uuid` CHAR(36) NOT NULL,
    `customer_id` BIGINT UNSIGNED NULL,
    `assigned_to` BIGINT UNSIGNED NULL,
    `subject` VARCHAR(255) NOT NULL,
    `status` ENUM('open','in_progress','waiting','resolved','closed') NOT NULL DEFAULT 'open',
    `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `category` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `closed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tickets_uuid` (`uuid`),
    KEY `idx_tickets_tenant` (`tenant_id`),
    KEY `idx_tickets_status` (`status`),
    CONSTRAINT `fk_tickets_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tickets_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tickets_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NULL,
    `customer_id` BIGINT UNSIGNED NULL,
    `message` TEXT NOT NULL,
    `is_internal` TINYINT(1) NOT NULL DEFAULT 0,
    `attachments` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tm_ticket` (`ticket_id`),
    CONSTRAINT `fk_tm_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- STATISTICS & MONITORING
-- ============================================================

CREATE TABLE IF NOT EXISTS `server_stats` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` BIGINT UNSIGNED NOT NULL,
    `cpu_usage` DECIMAL(5,2) NULL,
    `ram_usage` DECIMAL(5,2) NULL,
    `disk_usage` DECIMAL(5,2) NULL,
    `bandwidth_mbps` DECIMAL(10,2) NULL,
    `active_sessions` INT UNSIGNED NOT NULL DEFAULT 0,
    `online_users` INT UNSIGNED NOT NULL DEFAULT 0,
    `recorded_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ss_server_date` (`server_id`, `recorded_at`),
    CONSTRAINT `fk_ss_server` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `playback_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `server_id` BIGINT UNSIGNED NOT NULL,
    `media_user_id` BIGINT UNSIGNED NULL,
    `external_session_id` VARCHAR(255) NULL,
    `title` VARCHAR(500) NULL,
    `media_type` VARCHAR(50) NULL,
    `player` VARCHAR(100) NULL,
    `device` VARCHAR(255) NULL,
    `ip_address` VARCHAR(45) NULL,
    `country` VARCHAR(2) NULL,
    `quality` VARCHAR(20) NULL,
    `started_at` DATETIME NOT NULL,
    `ended_at` DATETIME NULL,
    `duration_seconds` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ps_tenant` (`tenant_id`),
    KEY `idx_ps_server` (`server_id`),
    KEY `idx_ps_user` (`media_user_id`),
    KEY `idx_ps_started` (`started_at`),
    CONSTRAINT `fk_ps_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ps_server` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AUDIT & LOGS
-- ============================================================

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NULL,
    `user_id` BIGINT UNSIGNED NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(100) NULL,
    `entity_id` BIGINT UNSIGNED NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_tenant` (`tenant_id`),
    KEY `idx_audit_user` (`user_id`),
    KEY `idx_audit_action` (`action`),
    KEY `idx_audit_entity` (`entity_type`, `entity_id`),
    KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NULL,
    `level` ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
    `channel` VARCHAR(50) NOT NULL DEFAULT 'app',
    `message` TEXT NOT NULL,
    `context` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_slogs_level` (`level`),
    KEY `idx_slogs_tenant` (`tenant_id`),
    KEY `idx_slogs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SECURITY
-- ============================================================

CREATE TABLE IF NOT EXISTS `ip_blacklist` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `reason` VARCHAR(255) NULL,
    `expires_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_blacklist_ip` (`tenant_id`, `ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_keys` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `key_hash` VARCHAR(255) NOT NULL,
    `key_prefix` VARCHAR(10) NOT NULL,
    `permissions` JSON NULL,
    `last_used_at` DATETIME NULL,
    `expires_at` DATETIME NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_api_keys_tenant` (`tenant_id`),
    KEY `idx_api_keys_hash` (`key_hash`),
    CONSTRAINT `fk_api_keys_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_api_keys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SETTINGS & CONFIGURATION
-- ============================================================

CREATE TABLE IF NOT EXISTS `settings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NULL,
    `group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT NULL,
    `type` ENUM('string','integer','boolean','json','encrypted') NOT NULL DEFAULT 'string',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_settings_tenant_key` (`tenant_id`, `group`, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `backups` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NULL,
    `filename` VARCHAR(255) NOT NULL,
    `path` VARCHAR(500) NOT NULL,
    `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `type` ENUM('full','incremental','database','files') NOT NULL DEFAULT 'full',
    `status` ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
    `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
    `remote_path` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_backups_tenant` (`tenant_id`),
    KEY `idx_backups_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plugins` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `version` VARCHAR(20) NOT NULL,
    `description` TEXT NULL,
    `author` VARCHAR(100) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `config` JSON NULL,
    `installed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_plugins_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `migration` VARCHAR(255) NOT NULL,
    `batch` INT NOT NULL,
    `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `stream_limit_violations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `media_user_id` BIGINT UNSIGNED NULL,
    `server_id` BIGINT UNSIGNED NULL,
    `username` VARCHAR(255) NULL,
    `stream_count` INT UNSIGNED NOT NULL,
    `stream_limit` INT UNSIGNED NOT NULL,
    `session_ids` JSON NULL,
    `killed_session_ids` JSON NULL,
    `titles` JSON NULL,
    `client_ips` JSON NULL,
    `action` VARCHAR(40) NOT NULL DEFAULT 'kill_newest',
    `message` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_stream_limit_violations_tenant` (`tenant_id`, `created_at`),
    KEY `idx_stream_limit_violations_user` (`media_user_id`, `created_at`),
    KEY `idx_stream_limit_violations_server` (`server_id`),
    CONSTRAINT `fk_stream_limit_violations_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_stream_limit_violations_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_stream_limit_violations_server` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO `tenants` (`uuid`, `name`, `slug`, `status`, `plan`) VALUES
(UUID(), 'Default Tenant', 'default', 'active', 'enterprise');

INSERT INTO `roles` (`tenant_id`, `name`, `slug`, `description`, `is_system`, `level`) VALUES
(NULL, 'Super Administrador', 'super_admin', 'Acceso total al sistema', 1, 100),
(1, 'Administrador', 'admin', 'Administrador del tenant', 1, 80),
(1, 'Operador', 'operator', 'Operaciones diarias', 1, 60),
(1, 'Soporte', 'support', 'Atención al cliente', 1, 40),
(1, 'Cliente', 'client', 'Portal de autoservicio', 1, 20),
(1, 'Invitado', 'guest', 'Acceso limitado', 1, 10);

INSERT INTO `permissions` (`name`, `slug`, `group`) VALUES
('Ver dashboard', 'dashboard.view', 'dashboard'),
('Gestionar servidores', 'servers.manage', 'servers'),
('Ver servidores', 'servers.view', 'servers'),
('Gestionar usuarios media', 'media_users.manage', 'media_users'),
('Ver usuarios media', 'media_users.view', 'media_users'),
('Gestionar clientes', 'customers.manage', 'crm'),
('Gestionar facturación', 'billing.manage', 'billing'),
('Gestionar automatizaciones', 'automation.manage', 'automation'),
('Ver logs', 'logs.view', 'logs'),
('Gestionar configuración', 'settings.manage', 'settings'),
('Gestionar roles', 'roles.manage', 'security'),
('Gestionar API keys', 'api.manage', 'api'),
('Gestionar backups', 'backups.manage', 'backups'),
('Gestionar tickets', 'tickets.manage', 'support'),
('Ver estadísticas', 'stats.view', 'stats');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions`;

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions`;

SET FOREIGN_KEY_CHECKS = 1;
