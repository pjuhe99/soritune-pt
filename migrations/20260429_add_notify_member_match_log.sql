-- PT 알림톡 전용: 시트 → PT members lookup 디버깅 로그
-- boot에는 없는 PT 고유 단계. notify_message.skip_reason과 분리하여 후속 분석 용이.

CREATE TABLE IF NOT EXISTS notify_member_match_log (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    preview_id        CHAR(32)     NULL,
    batch_id          BIGINT       NULL,
    scenario_key      VARCHAR(64)  NOT NULL,
    sheet_row_idx     INT          NOT NULL,
    soritune_id       VARCHAR(50)  NOT NULL,
    match_status      ENUM('matched','member_not_found','phone_empty','merged_followed') NOT NULL,
    resolved_member_id INT         NULL,
    resolved_phone    VARCHAR(20)  NULL,
    override_applied  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_preview (preview_id),
    INDEX idx_batch (batch_id),
    INDEX idx_soritune (soritune_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
