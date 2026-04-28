# PT 매칭 시스템 — 설계 명세

- **작성일**: 2026-04-28
- **대상 시스템**: `pt.soritune.com` admin
- **선행 의존**: 리텐션 관리(2026-04-24 spec, Task 1~14 구현 완료) — `coach_retention_scores.final_allocation` 사용

## 1. 목적

새 구매(=신규 `orders` row, `status='매칭대기'`)를 코치에게 자동 배분한다. 자동 매칭 결과를 어드민이 검토·수정한 뒤 일괄 확정하면 `orders.coach_id` + `coach_assignments`로 commit된다.

장기 데이터 흐름:
```
구매데이터 업로드(import_orders) → 매칭대기 orders → [매칭 시스템] → 매칭완료 orders + coach_assignments
```

## 2. 범위

### In scope
- 매칭대기 `orders`를 모아 한 batch로 자동 매칭 — 이전 코치 룰 + 신규 풀 분배
- 어드민이 batch 결과를 검토·수정하는 UI (테이블 + 행별 코치 드롭다운)
- 확정 시 `orders.coach_id` + `status` + `coach_assignments` 일괄 commit
- batch 단위 취소/폐기 (모든 draft 삭제, orders 그대로)

### Out of scope (이번 spec에서 빠짐 — 필요 시 후속 spec)
- **DISC / 오감각 테스트 결과 반영** — 사용자가 명시적으로 이번 범위에서 제외
- 매칭 결과 검색/필터링의 고급 기능(코치별 부하 시각화 등)
- 코치 측에서 매칭 결과 보기 (admin 단방향)
- 자동 매칭의 재실행/롤백 (확정 후엔 일반 `orders.coach_id` 변경 UI로 수정)

## 3. 확정된 결정 사항

| # | 항목 | 결정 |
|---|---|---|
| Q1 | 적용 방식 | **(b) draft 후 일괄 확정** — staging에 매칭안 저장, 검토/수정 후 confirm 시 orders로 commit |
| Q2 | "이전 담당 코치" 식별 | `orders` 기준. 같은 `member_id`의 `status NOT IN ('환불','중단')` 인 orders 중 **가장 최근**(`end_date DESC`) row의 `coach_id`. 동시 진행 이력 있어도 가장 최근 1건만 |
| Q3 | "1년 이상 안 들음" 기준 | 새 order `start_date` − 직전 정상 order `end_date` ≥ **365일** |
| Q4 | "1년 이상" + "이전 코치 inactive" 통합 | 둘 다 **신규 풀**(`final_allocation` 분배)에 합류 |
| Q5 | 이전 코치 capacity | **별개 풀**. 이전 학생은 `final_allocation` 무시하고 무조건 받음. `final_allocation`은 **신규 한정** capacity |
| Q6 | Draft 저장 | **별도 staging 테이블** + batch 메타 테이블 (`coach_assignment_drafts` + `coach_assignment_runs`) |
| Q7 | 트리거 | 어드민이 "**매칭 실행**" 버튼 클릭. 한 번에 **하나의 active draft batch만** 허용 |
| Q8 | 신규 풀 분배 | 코치별 `final_allocation` 슬롯 생성 → 셔플 → zip. **풀 > capacity 합 시 미매칭은 `매칭대기` 그대로 유지** (다음 batch 또는 어드민 수동) |
| Q9 | 수동 수정 UI | **행별 드롭다운** (PT admin 기존 패턴 일관) |
| Q10 | Capacity snapshot | batch 생성 시점에 `runs.capacity_snapshot` (JSON)에 코치별 `final_allocation` 저장 → 이후 retention 변경에 영향 받지 않음 |

## 4. 아키텍처

```
┌──────────────────────────────────────────────────────────┐
│  SORITUNECOM_PT                                          │
│                                                          │
│  orders (status='매칭대기')                              │
│    │                                                     │
│    ▼  [매칭 실행]                                        │
│  coach_assignment_drafts  ─────┬── batch_id              │
│  coach_assignment_runs ────────┘   capacity_snapshot     │
│    │                                                     │
│    ▼  [확정]                                             │
│  orders (coach_id set, status='매칭완료')                │
│  coach_assignments (assigned_at, reason)                 │
│  change_logs (target_type='order', action='coach_assigned')│
└──────────────────────────────────────────────────────────┘
            ▲
            │ final_allocation 참조 (READ)
  coach_retention_scores (base_month별)
```

