-- PDF Viewer Platform - Database Schema
-- github.com/senthilnasa/pdf-viewer
-- Compatible with MySQL 5.7+ / MariaDB 10.3+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- --------------------------------------------------------
-- Users Table
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`            VARCHAR(120) NOT NULL,
    `email`           VARCHAR(191) NOT NULL,
    `email_verified`    TINYINT(1) NOT NULL DEFAULT 0,
    `email_verified_at` DATETIME DEFAULT NULL,
    `password`        VARCHAR(255) DEFAULT NULL COMMENT 'NULL for OAuth-only accounts',
    `role`            ENUM('admin','editor','viewer') NOT NULL DEFAULT 'viewer',
    `auth_provider`   ENUM('local','google') NOT NULL DEFAULT 'local',
    `google_id`       VARCHAR(100) DEFAULT NULL,
    `avatar`          VARCHAR(255) DEFAULT NULL,
    `status`          ENUM('active','inactive','invited') NOT NULL DEFAULT 'active',
    `invite_token`    VARCHAR(64) DEFAULT NULL,
    `reset_token`     VARCHAR(64) DEFAULT NULL,
    `reset_expires`   DATETIME DEFAULT NULL,
    `last_login`      DATETIME DEFAULT NULL,
    `login_attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until`    DATETIME DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email` (`email`),
    UNIQUE KEY `uq_google_id` (`google_id`),
    KEY `idx_invite_token` (`invite_token`),
    KEY `idx_reset_token` (`reset_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Categories Table (unlimited parent/child hierarchy via self-reference)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id`  INT UNSIGNED DEFAULT NULL COMMENT 'NULL = top-level parent category',
    `name`       VARCHAR(150) NOT NULL,
    `slug`       VARCHAR(160) NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`),
    KEY `idx_parent_id` (`parent_id`),
    CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- PDF Documents Table
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pdf_documents` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`          VARCHAR(255) NOT NULL,
    `description`    TEXT DEFAULT NULL,
    `slug`           VARCHAR(255) NOT NULL,
    `file_path`      VARCHAR(500) NOT NULL,
    `file_size`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `page_count`     INT UNSIGNED NOT NULL DEFAULT 0,
    `visibility`     ENUM('public','private') NOT NULL DEFAULT 'public',
    `status`         ENUM('active','inactive','processing') NOT NULL DEFAULT 'active',
    `meta_title`     VARCHAR(255) DEFAULT NULL,
    `meta_desc`      VARCHAR(500) DEFAULT NULL,
    `thumbnail`      VARCHAR(500) DEFAULT NULL,
    `enable_download`TINYINT(1) NOT NULL DEFAULT 1,
    `show_in_catalog`TINYINT(1) NOT NULL DEFAULT 1,
    `category_id`    INT UNSIGNED DEFAULT NULL,
    `created_by`     INT UNSIGNED NOT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`),
    KEY `idx_status_visibility` (`status`, `visibility`),
    KEY `idx_created_by` (`created_by`),
    KEY `idx_category_id` (`category_id`),
    KEY `idx_catalog` (`show_in_catalog`, `status`, `visibility`),
    CONSTRAINT `fk_pdf_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_pdf_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- PDF Views Table (visit-level analytics)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pdf_views` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pdf_id`      INT UNSIGNED NOT NULL,
    `visitor_ip`  VARCHAR(45) NOT NULL COMMENT 'IPv4 or IPv6',
    `user_agent`  VARCHAR(512) DEFAULT NULL,
    `referrer`    VARCHAR(512) DEFAULT NULL,
    `user_id`     INT UNSIGNED DEFAULT NULL COMMENT 'NULL for anonymous',
    `session_id`  VARCHAR(64) DEFAULT NULL,
    `visit_time`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pdf_id` (`pdf_id`),
    KEY `idx_visit_time` (`visit_time`),
    KEY `idx_visitor_ip` (`visitor_ip`),
    KEY `idx_pdf_date` (`pdf_id`, `visit_time`),
    CONSTRAINT `fk_view_pdf` FOREIGN KEY (`pdf_id`) REFERENCES `pdf_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- PDF Page Views Table (page-level analytics)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pdf_page_views` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pdf_id`      INT UNSIGNED NOT NULL,
    `page_number` SMALLINT UNSIGNED NOT NULL,
    `visitor_ip`  VARCHAR(45) NOT NULL,
    `session_id`  VARCHAR(64) DEFAULT NULL,
    `viewed_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pdf_page` (`pdf_id`, `page_number`),
    KEY `idx_viewed_at` (`viewed_at`),
    CONSTRAINT `fk_pv_pdf` FOREIGN KEY (`pdf_id`) REFERENCES `pdf_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Share Links Table
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `share_links` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pdf_id`      INT UNSIGNED NOT NULL,
    `token`       VARCHAR(64) NOT NULL,
    `password`    VARCHAR(255) DEFAULT NULL COMMENT 'Hashed password, optional',
    `max_views`   INT UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited',
    `view_count`  INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at`  DATETIME DEFAULT NULL COMMENT 'NULL = never expires',
    `created_by`  INT UNSIGNED NOT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token` (`token`),
    KEY `idx_pdf_id` (`pdf_id`),
    CONSTRAINT `fk_sl_pdf` FOREIGN KEY (`pdf_id`) REFERENCES `pdf_documents` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sl_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Settings Table (key-value store)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`        VARCHAR(100) NOT NULL,
    `value`      TEXT DEFAULT NULL,
    `type`       ENUM('string','boolean','integer','json') NOT NULL DEFAULT 'string',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Login Attempts Table (rate limiting)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address`  VARCHAR(45) NOT NULL,
    `email`       VARCHAR(191) NOT NULL,
    `attempted_at`DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ip_time` (`ip_address`, `attempted_at`),
    KEY `idx_email_time` (`email`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Audit Logs Table
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `action`         VARCHAR(50) NOT NULL,
    `user_id`        INT UNSIGNED DEFAULT NULL,
    `resource_type`  VARCHAR(50) DEFAULT NULL,
    `resource_id`    INT UNSIGNED DEFAULT NULL,
    `metadata`       JSON DEFAULT NULL,
    `ip_address`     VARCHAR(45) NOT NULL,
    `user_agent`     VARCHAR(512) DEFAULT NULL,
    `logged_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_action` (`action`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_logged_at` (`logged_at`),
    KEY `idx_resource` (`resource_type`, `resource_id`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- User Sessions Table
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `session_token`   VARCHAR(255) NOT NULL,
    `ip_address`      VARCHAR(45) NOT NULL,
    `user_agent`      VARCHAR(512) DEFAULT NULL,
    `last_activity`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`      DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token` (`session_token`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_expires_at` (`expires_at`),
    CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Email Verification Tokens
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_verifications` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `email`           VARCHAR(191) NOT NULL,
    `token`           VARCHAR(64) NOT NULL,
    `verified_at`     DATETIME DEFAULT NULL,
    `expires_at`      DATETIME NOT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token` (`token`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_email` (`email`),
    CONSTRAINT `fk_verify_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- In-App Notifications
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `type`            VARCHAR(50) NOT NULL,
    `title`           VARCHAR(255) NOT NULL,
    `message`         TEXT NOT NULL,
    `action_url`      VARCHAR(500) DEFAULT NULL,
    `read_at`         DATETIME DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`      DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_read_at` (`read_at`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Email Queue
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_queue` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `to_email`        VARCHAR(191) NOT NULL,
    `subject`         VARCHAR(255) NOT NULL,
    `html_body`       LONGTEXT NOT NULL,
    `plain_body`      LONGTEXT,
    `metadata`        JSON DEFAULT NULL,
    `status`          ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_attempt_at` DATETIME DEFAULT NULL,
    `error_message`   TEXT DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at`         DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_next_retry` (`status`, `last_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- User Preferences
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_preferences` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `key`             VARCHAR(100) NOT NULL,
    `value`           TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_key` (`user_id`, `key`),
    CONSTRAINT `fk_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Scheduled Tasks Table
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scheduled_tasks` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `task_name`     VARCHAR(100) NOT NULL,
    `payload`       JSON DEFAULT NULL,
    `status`        ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    `result`        JSON DEFAULT NULL,
    `error`         TEXT DEFAULT NULL,
    `scheduled_for` DATETIME DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `started_at`    DATETIME DEFAULT NULL,
    `completed_at`  DATETIME DEFAULT NULL,
    `failed_at`     DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_scheduled` (`status`, `scheduled_for`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------
-- Default Settings
-- --------------------------------------------------------
INSERT INTO `settings` (`key`, `value`, `type`) VALUES
('site_name',           'PDF Viewer',   'string'),
('enable_public_view',  '1',            'boolean'),
('analytics_enabled',   '1',            'boolean'),
('enable_download',     '1',            'boolean'),
('enable_flipbook',     '0',            'boolean'),
('ga_measurement_id',   '',             'string'),
('google_oauth_enabled','0',            'boolean'),
('google_client_id',    '',             'string'),
('google_client_secret','',             'string'),
('google_allowed_domains','[]',         'json'),
('cloudflare_token',    '',             'string'),
('demo_mode',           '0',            'boolean'),
('email_provider',      'smtp',         'string'),
('email_from',          '',             'string'),
('email_from_name',     '',             'string'),
('smtp_host',           '',             'string'),
('smtp_port',           '587',          'integer'),
('smtp_username',       '',             'string'),
('smtp_password',       '',             'string'),
('smtp_encryption',     'tls',          'string')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
