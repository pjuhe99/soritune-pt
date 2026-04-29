-- PT 알림톡: 1회용 우회(bypass) 컬럼 추가
-- 출처: boot-dev/migrate_notify_bypass_columns.php
-- 운영자가 시나리오 파일 수정 없이 다음 1회 발송에만 dry_run/cooldown 무시 가능

ALTER TABLE notify_scenario_state
    ADD COLUMN IF NOT EXISTS bypass_dry_run_once TINYINT(1) NOT NULL DEFAULT 0 AFTER notes,
    ADD COLUMN IF NOT EXISTS bypass_cooldown_once TINYINT(1) NOT NULL DEFAULT 0 AFTER bypass_dry_run_once;