- 단일 DB(`SORITUNECOM_PT`) 안에서 모두 처리. cross-DB 없음.
- 리텐션 관리에서 어드민이 정한 `final_allocation`을 batch 시작 시 snapshot으로 잡음 → batch 안에서 일관성 보장.

## 5. 데이터 모델

### 5.1 `coach_assignment_runs` (신규 — batch 메타)

```sql
CREATE TABLE `coach_assignment_runs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `base_month` VARCHAR(7) NOT NULL,                   -- 어느 월의 final_allocation을 사용한 batch
  `status` ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  `started_by` INT NOT NULL,                          -- admins.id
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at` DATETIME DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `total_orders` INT NOT NULL DEFAULT 0,              -- batch에 들어간 매칭대기 order 수
  `prev_coach_count` INT NOT NULL DEFAULT 0,          -- '이전 코치' 룰로 매칭된 수
  `new_pool_count` INT NOT NULL DEFAULT 0,            -- 신규 풀로 들어간 수 (분배 시도)
  `matched_count` INT NOT NULL DEFAULT 0,             -- 최종 매칭 = prev_coach + 신규풀 분배 성공
  `unmatched_count` INT NOT NULL DEFAULT 0,           -- 신규 풀에서 capacity 부족으로 미매칭
  `capacity_snapshot` LONGTEXT DEFAULT NULL,          -- JSON: [{coach_id,coach_name,final_allocation}]
  FOREIGN KEY (`started_by`) REFERENCES `admins`(`id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_started_at` (`started_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- **draft 유일성**: 어플리케이션 레벨에서 `status='draft'` row가 0 또는 1개임을 강제 (Q7 (i)). 새 batch 시작 전에 기존 draft가 없는지 검사.

### 5.2 `coach_assignment_drafts` (신규 — 매칭안 row)

```sql
CREATE TABLE `coach_assignment_drafts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `batch_id` INT NOT NULL,                            -- coach_assignment_runs.id
  `order_id` INT NOT NULL,
  `proposed_coach_id` INT DEFAULT NULL,               -- NULL = 미매칭 (신규풀 capacity 부족)
  `source` ENUM('previous_coach','new_pool','manual_override','unmatched') NOT NULL,
  `prev_coach_id` INT DEFAULT NULL,                   -- 참고용: 이전 담당 코치 id (있으면)
  `prev_end_date` DATE DEFAULT NULL,                  -- 참고용: 이전 정상 order의 end_date
  `reason` VARCHAR(255) DEFAULT NULL,                 -- 분기 이유 (사람이 읽기용)
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`batch_id`) REFERENCES `coach_assignment_runs`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`proposed_coach_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL,
  UNIQUE KEY `uq_batch_order` (`batch_id`, `order_id`),  -- 한 batch에 같은 order 중복 금지
  INDEX `idx_proposed_coach` (`proposed_coach_id`),
  INDEX `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- 한 order는 active(`draft`) batch 안에 **최대 1번**만 들어감 (`UNIQUE`).
- 같은 order가 다른 active draft batch에 들어가는 일은 Q7 (i) 룰로 방지(active draft 1개 이하).
- `proposed_coach_id IS NULL` + `source='unmatched'` → 신규 풀 capacity 부족으로 매칭 안 된 row. UI에서 강조.
- draft 단계의 row 변경은 `change_logs`에 기록하지 않는다 (drafts는 임시 데이터). 확정 시점의 최종 결과만 `change_logs`에 1건씩 기록. 따라서 `change_logs.target_type` enum 변경은 불필요 — 기존 `'order'`, `'coach_assignment'` 값으로 충분.

### 5.3 기존 테이블 변경 — 없음

`orders`, `coaches`, `members`, `coach_assignments`, `coach_retention_scores`는 **변경 없음**. 신규 두 테이블만 추가.

## 6. 매칭 룰 (트리거 시점)

batch 생성 시 다음 흐름으로 각 order의 `proposed_coach_id`/`source`를 결정한다.

