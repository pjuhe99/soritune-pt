# PT 리텐션 관리 — 설계 명세

- **작성일**: 2026-04-24
- **대상 시스템**: `pt.soritune.com` admin
- **연관 시스템**: `coach.soritune.com` (과도기 읽기 전용 참조 + 1건 hotfix)

## 1. 목적

현재 `coach.soritune.com`에 있는 "코치별 리텐션 계산 + 점수/등급 + 신규 배정" 기능을 `pt.soritune.com`의 admin 패널 "리텐션관리" 탭으로 이식한다. PT의 코치 리스트를 기준으로 하고, 장기적으로 PT가 단일 시스템이 되는 방향의 선행 작업.

장기 데이터 흐름:
```
구매데이터 업로드 → 매칭 시스템 → PT 이력(orders)
```
매칭 시스템이 PT에 생기기 전까지는 coach 사이트가 구매·매칭 업로드의 입력 창구 역할을 유지한다. 이번 작업 범위는 **리텐션 계산 기능의 이식**과 **코치 요청 화면의 등급 숨김 hotfix**까지.

## 2. 배포 전략 — 2단계 릴리즈

운영 리스크(코치 본인 등급 노출)를 즉시 제거하기 위해 2단계로 나눈다.

### 1단계 (hotfix)
- **파일**: `coach.soritune.com` 레포의 `public_html/coach/request.php`
- **변경**: 신청 이력 테이블의 "등급" 컬럼 표시 제거 (DB 저장 로직은 유지)
- 즉시 배포

### 2단계 (본체)
PT 리텐션 관리 탭 + 월 시프트 반영 + cross-DB 계산 + 수동 조정 UI 일괄 배포.

## 3. 확정된 결정 사항

| 항목 | 결정 |
|---|---|
| 기능 범위 | 리텐션율 + 점수·등급 + 신규 배정 자동 산출 + 수동 조정 |
| 데이터 흐름 | 과도기: coach 사이트 유지, PT는 cross-DB read |
| 코치 리스트 기준 | PT `coaches` |
| 코치 매핑 규칙 | coach 사이트 `coaches.name` == PT `coaches.coach_name` (영문명 정확 일치) |
| 모수 소스 | `SORITUNECOM_COACH.coach_member_mapping` (읽기) |
| 점수/등급 기준표 | `SORITUNECOM_COACH.retention_score_criteria`, `grade_criteria` (읽기). 관리 UI는 당분간 coach 사이트에 유지 |
| 희망 인원 신청 | coach 사이트에서 코치가 입력. PT는 `SORITUNECOM_COACH.coach_assignment_requests` 읽기 |
| 리텐션 결과 저장 | **PT DB 신규 테이블** (수동 조정 값이 저장되어야 하므로 PT가 주인) |
| 자동 배분 로직 | **현행 유지** (상위권 = 희망 × hope_ratio, 하위권 = 잔여 가중치 분배). 자동값은 "초안"이고 수동 조정이 최종 |
| 월 시프트 | `base_month` → current 후보 `[base-1, base-2, base-3]`. 측정 구간 3개 |

## 4. 아키텍처

```
┌──────────────────────────────────────┐   ┌──────────────────────────────────────┐
│  SORITUNECOM_PT  (주인)              │   │  SORITUNECOM_COACH  (읽기 전용 참조) │
│  - coaches                           │   │  - coaches            (영문명 매핑용)│
│  - orders                            │   │  - coach_member_mapping  (모수)      │
│  - coach_retention_scores   ←신설    │◄──┤  - retention_score_criteria          │
│  - coach_retention_runs     ←신설    │   │  - grade_criteria                    │
│  - (admin이 수동 조정한 final 배정)    │   │  - coach_assignment_requests (희망)  │
└──────────────────────────────────────┘   └──────────────────────────────────────┘
           ▲                                            ▲
           │                                            │
      PT admin "리텐션 관리"                         coach 사이트 (현행 유지)
      계산 실행 / 수동 조정                          코치가 희망 인원 신청
```

- 같은 MySQL 인스턴스 → 단일 PDO로 `SORITUNECOM_COACH.테이블명` 스키마 한정 쿼리 사용.
- 선행 1회 작업: `SORITUNECOM_PT` MySQL 유저에게 `SORITUNECOM_COACH` 리드온리 GRANT.
- 코치 매핑: PT `coaches.coach_name` = COACH `coaches.name`. 한 번에 `SELECT name, id` 로드해 PHP 배열 변환, 양방향 헬퍼 제공.
- 매핑 실패 코치: UI 상단 경고 배너로 노출. 계산에는 PT쪽에 존재하는 코치만 포함.

