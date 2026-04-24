-- 2026-04-24: Coach retention management (PT 리텐션 관리)
-- Adds coach_retention_scores, coach_retention_runs tables.
-- Extends change_logs.target_type ENUM with 'retention_allocation'.
--
-- Apply:
--   mysql -u SORITUNECOM_PT -p SORITUNECOM_PT < migrations/20260424_add_coach_retention.sql
--
-- Rollback: manual (DROP TABLE + restore ENUM), no automated rollback script.

SET NAMES utf8mb4;

-- coach_retention_scores: one row per (coach, base_month). Snapshot of retention + grade + allocation.
CREATE TABLE IF NOT EXISTS `coach_retention_scores` (
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

-- coach_retention_runs: one row per base_month. Records the calculation inputs + unmapped coaches.
CREATE TABLE IF NOT EXISTS `coach_retention_runs` (
  `base_month` VARCHAR(7) PRIMARY KEY,
  `total_new` INT NOT NULL DEFAULT 0,
  `unmapped_coaches` LONGTEXT DEFAULT NULL,
  `calculated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `calculated_by` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extend change_logs.target_type ENUM.
ALTER TABLE `change_logs`
  MODIFY COLUMN `target_type`
    ENUM('member','order','coach_assignment','merge','retention_allocation') NOT NULL;