```
[입력] orders.status='매칭대기'이고 어떤 active draft batch에도 안 들어가 있는 order 전부

[Step 1] 각 매칭대기 order(=current)에 대해 "이전 담당 코치" 조회
  prev_order = SELECT * FROM orders
               WHERE member_id = current.member_id
                 AND id        != current.id              -- 자기 자신 제외
                 AND status NOT IN ('환불','중단')
               ORDER BY end_date DESC
               LIMIT 1

  IF prev_order IS NULL → 신규 풀 (Step 2 대기)
  ELSE:
    gap_days = current.start_date - prev_order.end_date
    prev_coach = coaches WHERE id = prev_order.coach_id
    IF prev_coach IS NULL OR prev_coach.status='inactive' → 신규 풀
    ELSE IF gap_days >= 365                              → 신규 풀
    ELSE:
      proposed_coach_id = prev_coach.id
      source = 'previous_coach'
      reason = "직전 PT (~{prev_end_date}, gap {gap}일) 담당"

[Step 2] 신규 풀 분배
  pool = Step 1에서 신규 풀로 떨어진 order들 (셔플)
  capacity_map = runs.capacity_snapshot 기준 final_allocation > 0 인 active 코치
  slots = []
  FOR coach IN capacity_map:
    slots.extend([coach.id] * coach.final_allocation)
  슬롯 셔플
  zip(pool, slots) → 짝지어진 (order, coach) 매칭
    source = 'new_pool', reason = "신규 풀 무작위 추첨"
  pool에 남은 order들 (capacity 부족) → proposed_coach_id=NULL, source='unmatched',
    reason = "이번 batch 신규 capacity 부족"

[Step 3] runs 통계 update
  prev_coach_count, new_pool_count, matched_count, unmatched_count 계산해서 저장
```

## 7. 워크플로우

```
[Active draft 없음]
   │
   ▼
어드민: "매칭 실행" 클릭 (base_month 선택 — 디폴트 가장 최근)
   │
   ▼
서버: runs row 생성(status=draft) + drafts INSERT (Step 1~3)
   │
   ▼
[검토 화면 (draft 상태)]
   │  ├─ 행별 드롭다운으로 proposed_coach_id 변경 → source='manual_override'
   │  ├─ 미매칭 row에 코치 직접 지정 가능
   │  └─ 코치별 capacity 진행도 카드 (snapshot 기준)
   │
   ├─ "확정" 클릭 → §8.1
   └─ "이 batch 폐기" 클릭 → §8.2
```

## 8. 확정 / 취소 처리

### 8.1 확정 (Confirm)

```
BEGIN TRANSACTION
  -- 매칭된 drafts (proposed_coach_id IS NOT NULL) 일괄 처리
  FOR EACH draft d IN this batch WHERE d.proposed_coach_id IS NOT NULL:
    UPDATE orders SET coach_id = d.proposed_coach_id, status='매칭완료' WHERE id = d.order_id
    INSERT INTO coach_assignments (member_id, coach_id, order_id, assigned_at, reason)
      SELECT o.member_id, d.proposed_coach_id, o.id, NOW(),
             CASE d.source
               WHEN 'previous_coach' THEN 'auto_match:previous_coach'
               WHEN 'new_pool'       THEN 'auto_match:new_pool'
               WHEN 'manual_override'THEN 'auto_match:manual_override'
             END
        FROM orders o WHERE o.id = d.order_id
    INSERT INTO change_logs (target_type='order', target_id=o.id, action='coach_assigned',
                             old_value={coach_id:null}, new_value={coach_id:d.proposed_coach_id, source:d.source},
                             actor_type='admin', actor_id=admin.id)

  -- 미매칭 drafts (proposed_coach_id IS NULL): orders는 매칭대기 그대로, drafts만 정리
  -- (별도 INSERT 없음, drafts CASCADE 삭제로 처리)

  -- runs status 전환
  UPDATE coach_assignment_runs SET status='confirmed', confirmed_at=NOW() WHERE id = batch_id

  -- drafts 삭제 (audit는 change_logs + runs 메타로 충분)
  DELETE FROM coach_assignment_drafts WHERE batch_id = batch_id
COMMIT
```

### 8.2 취소 (Cancel)

```
BEGIN TRANSACTION
  UPDATE coach_assignment_runs SET status='cancelled', cancelled_at=NOW() WHERE id = batch_id
  DELETE FROM coach_assignment_drafts WHERE batch_id = batch_id
  -- orders는 매칭대기 그대로 유지
COMMIT
```

### 8.3 어드민 수동 수정 (draft 검토 중)