## 5. 데이터 모델 (PT DB)

### 5.1 `coach_retention_scores`
```sql
CREATE TABLE `coach_retention_scores` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `coach_id` INT DEFAULT NULL,             -- PT coaches.id, 삭제된 코치면 NULL
  `coach_name_snapshot` VARCHAR(100) NOT NULL,  -- 계산 시점의 coach_name (표시용 fallback)
  `base_month` VARCHAR(7) NOT NULL,        -- 'YYYY-MM'
  `grade` VARCHAR(5) DEFAULT NULL,         -- A+, A, B, C, D
  `rank_num` INT DEFAULT NULL,
  `total_score` DECIMAL(6,1) DEFAULT 0.0,
  `new_retention_3m` DECIMAL(10,8) DEFAULT 0,
  `existing_retention_3m` DECIMAL(10,8) DEFAULT 0,
  `assigned_members` INT DEFAULT 0,
  `requested_count` INT DEFAULT 0,
  `auto_allocation` INT DEFAULT 0,
  `final_allocation` INT DEFAULT 0,
  `adjusted_by` INT DEFAULT NULL,
  `adjusted_at` DATETIME DEFAULT NULL,
  `monthly_detail` LONGTEXT DEFAULT NULL,  -- JSON
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
                          ON UPDATE CURRENT_TIMESTAMP(3),  -- 낙관적 락 토큰
  UNIQUE KEY `uq_coach_month` (`coach_id`, `base_month`),
  FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL,
  INDEX `idx_base_month` (`base_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**`coach_id` nullable + `ON DELETE SET NULL`**: PT에서 코치를 삭제해도 과거 리텐션 스냅샷이 보존됨. 삭제된 코치의 행은 `coach_id=NULL`이 되고 UI는 `coach_name_snapshot`으로 표시(예: `Alex (삭제됨)`).

**`UNIQUE (coach_id, base_month)`**: MySQL `UNIQUE`는 NULL을 중복 간주하지 않으므로, 서로 다른 타이밍에 삭제된 코치 두 명이 같은 base_month에 NULL로 남아도 충돌 없음. 재계산은 "coach_id NOT NULL"인 행에만 영향.

**`updated_at DATETIME(3)`**: 밀리초 정밀도. `update_allocation` 낙관적 락 토큰으로 사용 (§7, §9.5).

### 5.2 `coach_retention_runs`
```sql
CREATE TABLE `coach_retention_runs` (
  `base_month` VARCHAR(7) PRIMARY KEY,
  `total_new` INT NOT NULL DEFAULT 0,
  `unmapped_coaches` LONGTEXT DEFAULT NULL,   -- JSON: {pt_only:[...], coach_site_only:[...]}
  `calculated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `calculated_by` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
`unmapped_coaches`를 스냅샷에 같이 저장 → `view` 액션은 coach DB를 건드리지 않아도 경고 배너 복원 가능.

### 5.3 `change_logs` ENUM 확장
```sql
ALTER TABLE change_logs MODIFY target_type
  ENUM('member','order','coach_assignment','merge','retention_allocation') NOT NULL;
```

### 5.4 재계산 시 필드별 정책

| 필드 | 정책 |
|---|---|
| `coach_name_snapshot`, `grade`, `rank_num`, `total_score`, `*_retention_3m`, `assigned_members`, `requested_count`, `auto_allocation`, `monthly_detail` | 덮어쓰기 |
| `final_allocation` | 신규 행이면 `auto_allocation`으로 초기화. 이미 존재하면 **보존** |
| `adjusted_by`, `adjusted_at` | 수동 조정 시에만 기록. 재계산으로 변경 없음 |
| `updated_at` | MySQL `ON UPDATE CURRENT_TIMESTAMP(3)`로 자동 갱신 — 재계산·리셋·수동조정 모두 토큰을 bump (§9.5) |

## 6. 계산 로직

`public_html/api/retention.php` (신규)에 이식. 기반은 coach 사이트의 `retention_calc.php`이며, 다음 세 가지만 변경한다.

