-- ========================================================================
-- PDF Viewer Platform - Database Migrations
-- Add these tables and columns to your existing PDF Viewer database
-- ========================================================================

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
-- Add missing columns to existing users table
-- --------------------------------------------------------
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `email_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `email`,
    ADD COLUMN IF NOT EXISTS `email_verified_at` DATETIME DEFAULT NULL AFTER `email_verified`;

-- ========================================================================
-- Indexes for Performance
-- ========================================================================
ALTER TABLE `pdf_views` ADD KEY IF NOT EXISTS `idx_date_range` (`pdf_id`, `visit_time`);
ALTER TABLE `audit_logs` ADD KEY IF NOT EXISTS `idx_action_time` (`action`, `logged_at`);

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
