-- Subscription Packages Phase 1 — Foundation tables + 4 default plans.
-- Run in phpMyAdmin → SQL tab → paste → Go.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS skips existing tables, plans
-- are inserted with ON DUPLICATE KEY so codes stay unique.

-- ─── 1) Plans table ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `plans` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`           VARCHAR(30)     NOT NULL,
  `name_ar`        VARCHAR(100)    NOT NULL,
  `name_en`        VARCHAR(100)    NOT NULL,
  `tagline_ar`     VARCHAR(200)    NULL,
  `tagline_en`     VARCHAR(200)    NULL,
  `price_monthly`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `price_yearly`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `features`       JSON            NOT NULL,
  `display_order`  TINYINT         NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
  `is_default`     TINYINT(1)      NOT NULL DEFAULT 0,
  `badge_color`    VARCHAR(20)     NULL,
  `created_at`     TIMESTAMP       NULL,
  `updated_at`     TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plans_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 2) Subscriptions table ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         BIGINT UNSIGNED NOT NULL,
  `plan_id`         BIGINT UNSIGNED NOT NULL,
  `status`          ENUM('pending','active','cancelled','expired','suspended') NOT NULL DEFAULT 'pending',
  `billing_cycle`   ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `started_at`      TIMESTAMP NULL,
  `expires_at`      TIMESTAMP NULL,
  `cancelled_at`    TIMESTAMP NULL,
  `auto_renew`      TINYINT(1)      NOT NULL DEFAULT 1,
  `source`          VARCHAR(30)     NULL,
  `granted_by`      BIGINT UNSIGNED NULL,
  `last_payment_id` BIGINT UNSIGNED NULL,
  `metadata`        JSON            NULL,
  `created_at`      TIMESTAMP       NULL,
  `updated_at`      TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  KEY `subscriptions_user_id_index`         (`user_id`),
  KEY `subscriptions_user_status_index`     (`user_id`, `status`),
  KEY `subscriptions_expires_at_index`      (`expires_at`),
  CONSTRAINT `subscriptions_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscriptions_plan_id_foreign`
    FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 3) Monthly usage counter ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `subscription_usage` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         BIGINT UNSIGNED NOT NULL,
  `period_yyyymm`   VARCHAR(7)      NOT NULL,
  `listings_posted` INT UNSIGNED    NOT NULL DEFAULT 0,
  `featured_used`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `pins_used`       INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`      TIMESTAMP       NULL,
  `updated_at`      TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_usage_user_period_unique` (`user_id`, `period_yyyymm`),
  CONSTRAINT `subscription_usage_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 4) Seed default plans ──────────────────────────────────────
INSERT INTO `plans`
  (`code`, `name_ar`, `name_en`, `tagline_ar`, `tagline_en`,
   `price_monthly`, `price_yearly`, `features`, `display_order`,
   `is_active`, `is_default`, `badge_color`, `created_at`, `updated_at`)
VALUES
  ('free',
   'الباقة المجانية', 'Free',
   'ابدأ بدون أي تكلفة', 'Start with no cost',
   0, 0,
   JSON_OBJECT(
     'max_listings', 3, 'max_featured_per_month', 0,
     'has_ma', FALSE, 'has_pin', FALSE,
     'auto_renew_listings', FALSE, 'has_trusted_badge', FALSE,
     'has_blind_bidding', FALSE,
     'ai_tools_level', 'limited', 'analytics_level', 'basic',
     'support_level', 'email_72h', 'api_access', FALSE, 'max_sub_users', 1
   ),
   1, 1, 1, 'gray', NOW(), NOW()),

  ('basic',
   'الأساسية', 'Basic',
   'مثالية للأفراد والشركات الصغيرة', 'Perfect for individuals and small businesses',
   99, 999,
   JSON_OBJECT(
     'max_listings', 15, 'max_featured_per_month', 1,
     'has_ma', TRUE, 'has_pin', TRUE,
     'auto_renew_listings', FALSE, 'has_trusted_badge', FALSE,
     'has_blind_bidding', TRUE,
     'ai_tools_level', 'limited', 'analytics_level', 'intermediate',
     'support_level', 'email_48h', 'api_access', FALSE, 'max_sub_users', 2
   ),
   2, 1, 0, 'navy', NOW(), NOW()),

  ('professional',
   'الاحترافية', 'Professional',
   'للمتوسطين والوكالات اللوجستية', 'For mid-size logistics agencies',
   299, 2999,
   JSON_OBJECT(
     'max_listings', 50, 'max_featured_per_month', 5,
     'has_ma', TRUE, 'has_pin', TRUE,
     'auto_renew_listings', TRUE, 'has_trusted_badge', TRUE,
     'has_blind_bidding', TRUE,
     'ai_tools_level', 'full', 'analytics_level', 'advanced',
     'support_level', 'email_24h', 'api_access', FALSE, 'max_sub_users', 5
   ),
   3, 1, 0, 'emerald', NOW(), NOW()),

  ('enterprise',
   'المؤسسات', 'Enterprise',
   'للمصانع الكبرى والشركات الكبيرة', 'For large enterprises and factories',
   999, 9999,
   JSON_OBJECT(
     'max_listings', -1, 'max_featured_per_month', -1,
     'has_ma', TRUE, 'has_pin', TRUE,
     'auto_renew_listings', TRUE, 'has_trusted_badge', TRUE,
     'has_blind_bidding', TRUE,
     'ai_tools_level', 'priority', 'analytics_level', 'advanced_export',
     'support_level', 'dedicated_whatsapp', 'api_access', TRUE, 'max_sub_users', 20
   ),
   4, 1, 0, 'gold', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `name_ar`       = VALUES(`name_ar`),
  `name_en`       = VALUES(`name_en`),
  `price_monthly` = VALUES(`price_monthly`),
  `price_yearly`  = VALUES(`price_yearly`),
  `features`      = VALUES(`features`),
  `updated_at`    = NOW();

-- ─── 5) Auto-grant Free plan to every existing user ─────────────
-- Uses INSERT IGNORE so re-running this won't create duplicates.
INSERT IGNORE INTO `subscriptions`
  (`user_id`, `plan_id`, `status`, `billing_cycle`, `started_at`,
   `source`, `auto_renew`, `created_at`, `updated_at`)
SELECT
  `u`.`id`,
  (SELECT `id` FROM `plans` WHERE `code` = 'free' LIMIT 1),
  'active', 'monthly', NOW(),
  'auto_migration', 0, NOW(), NOW()
FROM `users` `u`
LEFT JOIN `subscriptions` `s`
  ON `s`.`user_id` = `u`.`id` AND `s`.`status` IN ('active','pending')
WHERE `s`.`id` IS NULL;