### 6.1 월 시프트
```php
// BEFORE (coach 사이트): base, base-1, base-2
for ($i = 0; $i < 3; $i++) { $months[] = date('Y-m', strtotime("$baseMonth-01 -$i months")); }

// AFTER (PT): base-1, base-2, base-3
for ($i = 1; $i <= 3; $i++) { $months[] = date('Y-m', strtotime("$baseMonth-01 -$i months")); }
```
`base_month=2026-05`일 때 current 후보 `[2026-04, 2026-03, 2026-02]`, 각 current에 대해 prev=current-1 → 측정 구간 **1→2, 2→3, 3→4**.

### 6.2 크로스-DB + 코치 매핑
- 계산 시작 시 **양쪽 코치 리스트를 모두 로드**:
  - PT: `SELECT id, coach_name FROM coaches WHERE status='active'`
  - COACH: `SELECT id, name FROM SORITUNECOM_COACH.coaches WHERE is_active=1`
- 영문명(`coach_name` ↔ `name`) 정확 일치로 매핑 맵 구성. `unmapped_coaches`는 두 방향 모두 기록:
  - `pt_only`: PT에 있는데 coach DB에 같은 영문명 없음 — 결과 행은 만들되 리텐션 0%·담당 0명·등급 D·희망 0·자동 0
  - `coach_site_only`: coach DB에 있는데 PT에 없음 — 결과에서 제외하고 UI 경고 배너로 이름 노출
- coach 사이트 모든 쿼리의 `coach_id`는 위 맵을 거쳐 치환
- `retention_score_criteria`, `grade_criteria`는 coach DB에서 직접 읽음
- 각 coach에 대한 `getLatestRequest`는 `SORITUNECOM_COACH.coach_assignment_requests`에서 조회

### 6.3 저장 대상
- PT `coach_retention_scores` UPSERT: `ON DUPLICATE KEY UPDATE`로 작성
- `coach_id`는 매핑 성공한 PT coaches.id. `coach_name_snapshot`은 계산 시점 PT coach_name
- `final_allocation`은 `COALESCE(기존값, VALUES(auto_allocation))` 패턴으로 신규 행만 초기화
- `updated_at`은 MySQL이 자동 bump (낙관적 락 토큰, §9.5) — 재계산은 모든 영향 행의 토큰을 갱신 → 대기 중이던 stale `update_allocation`이 자동 거절됨
- `coach_retention_runs`에 base_month, total_new, unmapped_coaches, calculated_at, calculated_by 업서트

### 6.4 나머지 로직 (변경 없음)
- 점수 환산 (`retentionToScore`)
- 상위권: `round(희망 × hope_ratio)`
- 하위권: `remaining = max(0, total_new - upperAlloc)`을 `희망 × remain_ratio` 가중치로 분배
- 반올림 오차를 하위권 꼴등 코치에 몰아주기
- 하위권 전체 가중치 0 → 균등 분배 fallback

**참고**: 이 로직의 알려진 특성(상위권 합 > total_new일 때 총합 초과, 하위권 등급 경계 모호, 꼴등 오차 편중, 희망 0명 코치 미배정)은 수동 조정 UI에서 해소한다. 로직 자체는 수정하지 않는다.

### 6.5 UI 월 시프트 표기
기준월 선택 input 옆에 동적 텍스트:
> 기준월 **2026-05** → 측정 구간: 2026-01→02, 2026-02→03, 2026-03→04 (배정월 **2026-04까지** 반영)

## 7. API 엔드포인트

`public_html/api/retention.php` 단일 파일, `?action=xxx` 패턴.

| Method | Action | 입력 | 출력 | 비고 |
|---|---|---|---|---|
| GET | `snapshots` | — | `[{base_month, total_coaches, last_calculated_at, total_new}, …]` | 드롭다운/탭용. `total_coaches`는 `SELECT base_month, COUNT(*) FROM coach_retention_scores GROUP BY base_month`로 집계 (denormalize 하지 않음) |
| GET | `view` | `base_month` | `{rows, unmapped_coaches, summary, total_new}` | 저장된 결과 조회 (coach DB 접근 불필요). 각 row에 `updated_at` 포함 |
| POST | `calculate` | `base_month`, `total_new` | `{rows, unmapped_coaches, summary}` | 재계산 + UPSERT. final_allocation은 신규 행만 초기화. 모든 영향받은 행의 `updated_at` bump |
| POST | `update_allocation` | `id`, `final_allocation`, `expected_updated_at` | `{ok:true, row}` 또는 `{ok:false, code:'conflict', row}` | 단일 코치 최종 배정 편집. 낙관적 락(§9.5). `change_logs` 기록 |
| POST | `reset_allocation` | `base_month` | `{ok, updated_rows}` | 전체 final_allocation을 auto로 리셋. 영향받은 행의 `updated_at` bump |
| POST | `delete_snapshot` | `base_month` | `{ok, deleted_scores, deleted_runs}` | `coach_retention_scores`와 `coach_retention_runs` 해당 월을 단일 트랜잭션으로 삭제. 확인 모달 필수 |

