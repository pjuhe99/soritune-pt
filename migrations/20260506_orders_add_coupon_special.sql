-- 2026-05-06: orders 테이블에 쿠폰 지급/특이 건 플래그 추가
-- Spec: docs/superpowers/specs/2026-05-06-pt-kakao-check-extra-flags-design.md
--
-- 적용 (DEV):
--   mysql --defaults-file=/root/.my.cnf SORITUNECOM_DEV_PT < migrations/20260506_orders_add_coupon_special.sql
-- 적용 (PROD):
--   mysql --defaults-file=/root/.my.cnf SORITUNECOM_PT < migrations/20260506_orders_add_coupon_special.sql
--
-- 멱등성: ALTER ... ADD COLUMN IF NOT EXISTS (MySQL 8+).
-- 롤백: 수동 (DROP COLUMN). 자동 롤백 스크립트 없음.

SET NAMES utf8mb4;

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `coupon_issued` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `kakao_room_joined_by`,
  ADD COLUMN IF NOT EXISTS `coupon_issued_at` DATETIME DEFAULT NULL
    AFTER `coupon_issued`,
  ADD COLUMN IF NOT EXISTS `coupon_issued_by` INT DEFAULT NULL
    COMMENT 'admin.id 또는 coach.id (actor 구분은 change_logs)'
    AFTER `coupon_issued_at`,
  ADD COLUMN IF NOT EXISTS `special_case` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `coupon_issued_by`,
  ADD COLUMN IF NOT EXISTS `special_case_at` DATETIME DEFAULT NULL
    AFTER `special_case`,
  ADD COLUMN IF NOT EXISTS `special_case_by` INT DEFAULT NULL
    COMMENT 'admin.id 또는 coach.id (actor 구분은 change_logs)'
    AFTER `special_case_at`,
  ADD COLUMN IF NOT EXISTS `special_case_note` VARCHAR(255) DEFAULT NULL
    AFTER `special_case_by`;
