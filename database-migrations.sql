-- ========================================================================
-- PDF Viewer Platform - Database Migrations
-- Add these tables and columns to your existing PDF Viewer database
-- ========================================================================

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
-- pdf_documents: add show_in_catalog + category_id
-- Portable guarded ALTERs (see note above about MariaDB-only
-- "IF NOT EXISTS" clauses not working on vanilla MySQL).
-- --------------------------------------------------------

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = 'pdf_documents' AND column_name = 'show_in_catalog'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `pdf_documents` ADD COLUMN `show_in_catalog` TINYINT(1) NOT NULL DEFAULT 1 AFTER `enable_download`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = 'pdf_documents' AND column_name = 'category_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `pdf_documents` ADD COLUMN `category_id` INT UNSIGNED DEFAULT NULL AFTER `show_in_catalog`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = 'pdf_documents' AND index_name = 'idx_category_id'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `pdf_documents` ADD KEY `idx_category_id` (`category_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = 'pdf_documents' AND index_name = 'idx_catalog'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `pdf_documents` ADD KEY `idx_catalog` (`show_in_catalog`, `status`, `visibility`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE table_schema = DATABASE() AND table_name = 'pdf_documents' AND constraint_name = 'fk_pdf_category'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `pdf_documents` ADD CONSTRAINT `fk_pdf_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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
-- User Sessions Table (for session management)
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
-- Email Verification Tokens Table
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
-- In-App Notifications Table
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
-- Email Queue Table (for background email processing)
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
-- User Preferences Table (for notification settings)
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
-- Scheduled Tasks Table (background job queue)
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

-- ========================================================================
-- Add missing columns to existing `users` table
-- Portable across MySQL 5.7+ and MariaDB 10.3+ (ADD COLUMN IF NOT EXISTS is
-- a MariaDB-only extension and errors out on vanilla MySQL, so we guard
-- with information_schema + dynamic SQL instead).
-- ========================================================================

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'email_verified'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `email`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'email_verified_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `email_verified_at` DATETIME DEFAULT NULL AFTER `email_verified`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ========================================================================
-- Indexes for Performance (same portable guard pattern)
-- ========================================================================

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = 'pdf_views' AND index_name = 'idx_date_range'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `pdf_views` ADD KEY `idx_date_range` (`pdf_id`, `visit_time`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ========================================================================
-- Default Email / SMTP Settings
-- ========================================================================
INSERT INTO `settings` (`key`, `value`, `type`) VALUES
('email_provider',      'smtp',         'string'),
('email_from',          '',             'string'),
('email_from_name',     '',             'string'),
('smtp_host',           '',             'string'),
('smtp_port',           '587',          'integer'),
('smtp_username',       '',             'string'),
('smtp_password',       '',             'string'),
('smtp_encryption',     'tls',          'string')
ON DUPLICATE KEY UPDATE `value` = `value`;

-- ========================================================================
-- Default Preferences
-- ========================================================================
-- Note: Preferences can be set via the application UI
-- Examples of preference keys:
-- - notify_email_login
-- - notify_email_pdf_shared
-- - notify_inapp_all
-- - theme_preference
-- - email_frequency
