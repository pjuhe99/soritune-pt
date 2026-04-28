-- PT Management System Schema
-- Run: mysql -u SORITUNECOM_PT -p SORITUNECOM_PT < schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. admins
DROP TABLE IF EXISTS `migration_logs`;
DROP TABLE IF EXISTS `change_logs`;
DROP TABLE IF EXISTS `merge_logs`;
DROP TABLE IF EXISTS `member_notes`;
DROP TABLE IF EXISTS `test_results`;
DROP TABLE IF EXISTS `coach_assignments`;
DROP TABLE IF EXISTS `order_sessions`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `member_accounts`;
DROP TABLE IF EXISTS `members`;
DROP TABLE IF EXISTS `coach_assignment_drafts`;
DROP TABLE IF EXISTS `coach_assignment_runs`;
DROP TABLE IF EXISTS `coach_retention_runs`;
DROP TABLE IF EXISTS `coach_retention_scores`;
DROP TABLE IF EXISTS `coaches`;
DROP TABLE IF EXISTS `admins`;

CREATE TABLE `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `login_id` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. coaches
CREATE TABLE `coaches` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `login_id` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `coach_name` VARCHAR(100) NOT NULL,
  `korean_name` VARCHAR(50) DEFAULT NULL,
  `birthdate` DATE DEFAULT NULL,
  `hired_on` DATE DEFAULT NULL,
  `role` ENUM('신규 코치','일반 코치','리드 코치','코칭 마스터 코치','소리 마스터 코치') DEFAULT NULL,
  `evaluation` ENUM('pass','fail') DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `available` TINYINT(1) NOT NULL DEFAULT 1,
  `max_capacity` INT NOT NULL DEFAULT 0,
  `memo` TEXT,
  `overseas` TINYINT(1) NOT NULL DEFAULT 0,
  `side_job` TINYINT(1) NOT NULL DEFAULT 0,
  `soriblock_basic` TINYINT(1) NOT NULL DEFAULT 0,
  `soriblock_advanced` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. members (NO status, NO current_coach_id — derived at query time)
CREATE TABLE `members` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `soritune_id` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20),
  `email` VARCHAR(255),
  `memo` TEXT,
  `merged_into` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`merged_into`) REFERENCES `members`(`id`) ON DELETE SET NULL,
  INDEX `idx_phone` (`phone`),
  INDEX `idx_email` (`email`),
  INDEX `idx_merged_into` (`merged_into`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. member_accounts (original data preservation)
CREATE TABLE `member_accounts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `member_id` INT NOT NULL,
  `source` VARCHAR(50) NOT NULL,
  `source_id` VARCHAR(100),
  `name` VARCHAR(100),
  `phone` VARCHAR(20),
  `email` VARCHAR(255),
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
  INDEX `idx_member_id` (`member_id`),
  INDEX `idx_source` (`source`, `source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. orders (NO used_sessions — derived from COUNT(order_sessions))
CREATE TABLE `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `member_id` INT NOT NULL,
  `coach_id` INT DEFAULT NULL,
  `product_name` VARCHAR(200) NOT NULL,
  `product_type` ENUM('period','count') NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `total_sessions` INT DEFAULT NULL,
  `amount` INT NOT NULL DEFAULT 0,
  `status` ENUM('매칭대기','매칭완료','진행중','연기','중단','환불','종료') NOT NULL DEFAULT '매칭대기',
  `memo` TEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL,
  INDEX `idx_member_id` (`member_id`),
  INDEX `idx_coach_id` (`coach_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_natural_key` (`member_id`, `product_name`, `start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. order_sessions
CREATE TABLE `order_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `session_number` INT NOT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `memo` VARCHAR(255),
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_order_session` (`order_id`, `session_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. coach_assignments
CREATE TABLE `coach_assignments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `member_id` INT NOT NULL,
  `coach_id` INT NOT NULL,
  `order_id` INT DEFAULT NULL,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `released_at` DATETIME DEFAULT NULL,
  `reason` VARCHAR(255),
  FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL,
  INDEX `idx_member_id` (`member_id`),
  INDEX `idx_coach_id` (`coach_id`),
  INDEX `idx_active` (`coach_id`, `released_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. test_results
CREATE TABLE `test_results` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `member_id` INT NOT NULL,
  `test_type` ENUM('disc','sensory') NOT NULL,
  `result_data` JSON,
  `tested_at` DATE NOT NULL,
  `memo` TEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
  INDEX `idx_member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. member_notes
CREATE TABLE `member_notes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `member_id` INT NOT NULL,
  `author_type` ENUM('admin','coach') NOT NULL,
  `author_id` INT NOT NULL,
  `content` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
  INDEX `idx_member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. merge_logs
CREATE TABLE `merge_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `primary_member_id` INT NOT NULL,
  `merged_member_id` INT NOT NULL,
  `absorbed_member_data` JSON NOT NULL,
  `moved_records` JSON NOT NULL,
  `admin_id` INT NOT NULL,
  `merged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unmerged_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`),
  INDEX `idx_primary` (`primary_member_id`),
  INDEX `idx_merged` (`merged_member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. change_logs
CREATE TABLE `change_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `target_type` ENUM('member','order','coach_assignment','merge','retention_allocation') NOT NULL,
  `target_id` INT NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `old_value` JSON,
  `new_value` JSON,
  `actor_type` ENUM('admin','coach','system') NOT NULL,
  `actor_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_target` (`target_type`, `target_id`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. coach_retention_scores
CREATE TABLE `coach_retention_scores` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `coach_id` INT DEFAULT NULL,
  `coach_name_snapshot` VARCHAR(100) NOT NULL,
  `base_month` VARCHAR(7) NOT NULL,
  `grade` VARCHAR(5) DEFAULT NULL,
  `rank_num` INT DEFAULT NULL,
  `total_score` DECIMAL(6,1) DEFAULT 0.0,
  `new_retention_3m` DECIMAL(10,8) DEFAULT 0,
  `existing_retention_3m` DECIMAL(10,8) DEFAULT 0,
  `assigned_members` INT DEFAULT 0,
  `requested_count` INT DEFAULT 0,
  `auto_allocation` INT DEFAULT 0,
  `final_allocation` INT DEFAULT 0,
  `adjusted_by` INT DEFAULT NULL,
  `adjusted_at` DATETIME DEFAULT NULL,
  `monthly_detail` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
                            ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY `uq_coach_month` (`coach_id`, `base_month`),
  FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL,
  INDEX `idx_base_month` (`base_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. coach_retention_runs
CREATE TABLE `coach_retention_runs` (
  `base_month` VARCHAR(7) PRIMARY KEY,
  `total_new` INT NOT NULL DEFAULT 0,
  `unmapped_coaches` LONGTEXT DEFAULT NULL,
  `calculated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `calculated_by` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. coach_assignment_runs
CREATE TABLE `coach_assignment_runs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `base_month` VARCHAR(7) NOT NULL,
  `status` ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  `started_by` INT NOT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at` DATETIME DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `total_orders` INT NOT NULL DEFAULT 0,
  `prev_coach_count` INT NOT NULL DEFAULT 0,
  `new_pool_count` INT NOT NULL DEFAULT 0,
  `matched_count` INT NOT NULL DEFAULT 0,
  `unmatched_count` INT NOT NULL DEFAULT 0,
  `capacity_snapshot` LONGTEXT DEFAULT NULL,
  FOREIGN KEY (`started_by`) REFERENCES `admins`(`id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_started_at` (`started_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. coach_assignment_drafts
CREATE TABLE `coach_assignment_drafts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `batch_id` INT NOT NULL,
  `order_id` INT NOT NULL,
  `proposed_coach_id` INT DEFAULT NULL,
  `source` ENUM('previous_coach','new_pool','manual_override','unmatched') NOT NULL,
  `prev_coach_id` INT DEFAULT NULL,
  `prev_end_date` DATE DEFAULT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`batch_id`) REFERENCES `coach_assignment_runs`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`proposed_coach_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL,
  UNIQUE KEY `uq_batch_order` (`batch_id`, `order_id`),
  INDEX `idx_proposed_coach` (`proposed_coach_id`),
  INDEX `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. migration_logs
CREATE TABLE `migration_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `batch_id` VARCHAR(50) NOT NULL,
  `source_type` VARCHAR(50) NOT NULL,
  `source_row` INT,
  `target_table` VARCHAR(50),
  `target_id` INT,
  `status` ENUM('success','skipped','error') NOT NULL,
  `message` TEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_batch` (`batch_id`),
  INDEX `idx_status` (`batch_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
