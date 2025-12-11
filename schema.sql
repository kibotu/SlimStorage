-- SlimStorage Database Schema
-- This file creates all necessary tables for SlimStorage
-- Table prefix: slimstore_ (can be customized in .secrets.yml)

-- Disable foreign key checks during table creation
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- API Keys Table
-- Stores API keys for user authentication
-- ============================================================================
CREATE TABLE IF NOT EXISTS `slimstore_api_keys` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `api_key` VARCHAR(64) NOT NULL UNIQUE,
    `name` VARCHAR(64) NULL,
    `email` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` DATETIME NULL,
    INDEX `idx_api_key` (`api_key`),
    INDEX `idx_email` (`email`),
    INDEX `idx_email_created` (`email`, `created_at` DESC),
    INDEX `idx_email_last_used` (`email`, `last_used_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Key/Value Store Table
-- Stores user data as key-value pairs
-- ============================================================================
CREATE TABLE IF NOT EXISTS `slimstore_kv_store` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `api_key_id` INT UNSIGNED NOT NULL,
    `key` VARCHAR(255) NOT NULL,
    `value` MEDIUMTEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_api_key_key` (`api_key_id`, `key`),
    INDEX `idx_api_key_id` (`api_key_id`),
    FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Events Table
-- Stores time-series event data
-- ============================================================================
CREATE TABLE IF NOT EXISTS `slimstore_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `api_key_id` INT UNSIGNED NOT NULL,
    `event_data` JSON NOT NULL,
    `event_timestamp` DATETIME(3) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_api_key_id` (`api_key_id`),
    INDEX `idx_event_timestamp` (`event_timestamp`),
    INDEX `idx_api_key_timestamp` (`api_key_id`, `event_timestamp`),
    FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Sessions Table
-- Stores user login sessions
-- ============================================================================
CREATE TABLE IF NOT EXISTS `slimstore_sessions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `session_id` VARCHAR(64) NOT NULL UNIQUE,
    `email` VARCHAR(255) NOT NULL,
    `photo_url` VARCHAR(512) NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_session_id` (`session_id`),
    INDEX `idx_expires_at` (`expires_at`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Rate Limits Table
-- Tracks API rate limiting per IP address
-- ============================================================================
CREATE TABLE IF NOT EXISTS `slimstore_rate_limits` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL UNIQUE,
    `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `window_start` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_ip_address` (`ip_address`),
    INDEX `idx_window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- API Logs Table
-- Stores API request logs for analytics
-- ============================================================================
CREATE TABLE IF NOT EXISTS `slimstore_api_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `api_key_id` INT UNSIGNED NOT NULL,
    `endpoint` VARCHAR(50) NOT NULL,
    `method` VARCHAR(10) NOT NULL,
    `status_code` SMALLINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_api_key_id` (`api_key_id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_api_key_date` (`api_key_id`, `created_at`),
    FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Event Stats Table
-- Pre-computed daily event statistics
-- ============================================================================
CREATE TABLE IF NOT EXISTS `slimstore_event_stats` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `api_key_id` INT UNSIGNED NOT NULL,
    `stat_date` DATE NOT NULL,
    `event_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `sum_cpm` DECIMAL(20,4) NULL DEFAULT 0,
    `sum_usvh` DECIMAL(20,6) NULL DEFAULT 0,
    `min_cpm` DECIMAL(10,4) NULL,
    `max_cpm` DECIMAL(10,4) NULL,
    `min_usvh` DECIMAL(10,6) NULL,
    `max_usvh` DECIMAL(10,6) NULL,
    `cpm_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `usvh_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_api_key_date` (`api_key_id`, `stat_date`),
    INDEX `idx_api_key_id` (`api_key_id`),
    INDEX `idx_stat_date` (`stat_date`),
    FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- API Key Stats Table
-- Pre-computed API key statistics
-- ============================================================================
CREATE TABLE IF NOT EXISTS `slimstore_api_key_stats` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `api_key_id` INT UNSIGNED NOT NULL UNIQUE,
    `total_events` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `total_kv_pairs` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_kv_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `total_event_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `earliest_event` DATETIME(3) NULL,
    `latest_event` DATETIME(3) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_api_key_id` (`api_key_id`),
    FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Event Key Stats Table
-- Tracks common event keys for analytics
-- ============================================================================
CREATE TABLE IF NOT EXISTS `slimstore_event_key_stats` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `api_key_id` INT UNSIGNED NOT NULL,
    `event_key` VARCHAR(128) NOT NULL,
    `occurrence_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_api_key_event_key` (`api_key_id`, `event_key`),
    INDEX `idx_api_key_id` (`api_key_id`),
    INDEX `idx_occurrence_count` (`occurrence_count`),
    FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- API Logs Stats Table
-- Pre-computed request statistics
-- ============================================================================
CREATE TABLE IF NOT EXISTS `slimstore_api_logs_stats` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `api_key_id` INT UNSIGNED NOT NULL,
    `stat_date` DATE NOT NULL,
    `total_requests` INT UNSIGNED NOT NULL DEFAULT 0,
    `success_requests` INT UNSIGNED NOT NULL DEFAULT 0,
    `error_requests` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_api_key_date` (`api_key_id`, `stat_date`),
    INDEX `idx_api_key_id` (`api_key_id`),
    INDEX `idx_stat_date` (`stat_date`),
    FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- API Logs Endpoint Stats Table
-- Tracks endpoint usage
-- ============================================================================
CREATE TABLE IF NOT EXISTS `slimstore_api_logs_endpoint_stats` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `api_key_id` INT UNSIGNED NOT NULL,
    `endpoint` VARCHAR(255) NOT NULL,
    `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_request` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_api_key_endpoint` (`api_key_id`, `endpoint`),
    INDEX `idx_api_key_id` (`api_key_id`),
    INDEX `idx_request_count` (`request_count`),
    FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Event Schemas Table
-- User-defined event schemas for optimized aggregations
-- ============================================================================
CREATE TABLE IF NOT EXISTS `slimstore_event_schemas` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `api_key_id` INT UNSIGNED NOT NULL,
    `field_name` VARCHAR(64) NOT NULL,
    `field_type` ENUM('integer', 'bigint', 'float', 'double', 'string', 'boolean') NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_api_key_field` (`api_key_id`, `field_name`),
    INDEX `idx_api_key_id` (`api_key_id`),
    FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Event Aggregations Table
-- Tracks aggregation status for schemas
-- ============================================================================
CREATE TABLE IF NOT EXISTS `slimstore_event_aggregations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `api_key_id` INT UNSIGNED NOT NULL,
    `aggregation_type` ENUM('hourly', 'daily') NOT NULL,
    `status` ENUM('pending', 'building', 'active', 'error') NOT NULL DEFAULT 'pending',
    `row_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `last_updated` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_api_key_agg` (`api_key_id`, `aggregation_type`),
    INDEX `idx_api_key_id` (`api_key_id`),
    FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