**공통**:
- 모든 액션 `requireAdmin()` 게이트
- 응답 포맷: 기존 `jsonSuccess / jsonError` 헬퍼 재사용
- `rows` 공통 스키마: 등수, 코치명, 등급, 총점, new_retention_3m, existing_retention_3m, assigned_members, requested_count, auto_allocation, final_allocation, monthly_detail (상세 펼침용)
- `summary`: `{total_new, sum_auto, sum_final, unallocated}`

**입력 검증**:
- `base_month`: `/^\d{4}-\d{2}$/`
- `total_new`: 0 ~ 10000 정수
- `final_allocation`: 0 ~ 9999 정수

**동시성**: §9.5 낙관적 락으로 보호. debounce 대기 중인 요청과 `reset_allocation`/`calculate`의 경합을 자동으로 감지해 충돌 시 클라이언트가 갱신값으로 리프레시.

## 8. Admin UI

### 8.1 라우팅
- 사이드바 `retention` 추가: `admin/index.php`에 `<a href="#retention" data-page="retention">리텐션관리</a>`
- 새 파일: `admin/js/pages/retention.js`
- 기존 `App.registerPage` 패턴 준수

### 8.2 화면 구성 (상 → 하)

**① 계산 실행 카드**
- 기준월 `<input type="month">`
- 전체 신규 인원 숫자 input
- 계산 실행 버튼
- 월 시프트 텍스트 (기준월 변경 시 동적 갱신)

**② 스냅샷 탭 줄**
- 저장된 base_month 목록을 버튼으로 나열. 활성 탭은 Orange 강조
- 우측에 "스냅샷 삭제" 버튼 (확인 모달)

**③ 요약 패널 (sticky top)**
- 카드 4개: 전체 신규 / 자동 배정 합 / 현재 합(수정 후) / **잔여**
- 잔여 색상: 양수=주황, 0=회색, 음수=빨강
- "자동값으로 리셋" 버튼

**④ 결과 테이블**

| 등수 | 코치 | 등급 | 총점 | 3M 신규 | 3M 기존 | 담당 | 희망 | 자동 | **최종** | 상세 |

- **최종 셀**: 숫자 input + `−/+` 버튼
- input change → 로컬 합산 재계산 → ③ 잔여 즉시 갱신. **debounce 600ms** 후 `update_allocation` API 호출
- 저장 상태 표시 (스피너/체크/에러 아이콘)
- 행 클릭 시 월별 breakdown 표 토글

**⑤ 매핑 안 된 코치 배너**
- `unmapped_coaches` 있으면 노란 배너로 노출:
  > ⚠️ 아래 코치는 coach 사이트와 이름(영문) 정합이 안 돼서 리텐션에 포함되지 않았습니다: …

### 8.3 상호작용
- 빈 상태: "아직 계산된 스냅샷이 없습니다. 기준월과 전체 신규 인원을 입력하고 계산하세요."
- 페이지 진입 시 최신 스냅샷 자동 로드
- 페이지 이탈 시 debounce 대기 중이면 강제 flush
- 옵티미스틱 업데이트 + 실패 시 서버 값으로 롤백
- **충돌 처리** (§9.5): `update_allocation` 응답이 `code:'conflict'`면 해당 행만 서버 값으로 교체 + 토스트 "다른 작업으로 갱신되었습니다, 최신값으로 로드했습니다"
- **재계산/리셋 직후**: 테이블의 모든 행 `updated_at`이 무효화되므로 서버가 돌려준 새 rows를 통째로 교체 렌더링

## 9. 권한 & 에러 처리

### 9.1 MySQL GRANT (선행 1회 작업)
```sql
GRANT SELECT ON SORITUNECOM_COACH.coaches TO 'SORITUNECOM_PT'@'localhost';
GRANT SELECT ON SORITUNECOM_COACH.coach_member_mapping TO 'SORITUNECOM_PT'@'localhost';
GRANT SELECT ON SORITUNECOM_COACH.retention_score_criteria TO 'SORITUNECOM_PT'@'localhost';
GRANT SELECT ON SORITUNECOM_COACH.grade_criteria TO 'SORITUNECOM_PT'@'localhost';
GRANT SELECT ON SORITUNECOM_COACH.coach_assignment_requests TO 'SORITUNECOM_PT'@'localhost';
FLUSH PRIVILEGES;
```
PT에는 DEV/PROD DB 분리가 없으므로 PROD에만 적용.

