-- 2026-04-30: 카톡방 입장 체크 — orders 테이블 컬럼 4개 + 인덱스 2개 추가
-- Spec: docs/superpowers/specs/2026-04-29-kakao-room-check-design.md
--
-- 적용:
--   mysql --defaults-file=/root/.my.cnf SORITUNECOM_PT < migrations/20260430_add_kakao_room_check.sql
--
-- 멱등성: ALTER ... ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS 사용 (MySQL 8+).
-- 롤백: 수동 (DROP COLUMN / DROP INDEX). 자동 롤백 스크립트 없음.

SET NAMES utf8mb4;

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `cohort_month` CHAR(7) DEFAULT NULL
    COMMENT 'YYYY-MM. NULL이면 자동(DATE_FORMAT(start_date,"%Y-%m")). 명시값은 admin override.',
  ADD COLUMN IF NOT EXISTS `kakao_room_joined` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `kakao_room_joined_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `kakao_room_joined_by` INT DEFAULT NULL
    COMMENT 'coach.id 또는 admin.id (actor 구분은 change_logs로). FK 없음 — 직 삭제 시 무관.',
  ADD INDEX IF NOT EXISTS `idx_cohort_month` (`cohort_month`),
  ADD INDEX IF NOT EXISTS `idx_kakao_room` (`kakao_room_joined`);
