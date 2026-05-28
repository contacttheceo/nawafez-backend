-- Run this once in phpMyAdmin (or via Laravel migrate) to enable Web Push.
-- Compatible with MySQL 5.7+ (no IF NOT EXISTS on ALTER for older versions).

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `endpoint`     VARCHAR(500)    NOT NULL,
  `p256dh`       VARCHAR(200)    NULL,
  `auth`         VARCHAR(100)    NULL,
  `user_agent`   VARCHAR(250)    NULL,
  `last_seen_at` TIMESTAMP       NULL,
  `created_at`   TIMESTAMP       NULL,
  `updated_at`   TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `push_subscriptions_endpoint_unique` (`endpoint`),
  KEY `push_subscriptions_user_id_index` (`user_id`),
  CONSTRAINT `push_subscriptions_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
