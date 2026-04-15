-- PT Management System Schema
-- Engine: InnoDB, Charset: utf8mb4

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- 1. admins
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login_id`   VARCHAR(50)  NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_login_id` (`login_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 2. coaches
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `coaches` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login_id`   VARCHAR(50)  NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `phone`      VARCHAR(20)  DEFAULT NULL,
  `email`      VARCHAR(255) DEFAULT NULL,
  `memo`       TEXT         DEFAULT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coaches_login_id` (`login_id`),
  KEY `idx_coaches_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 3. members
-- (NO status column, NO current_coach_id — derived at query time)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `members` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL,
  `phone`       VARCHAR(20)  DEFAULT NULL,
  `email`       VARCHAR(255) DEFAULT NULL,
  `memo`        TEXT         DEFAULT NULL,
  `merged_into` INT UNSIGNED DEFAULT NULL COMMENT 'FK self — points to surviving member after merge',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_members_phone`       (`phone`),
  KEY `idx_members_email`       (`email`),
  KEY `idx_members_merged_into` (`merged_into`),
  CONSTRAINT `fk_members_merged_into` FOREIGN KEY (`merged_into`) REFERENCES `members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 4. member_accounts (portal login)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `member_accounts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id`  INT UNSIGNED NOT NULL,
  `login_id`   VARCHAR(50)  NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member_accounts_login_id` (`login_id`),
  KEY `idx_member_accounts_member_id` (`member_id`),
  CONSTRAINT `fk_member_accounts_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 5. orders
-- (NO used_sessions — derived from COUNT(order_sessions))
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `member_id`      INT UNSIGNED  NOT NULL,
  `coach_id`       INT UNSIGNED  DEFAULT NULL,
  `product_name`   VARCHAR(255)  NOT NULL,
  `product_type`   ENUM('period','count') NOT NULL DEFAULT 'count',
  `start_date`     DATE          DEFAULT NULL,
  `end_date`       DATE          DEFAULT NULL,
  `total_sessions` INT UNSIGNED  DEFAULT NULL,
  `amount`         INT           NOT NULL DEFAULT 0 COMMENT 'KRW',
  `status`         ENUM('매칭대기','매칭완료','진행중','연기','중단','환불','종료') NOT NULL DEFAULT '매칭대기',
  `memo`           TEXT          DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orders_member_id`  (`member_id`),
  KEY `idx_orders_coach_id`   (`coach_id`),
  KEY `idx_orders_status`     (`status`),
  KEY `idx_orders_start_date` (`start_date`),
  CONSTRAINT `fk_orders_member` FOREIGN KEY (`member_id`) REFERENCES `members`  (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_coach`  FOREIGN KEY (`coach_id`)  REFERENCES `coaches`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 6. order_sessions
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_sessions` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`       INT UNSIGNED NOT NULL,
  `session_number` INT UNSIGNED NOT NULL,
  `completed_at`   DATETIME     DEFAULT NULL,
  `memo`           TEXT         DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_sessions_order_session` (`order_id`, `session_number`),
  KEY `idx_order_sessions_order_id` (`order_id`),
  CONSTRAINT `fk_order_sessions_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 7. coach_assignments
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `coach_assignments` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NOT NULL,
  `coach_id`     INT UNSIGNED NOT NULL,
  `assigned_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unassigned_at` DATETIME    DEFAULT NULL,
  `memo`         TEXT         DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_coach_assignments_order_id` (`order_id`),
  KEY `idx_coach_assignments_coach_id` (`coach_id`),
  CONSTRAINT `fk_coach_assignments_order` FOREIGN KEY (`order_id`) REFERENCES `orders`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coach_assignments_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 8. test_results
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `test_results` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id`  INT UNSIGNED NOT NULL,
  `order_id`   INT UNSIGNED DEFAULT NULL,
  `test_name`  VARCHAR(255) NOT NULL,
  `score`      VARCHAR(100) DEFAULT NULL,
  `tested_at`  DATE         DEFAULT NULL,
  `memo`       TEXT         DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_test_results_member_id` (`member_id`),
  KEY `idx_test_results_order_id`  (`order_id`),
  CONSTRAINT `fk_test_results_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_test_results_order`  FOREIGN KEY (`order_id`)  REFERENCES `orders`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 9. member_notes
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `member_notes` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id`   INT UNSIGNED NOT NULL,
  `author_type` ENUM('admin','coach') NOT NULL DEFAULT 'admin',
  `author_id`   INT UNSIGNED NOT NULL,
  `content`     TEXT         NOT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_member_notes_member_id` (`member_id`),
  KEY `idx_member_notes_author`    (`author_type`, `author_id`),
  CONSTRAINT `fk_member_notes_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 10. merge_logs
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `merge_logs` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `absorbed_member_id`   INT UNSIGNED NOT NULL COMMENT 'Member that was absorbed/deleted',
  `surviving_member_id`  INT UNSIGNED NOT NULL COMMENT 'Member that survived the merge',
  `absorbed_member_data` JSON         NOT NULL COMMENT 'Full data of absorbed member at time of merge',
  `moved_records`        JSON         NOT NULL COMMENT 'List of records moved from absorbed to surviving member',
  `merged_by_type`       ENUM('admin') NOT NULL DEFAULT 'admin',
  `merged_by_id`         INT UNSIGNED NOT NULL,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_merge_logs_absorbed_member_id`  (`absorbed_member_id`),
  KEY `idx_merge_logs_surviving_member_id` (`surviving_member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 11. change_logs
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `change_logs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `target_type` VARCHAR(50)  NOT NULL COMMENT 'Table/entity name',
  `target_id`   INT UNSIGNED NOT NULL,
  `action`      VARCHAR(50)  NOT NULL COMMENT 'create|update|delete|status_change|etc',
  `old_value`   JSON         DEFAULT NULL,
  `new_value`   JSON         DEFAULT NULL,
  `actor_type`  ENUM('admin','coach') NOT NULL,
  `actor_id`    INT UNSIGNED NOT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_change_logs_target` (`target_type`, `target_id`),
  KEY `idx_change_logs_actor`  (`actor_type`, `actor_id`),
  KEY `idx_change_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 12. migration_logs
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `migration_logs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename`    VARCHAR(255) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `applied_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration_logs_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