### 9.2 크로스-DB 실패 처리
- `calculate` 시 coach DB 쿼리 실패 → 트랜잭션 롤백 + `jsonError('coach 사이트 DB 접근 실패: …')`
- `view`는 PT 테이블만 읽으므로 coach 사이트 상태와 무관

### 9.3 코치 매핑 실패
계산 시작 시 양쪽 코치 리스트를 모두 로드해 두 방향 매핑 실패를 수집한다 (§6.2 참조).

- **coach 사이트에만 존재** (`coach_site_only`): 결과에서 제외, `unmapped_coaches.coach_site_only`에 이름 추가, UI 경고 배너에 노출
- **PT에만 존재** (`pt_only`): 결과 행은 만들되 리텐션 0%, 담당 0명, 등급 D, 희망 0, 자동 0. 필요 시 행에 "데이터 없음" 라벨 표시
- 매핑은 재계산마다 다시 체크되므로 이름 수정으로 회복

### 9.4 감사 로그
`update_allocation` 성공 시 PT `change_logs`에 기록:
- `target_type='retention_allocation'`
- `target_id=coach_retention_scores.id`
- `old_value={final_allocation:이전값}`, `new_value={final_allocation:새값}`
- `actor_type='admin'`, `actor_id=관리자.id`

### 9.5 동시성 — 낙관적 락 프로토콜

**모티브**: debounce 600ms 대기 중인 UPDATE 요청이 `reset_allocation`·`calculate` 이후에 늦게 도착해 최신 상태를 덮어쓰는 경합을 방지한다.

**토큰**: `coach_retention_scores.updated_at` (DATETIME(3), 밀리초 정밀도, `ON UPDATE CURRENT_TIMESTAMP(3)`).

**프로토콜**:

1. 클라이언트는 `view` / `calculate` 응답에서 각 행의 `updated_at`을 받아 보관.
2. `update_allocation` 호출 시 `{id, final_allocation, expected_updated_at}`을 보냄.
3. 서버 쿼리:
   ```sql
   UPDATE coach_retention_scores
      SET final_allocation = ?, adjusted_by = ?, adjusted_at = NOW()
    WHERE id = ?
      AND updated_at = ?;
   ```
   `ROW_COUNT() = 1` → 성공. 응답으로 최신 행(`{id, final_allocation, updated_at}`) 반환.
   `ROW_COUNT() = 0` → 충돌. 해당 행을 다시 SELECT해서 `{ok:false, code:'conflict', row}` 반환.
4. 클라이언트는 `conflict` 수신 시 해당 행을 서버 값으로 리프레시 + 토스트("다른 작업으로 갱신되었습니다, 최신값으로 로드했습니다").
5. `reset_allocation`·`calculate`는 같은 `updated_at`을 bump하므로 대기 중이던 stale 요청은 자동으로 거절된다.

**동일 사용자 경합 완화**: 페이지 이탈 시 debounce flush는 유지하되, `beforeunload` 후 도착한 응답은 UI가 이미 없으므로 무시.

## 10. coach 사이트 1단계 hotfix 상세

### 10.1 파일
`coach.soritune.com:public_html/coach/request.php`

### 10.2 변경
- Line 128 `<th>등급</th>` 제거
- Line 134~137의 `badgeMap`, `$bc` 변수 제거
- Line 141~142의 `<td><span class="badge …">…</span></td>` 셀 제거
- 저장 로직(`grade_at_request` insert, Line 32~42)은 유지 — admin 리포트에서 계속 사용

### 10.3 배포
coach 레포 `main` 브랜치 커밋 → 사용자 승인 후 prod pull.

## 11. 테스트 계획 (수동)

### 11.1 선행 GRANT 검증
- PT admin 로그인 후 `calculate` 호출 → coach DB 쿼리 성공 여부

### 11.2 월 시프트 정합성
- coach 사이트 `base=2026-04` 스냅샷의 `monthly_detail`과 PT `base=2026-05` 계산의 `monthly_detail`이 **같은 측정 구간**(03→04, 02→03, 01→02)을 보이는지, 같은 리텐션율·점수·등급이 나오는지 스팟체크

