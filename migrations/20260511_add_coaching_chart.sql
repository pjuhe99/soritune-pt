-- 2026-05-11: 회원 차트 시스템 — 매칭 캘린더 + 일별 코칭 로그 확장
-- Spec: docs/superpowers/specs/2026-05-11-pt-member-chart-design.md

START TRANSACTION;

-- 1) 매칭 캘린더 (월별 × 상품별, single source)
CREATE TABLE IF NOT EXISTS `coaching_calendars` (
  `id`            INT PRIMARY KEY AUTO_INCREMENT,
  `cohort_month`  CHAR(7)      NOT NULL,
  `product_name`  VARCHAR(200) NOT NULL,
  `session_count` INT          NOT NULL,
  `notes`         VARCHAR(500),
  `created_by`    INT NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT current_timestamp(),
  `updated_at`    DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY `uk_cohort_product` (`cohort_month`, `product_name`),
  KEY `idx_cohort` (`cohort_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) 캘린더 일자 (1:N)
CREATE TABLE IF NOT EXISTS `coaching_calendar_dates` (
  `id`             INT PRIMARY KEY AUTO_INCREMENT,
  `calendar_id`    INT NOT NULL,
  `session_number` INT NOT NULL,
  `scheduled_date` DATE NOT NULL,
  UNIQUE KEY `uk_calendar_session` (`calendar_id`, `session_number`),
  KEY `idx_date` (`scheduled_date`),
  FOREIGN KEY (`calendar_id`) REFERENCES `coaching_calendars`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) order_sessions 확장
ALTER TABLE `order_sessions`
  ADD COLUMN `calendar_id`  INT       NULL AFTER `order_id`,
  ADD COLUMN `progress`     TEXT      NULL AFTER `memo`,
  ADD COLUMN `issue`        TEXT      NULL AFTER `progress`,
  ADD COLUMN `solution`     TEXT      NULL AFTER `issue`,
  ADD COLUMN `improved`     TINYINT(1) NOT NULL DEFAULT 0 AFTER `solution`,
  ADD COLUMN `improved_at`  DATETIME  NULL AFTER `improved`,
  ADD COLUMN `updated_by`   INT       NULL AFTER `improved_at`,
  ADD COLUMN `updated_at`   DATETIME  NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  ADD KEY `idx_order_completed` (`order_id`, `completed_at`),
  ADD KEY `idx_order_improved`  (`order_id`, `improved`),
  ADD CONSTRAINT `fk_session_calendar` FOREIGN KEY (`calendar_id`) REFERENCES `coaching_calendars`(`id`) ON DELETE SET NULL;

-- 4) change_logs.target_type ENUM 확장 (PT 현재 값 보존 + 3개 추가)
ALTER TABLE `change_logs`
  MODIFY COLUMN `target_type` ENUM(
    'member','order','coach_assignment','merge','retention_allocation',
    'meeting_note','training_attendance',
    'coaching_calendar','coaching_calendar_date','order_session'
  ) NOT NULL;

-- 5) 마이그 staging 테이블
CREATE TABLE IF NOT EXISTS `coaching_log_migration_preview` (
  `id`             INT PRIMARY KEY AUTO_INCREMENT,
  `batch_id`       VARCHAR(50) NOT NULL,
  `source_row`     INT NOT NULL,
  `soritune_id`    VARCHAR(50),
  `cohort_month`   CHAR(7),
  `product_name`   VARCHAR(200),
  `session_number` INT,
  `scheduled_date` DATE,
  `completed_at`   DATETIME,
  `progress`       TEXT,
  `issue`          TEXT,
  `solution`       TEXT,
  `improved`       TINYINT(1) DEFAULT 0,
  `sheet_progress_rate`    DECIMAL(5,2) NULL,
  `sheet_improvement_rate` DECIMAL(5,2) NULL,
  `match_status`   ENUM('matched','member_not_found','order_not_found',
                        'duplicate','date_invalid','calendar_missing','imported') NOT NULL,
  `target_order_id` INT NULL,
  `error_detail`    VARCHAR(500),
  `created_at`      DATETIME NOT NULL DEFAULT current_timestamp(),
  KEY `idx_batch`        (`batch_id`),
  KEY `idx_batch_status` (`batch_id`, `match_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
