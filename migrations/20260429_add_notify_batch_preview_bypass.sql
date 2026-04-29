-- PT 알림톡: notify_batch / notify_preview에 bypass 컬럼 추가
-- 출처: boot-dev/migrate_notify_bypass_columns.php
-- (T2의 scenario_state.bypass_*_once 마이그와는 별개. boot services/notify.php가
--  이 4개 컬럼을 INSERT/SELECT하므로 필수.)

ALTER TABLE notify_batch
    ADD COLUMN IF NOT EXISTS bypass_cooldown     TINYINT(1) NOT NULL DEFAULT 0 AFTER dry_run,
    ADD COLUMN IF NOT EXISTS bypass_max_attempts TINYINT(1) NOT NULL DEFAULT 0 AFTER bypass_cooldown;

ALTER TABLE notify_preview
    ADD COLUMN IF NOT EXISTS bypass_cooldown     TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS bypass_max_attempts TINYINT(1) NOT NULL DEFAULT 0;
