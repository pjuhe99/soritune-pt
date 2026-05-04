-- 코치 면담 기록 테이블
-- 팀장이 자기 팀원과의 면담 시 특이사항을 일자별로 기록.
-- 작성/수정/삭제는 작성한 팀장 본인만, 조회는 작성 팀장 + 어드민(read-only).

CREATE TABLE coach_meeting_notes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  coach_id      INT NOT NULL COMMENT '면담 대상 (팀원) coaches.id',
  meeting_date  DATE NOT NULL COMMENT '팀장이 선택한 면담 일자',
  notes         TEXT NOT NULL,
  created_by    INT NOT NULL COMMENT '작성한 팀장 coaches.id',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_coach_date (coach_id, meeting_date DESC, id DESC),
  INDEX idx_created_by (created_by),
  CONSTRAINT fk_cmn_coach   FOREIGN KEY (coach_id)   REFERENCES coaches(id) ON DELETE CASCADE,
  CONSTRAINT fk_cmn_creator FOREIGN KEY (created_by) REFERENCES coaches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
