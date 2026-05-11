-- 2026-05-08: test_results.test_type ENUM 확장 — voice_intake 추가
-- 기존 row 영향 없음 (ENUM 추가만)
ALTER TABLE test_results
  MODIFY COLUMN test_type ENUM('disc', 'sensory', 'voice_intake') NOT NULL;
