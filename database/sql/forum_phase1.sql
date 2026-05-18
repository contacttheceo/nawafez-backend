-- Forum Phase 1 — Q&A features
-- Run this in phpMyAdmin > SQL tab on the Freehostia database.
--
-- ⚠️  Compatible with MySQL < 8.0.29 (Freehostia uses an older MySQL
--    that does NOT support `ADD COLUMN IF NOT EXISTS` or
--    `CREATE INDEX IF NOT EXISTS`). On a fresh install all statements
--    succeed. On a re-run you'll see "Duplicate column" / "Duplicate
--    key name" — those are safe to ignore (no data loss).

-- ─── 1) Extend `comments` with Q&A fields + soft delete ───────────────────────
ALTER TABLE `comments`
  ADD COLUMN `parent_id`           BIGINT UNSIGNED NULL DEFAULT NULL AFTER `user_id`,
  ADD COLUMN `is_official_answer`  BOOLEAN NOT NULL DEFAULT 0 AFTER `body`,
  ADD COLUMN `is_marked_helpful`   BOOLEAN NOT NULL DEFAULT 0 AFTER `is_official_answer`,
  ADD COLUMN `upvotes_count`       INT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_marked_helpful`,
  ADD COLUMN `deleted_at`          TIMESTAMP NULL DEFAULT NULL;

CREATE INDEX `comments_parent_idx`        ON `comments`(`parent_id`);
CREATE INDEX `comments_listing_sort_idx`  ON `comments`(`listing_id`, `is_official_answer`, `upvotes_count`);

-- ─── 2) `comment_votes` for upvote tracking ──────────────────────────────────
CREATE TABLE IF NOT EXISTS `comment_votes` (
  `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `comment_id` BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uniq_user_comment` (`user_id`, `comment_id`),
  KEY `comment_votes_comment_idx` (`comment_id`),
  CONSTRAINT `comment_votes_comment_id_foreign`
    FOREIGN KEY (`comment_id`) REFERENCES `comments`(`id`) ON DELETE CASCADE,
  CONSTRAINT `comment_votes_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 3) Add `forum_category` to listings ─────────────────────────────────────
ALTER TABLE `listings`
  ADD COLUMN `forum_category` VARCHAR(30) NULL DEFAULT NULL AFTER `listing_type`;

CREATE INDEX `listings_forum_cat_idx` ON `listings`(`forum_category`);

-- ─── 4) Verify ───────────────────────────────────────────────────────────────
-- Run these as a sanity check:
--   SHOW COLUMNS FROM `comments` LIKE 'parent_id';
--   SHOW COLUMNS FROM `comments` LIKE 'is_official_answer';
--   SHOW TABLES LIKE 'comment_votes';
--   SHOW COLUMNS FROM `listings` LIKE 'forum_category';
