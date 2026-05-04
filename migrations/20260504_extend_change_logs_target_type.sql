-- 2026-05-04: change_logs.target_type ENUM 확장
-- Task 4(meeting_note) / Task 6(training_attendance)에서 logChange() 호출 시 필요.
--
-- Apply:
--   mysql -u SORITUNECOM_DEV_PT -p SORITUNECOM_DEV_PT < migrations/20260504_extend_change_logs_target_type.sql
--
-- Rollback: manual (MODIFY COLUMN으로 이전 ENUM 값 복원).
--   주의: 이미 'meeting_note'/'training_attendance' 값이 존재하면 롤백 불가.

SET NAMES utf8mb4;

-- MODIFY COLUMN은 idempotent: 동일 ENUM으로 재실행해도 안전.
ALTER TABLE `change_logs`
  MODIFY COLUMN `target_type`
    ENUM('member','order','coach_assignment','merge','retention_allocation','meeting_note','training_attendance') NOT NULL;