### 11.3 자동 배분 로직 동일성
- 같은 criteria·희망 데이터·같은 측정 구간에서 PT 자동 배분 결과가 coach 사이트와 일치 (월 시프트 제외)
- 상위권 합 > total_new 케이스에서 "합계 초과" 현상 재현 (의도된 동작 확인)

### 11.4 코치 매핑
- 정상 일치: 계산 포함
- PT에만 존재: 결과 행 있고 리텐션 0%
- coach DB에만 존재: `unmapped_coaches` 배너에 노출
- 대소문자 차이: 별도 코치로 취급 (규칙 확인)

### 11.5 수동 조정 흐름
- 자동 계산 직후 `final_allocation = auto_allocation`
- 개별 셀 변경 → 잔여 즉시 갱신
- 새로고침 후 유지
- 같은 base_month 재계산 → auto/등급/점수는 갱신, `final_allocation` 보존
- "자동값으로 리셋" → final이 auto로 복원
- `change_logs`에 변경 이력 남음

### 11.5a 동시성 시나리오 (§9.5 검증)
- **Reset 경합**: 행 A 편집 → debounce 대기 중에 `자동값으로 리셋` 클릭 → 늦게 도착한 A의 update 요청이 409로 거절되고 UI는 A의 auto 값으로 복원
- **Calculate 경합**: 행 A 편집 → debounce 대기 중 `계산 실행` → 재계산은 auto만 갱신·final 보존이지만 `updated_at`이 바뀜 → A의 업데이트는 409로 거절되고 UI는 서버의 현재 값을 표시
- **탭 2개**: 탭1에서 A=5로 편집, 탭2에서 A=7로 편집 → 나중 도착이 409로 거절, UI가 승자 값으로 동기화
- **삭제 코치 스냅샷**: PT에서 코치 삭제 → 과거 스냅샷 행은 `coach_id=NULL, coach_name_snapshot="이름"` 상태로 조회됨. UI는 "이름 (삭제됨)" 표시

### 11.6 잔여 극단값
- total_new < 자동 합: 잔여 음수(빨강)
- total_new = 0: 자동 0, 잔여 0
- 매핑 실패 코치만 있는 상태: 빈 테이블 + 경고 배너

### 11.7 1단계 hotfix 검증
- 코치 계정으로 `/coach/request.php` 접속 → "등급" 컬럼 없음
- admin `/admin/assignment.php` → "등급" 계속 보임

### 11.8 자동화 테스트
- PT 레포에 테스트 러너 없음 → 이번 작업에선 **미추가**. 본 체크리스트를 수동 검증 기준으로 사용.

## 12. 배포 순서

**1단계**
```
coach-repo: request.php hotfix 커밋 → main 머지 → 사용자 승인 → prod pull
```

**2단계**

PT는 DEV/PROD 분리가 없고 단일 main 브랜치이므로 커밋 = prod 반영. 리스크를 낮추기 위해 순서를 다음과 같이 한다:

```
(a) 로컬에서 코드 작성 & 수동 검증 (PHP 내장 서버 등)
(b) PT DB에 마이그레이션 적용:
    - migrations/NNNN_add_coach_retention.sql
      - coach_retention_scores 생성 (coach_id nullable + SET NULL FK,
        coach_name_snapshot NOT NULL, updated_at DATETIME(3) ON UPDATE)
      - coach_retention_runs 생성 (unmapped_coaches JSON 포함)
      - change_logs.target_type ENUM 확장: 'retention_allocation' 추가
(c) GRANT 스크립트 실행 (PT MySQL 유저에게 COACH DB 읽기 권한 부여)
(d) (a)의 코드 커밋 → push → main (prod 반영)
(e) prod에서 사용자 수동 검증 (§11 체크리스트)
(f) 문제 시 hotfix
```

(b)(c)가 (d)보다 먼저여야 한다 — 테이블이 없는 상태로 코드가 prod에 올라가면 admin 탭이 즉시 깨진다.

## 13. 범위 밖 (후속 작업 후보)

- 매칭 시스템을 PT에 구축 (구매→매칭→orders 단일 시스템)
- 구매 데이터 업로드 UI의 PT 이식 및 coach 사이트 폐기
- 점수/등급 기준표 관리 UI의 PT 이식
- `retention_score_criteria`, `grade_criteria`의 PT 소유 이전
- 자동 배분 로직 재설계 (등급별 총량 보장형 등)
- 리텐션 계산 자동화 테스트 추가
