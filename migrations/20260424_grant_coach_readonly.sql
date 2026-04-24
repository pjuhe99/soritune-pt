-- 2026-04-24: Grant PT MySQL user read-only access to SORITUNECOM_COACH tables
-- needed by the retention management feature.
--
-- Apply (requires MySQL admin privileges, NOT the PT app user):
--   mysql -u root -p < migrations/20260424_grant_coach_readonly.sql

GRANT SELECT ON `SORITUNECOM_COACH`.`coaches`
  TO 'SORITUNECOM_PT'@'localhost';
GRANT SELECT ON `SORITUNECOM_COACH`.`coach_member_mapping`
  TO 'SORITUNECOM_PT'@'localhost';
GRANT SELECT ON `SORITUNECOM_COACH`.`retention_score_criteria`
  TO 'SORITUNECOM_PT'@'localhost';
GRANT SELECT ON `SORITUNECOM_COACH`.`grade_criteria`
  TO 'SORITUNECOM_PT'@'localhost';
GRANT SELECT ON `SORITUNECOM_COACH`.`coach_assignment_requests`
  TO 'SORITUNECOM_PT'@'localhost';

FLUSH PRIVILEGES;
