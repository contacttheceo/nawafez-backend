-- Run this in phpMyAdmin > SQL tab on your Freehostia database
-- Equivalent to migration 2024_07_01_000001_create_audit_logs_table.php

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    BIGINT UNSIGNED NOT NULL,
  `action`      VARCHAR(60)     NOT NULL,
  `target_type` VARCHAR(40)     NULL DEFAULT NULL COMMENT 'listing | user | verification | report',
  `target_id`   BIGINT UNSIGNED NULL DEFAULT NULL,
  `metadata`    JSON            NULL DEFAULT NULL COMMENT 'reason, before/after values, etc.',
  `ip_address`  VARCHAR(45)     NULL DEFAULT NULL,
  `user_agent`  TEXT            NULL DEFAULT NULL,
  `created_at`  TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`  TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_admin_id_index`       (`admin_id`),
  KEY `audit_logs_action_index`         (`action`),
  KEY `audit_logs_target_index`         (`target_type`, `target_id`),
  KEY `audit_logs_created_at_index`     (`created_at`),
  CONSTRAINT `audit_logs_admin_id_foreign`
    FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add suspended_at + suspend_reason to users (for suspend feature)
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `suspended_at`     TIMESTAMP NULL DEFAULT NULL AFTER `is_trusted_payer`,
  ADD COLUMN IF NOT EXISTS `suspend_reason`   VARCHAR(500) NULL DEFAULT NULL AFTER `suspended_at`;

CREATE INDEX IF NOT EXISTS `users_suspended_at_index` ON `users` (`suspended_at`);
