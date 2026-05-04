-- 코치 교육 출석 (가상 회차 모델: row 존재=출석, 없음=결석)
-- 매주 목요일 코치 교육. 팀장이 자기 팀원의 출석을 토글.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `coach_training_attendance` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `coach_id`      INT NOT NULL,
  `training_date` DATE NOT NULL COMMENT '가상 회차 일자 (보통 목요일)',
  `marked_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `marked_by`     INT NOT NULL COMMENT '체크한 팀장 coaches.id',
  UNIQUE KEY `uk_coach_date` (`coach_id`, `training_date`),
  INDEX `idx_training_date` (`training_date`),
  CONSTRAINT `fk_cta_coach`  FOREIGN KEY (`coach_id`)  REFERENCES `coaches`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cta_marker` FOREIGN KEY (`marked_by`) REFERENCES `coaches`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