- 드롭다운 변경 → API: `update_draft_row(draft_id, new_coach_id)`
  - `proposed_coach_id` ← new
  - `source` ← `'manual_override'` (이미 manual_override면 유지)
  - `reason` ← `"수동 조정 (이전: {old_source})"`
  - `updated_at` 자동 bump
- 미매칭 row에 코치 지정해도 같은 API 호출.
- 코치를 비우는(미매칭으로 되돌리는) 경우: `proposed_coach_id=NULL, source='unmatched', reason='수동 비움'`.

## 9. UI

### 9.1 사이드바 진입점
- "매칭관리" 새 메뉴 추가 (회원관리 / 코치관리 / 리텐션관리 / 매칭관리 / 임포트 ...).
- 라우트 hash: `#matching`.

### 9.2 화면 상태
- **active draft 없음**: 빈 상태 + "매칭 실행" 버튼 + base_month 드롭다운(coach_retention_runs에 있는 월 리스트) + 매칭 대상 미리보기(`status='매칭대기'`인 order 수)
- **active draft 있음**: 검토 화면

### 9.3 검토 화면 구성

상단 sticky 영역:
```
[ batch #ID • 시작 2026-04-28 14:30 by admin@... • base_month 2026-05 ]
[ 총 35 / 이전코치 12 / 신규풀 18 / 미매칭 5 ]
[ 코치별 capacity 진행도 카드 — Tia 8/10  Hazel 7/8  Rachel 5/5  ... ]
[ "확정" "이 batch 폐기" 버튼 ]
```

테이블:
| 회원명 | 상품명 | start_date | source | 이전 코치 (참고) | 제안 코치 [드롭다운] | 비고(reason) |
|---|---|---|---|---|---|---|
| Kim | 90일 PT | 2026-05-01 | previous_coach | Tia | **Tia ▾** | 직전 PT (~2026-04-15, gap 16일) |
| Park | 30일 PT | 2026-05-02 | new_pool | (없음) | **Hazel ▾** | 신규 풀 무작위 추첨 |
| Lee | 30일 PT | 2026-05-02 | unmatched | Sara (1년 초과) | **— ▾** | 신규 capacity 부족 |
| Choi | 60일 PT | 2026-05-03 | manual_override | (이전: new_pool) | **Rachel ▾** | 수동 조정 |

- source 컬럼은 색상 구분 (이전코치=초록, 신규풀=파랑, 수동조정=노랑, 미매칭=빨강).
- 드롭다운: active 코치 전체. capacity 초과해도 선택 가능 (어드민 판단 우선) — 단, 카드의 `used` 숫자가 capacity를 초과하면 카드를 빨간색으로 강조.
- 정렬/필터: source / 코치 / 회원명.

### 9.4 빈 상태 / 빈 batch
- 매칭대기 order 0건이면 "매칭 실행" 버튼 비활성화 + 안내 문구.
- batch 생성 결과가 0건(이론상 가능) 처리: runs row는 생성하지 않고 안내.

## 10. API 설계 (`/api/matching.php`)

```
GET  ?action=current           → active draft batch 정보 + drafts 목록 (없으면 null)
GET  ?action=runs              → 과거 runs 메타 리스트 (confirmed/cancelled)
GET  ?action=preview           → 매칭 대상 미리보기 (현재 status=매칭대기 order 수)
GET  ?action=base_months       → 사용 가능한 base_month 리스트

POST ?action=start             → 새 batch 생성 + Step 1~3 실행
       body: { base_month }
       error: 이미 active draft 있으면 409
POST ?action=update_draft      → 드롭다운 변경 시 호출
       body: { draft_id, proposed_coach_id|null }
POST ?action=confirm           → 확정 (§8.1)
       body: { batch_id }      -- batch_id 명시로 stale confirm 방지
POST ?action=cancel            → 취소 (§8.2)
       body: { batch_id }
```

- 모든 엔드포인트 `requireAdmin()`.
- `change_logs` 기록 일관성: confirm 시 매 row 1건씩.

## 11. Edge cases

