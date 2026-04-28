-- 2026-04-28: PT 매칭 시스템 — staging + batch 메타 테이블 추가
-- 기존 테이블 변경 없음 (orders, coaches, members, coach_assignments, coach_retention_scores)

SET NAMES utf8mb4;

-- 1. coach_assignment_runs (batch 메타)
CREATE TABLE IF NOT EXISTS `coach_assignment_runs` (
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

-- 2. coach_assignment_drafts (매칭안 row)
CREATE TABLE IF NOT EXISTS `coach_assignment_drafts` (
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