| 케이스 | 처리 |
|---|---|
| 매칭대기 order 0건 → 매칭 실행 | 버튼 비활성화 |
| 이전 코치는 active이지만 PT가 같은 member에 대해 환불/중단만 있음 | 환불/중단 제외 후 prev_order=NULL → 신규 풀 |
| 같은 member가 같은 batch에 여러 매칭대기 order 보유 | 각 order 독립적으로 룰 적용. 한 사람이 두 코치에게 동시 매칭될 수도 있음 (현재 PT 데이터 모델이 이미 허용) |
| `final_allocation` 합 = 0 (capacity_snapshot 비어있음) | 신규 풀 모두 미매칭. UI 상단에 경고 |
| `proposed_coach_id`로 지정된 코치가 batch 진행 중 inactive 됨 | 확정 시 검사: 매칭된 drafts의 코치 중 inactive가 있으면 confirm 실패 + 어드민에게 알림 → 수동 수정 후 재시도 |
| confirm 도중 fatal | 트랜잭션 롤백 → drafts/runs 그대로. 재시도 가능 |
| 같은 batch에서 두 어드민이 동시 편집 | drafts 단위 row 편집은 last-write-wins 단순 처리 (낙관적 락 미적용). active draft 1개 제약과 admin 인원이 적은 운영 환경상 충돌 빈도 낮음 |
| import_orders 중복으로 같은 매칭대기 order가 이미 active draft에 있음 | drafts UNIQUE(batch_id, order_id) 제약 + start API에서 active draft 존재 시 409 → import 직후 매칭 실행 충돌 회피 |
| 확정 후 같은 order의 coach_id를 일반 orders 편집 UI에서 변경 | 정상 흐름. coach_assignments에 새 row 추가, 기존 active row의 released_at set (orders.php 기존 로직 재활용) |
| draft 검토 중 base_month의 final_allocation이 retention 화면에서 변경됨 | snapshot 기준이라 draft에 영향 없음. capacity 진행도 카드도 snapshot 기준 |

## 12. 권한

- 모든 엔드포인트 admin 전용 (`requireAdmin`).
- 코치 사이트 영향 없음 (이번 spec 범위 밖).

## 13. 검증 / 수동 테스트 체크리스트

확정 후 어드민이 다음을 화면 + DB로 점검:

1. **빈 상태**: 매칭대기 0건 → "매칭 실행" 버튼 비활성화
2. **이전 코치 룰**:
   - active 코치 + 1년 미만 → `previous_coach`로 매칭 ✓
   - active 코치 + 1년 이상 → `new_pool`로 분류 ✓
   - inactive 코치 → `new_pool`로 분류 ✓
   - 환불/중단만 있는 member → `new_pool` ✓
3. **신규 풀 분배**:
   - capacity 합 == 풀 → 정확히 분배 ✓
   - capacity 합 > 풀 → 일부 코치 정원 미달 ✓
   - capacity 합 < 풀 → 일부 `unmatched` 발생, 카드에 빨간 표시 ✓
4. **수동 수정**: 드롭다운 변경 → `source='manual_override'`, drafts.updated_at bump ✓
5. **Capacity snapshot**: draft 검토 중 retention 화면에서 final_allocation 변경 → matching 화면 카드 영향 없음 ✓
6. **확정**:
   - matched orders → coach_id set, status='매칭완료' ✓
   - coach_assignments 신규 row 생성 (assigned_at, reason) ✓
   - change_logs 1건씩 기록 ✓
   - drafts 삭제, runs.status='confirmed' ✓
   - 미매칭 orders는 매칭대기 그대로 ✓
7. **취소**: drafts 삭제, runs.status='cancelled', orders 그대로 ✓
8. **active draft 1개 제약**: 이미 draft 있는 상태에서 매칭 실행 → 409 + UI 안내 ✓
9. **inactive 코치 confirm 시점 검사**: draft에 있는 코치가 inactive 됨 → confirm 실패 + 안내 ✓

## 14. 구현 후 마이그레이션 / 운영

- `migrations/20260428_add_coach_matching.sql`: 두 신규 테이블 (`coach_assignment_runs`, `coach_assignment_drafts`) 추가. `change_logs` enum 변경 없음.
- 운영 데이터 영향: 기존 데이터에 영향 없음 (신규 테이블만)
- 첫 batch는 어드민이 운영 시간 외에 한 번 실행 권장 (대상 orders가 누적된 상태)

## 15. 향후 작업 (out of scope, 후속 spec 후보)

- DISC / 오감각 결과 매칭 반영 — `test_results` 테이블 + 코치 성향 매핑 후 spec
- 매칭 결과 분석 대시보드 (코치별 매칭 수 추이, source 비율)
- import 후 자동 트리거 옵션 (운영 익숙해진 후)
- 매칭 실행 dry-run (DB 변경 없이 결과만 미리보기)
