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
  `coach_id` INT NOT NULL,                 -- PT coaches.id
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
  UNIQUE KEY `uq_coach_month` (`coach_id`, `base_month`),
  FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
  INDEX `idx_base_month` (`base_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

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
| `grade`, `rank_num`, `total_score`, `*_retention_3m`, `assigned_members`, `requested_count`, `auto_allocation`, `monthly_detail` | 덮어쓰기 |
| `final_allocation` | 신규 행이면 `auto_allocation`으로 초기화. 이미 존재하면 **보존** |
| `adjusted_by`, `adjusted_at` | 수동 조정 시에만 기록. 재계산으로 변경 없음 |

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
- `final_allocation`은 `COALESCE(기존값, VALUES(auto_allocation))` 패턴으로 신규 행만 초기화
- `coach_retention_runs`에 base_month, total_new, calculated_at, calculated_by 업서트

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
| GET | `snapshots` | — | `[{base_month, total_coaches, last_calculated_at, total_new}, …]` | 드롭다운/탭용 |
| GET | `view` | `base_month` | `{rows, unmapped_coaches, summary, total_new}` | 저장된 결과 조회 (coach DB 접근 불필요) |
| POST | `calculate` | `base_month`, `total_new` | `{rows, unmapped_coaches, summary}` | 재계산 + UPSERT. final_allocation은 신규 행만 초기화 |
| POST | `update_allocation` | `id`, `final_allocation` | `{ok, summary}` | 단일 코치 최종 배정 편집. `change_logs` 기록 |
| POST | `reset_allocation` | `base_month` | `{ok}` | 전체 final_allocation을 auto로 리셋 |
| POST | `delete_snapshot` | `base_month` | `{ok}` | 스냅샷 삭제 (확인 모달 필수) |

**공통**:
- 모든 액션 `requireAdmin()` 게이트
- 응답 포맷: 기존 `jsonSuccess / jsonError` 헬퍼 재사용
- `rows` 공통 스키마: 등수, 코치명, 등급, 총점, new_retention_3m, existing_retention_3m, assigned_members, requested_count, auto_allocation, final_allocation, monthly_detail (상세 펼침용)
- `summary`: `{total_new, sum_auto, sum_final, unallocated}`

**입력 검증**:
- `base_month`: `/^\d{4}-\d{2}$/`
- `total_new`: 0 ~ 10000 정수
- `final_allocation`: 0 ~ 9999 정수

**동시성**: 단일 행 UPDATE 기준 last-write-wins. 현실 사용 패턴상 문제없음.

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
      (coach_retention_scores 생성, coach_retention_runs 생성,
       change_logs.target_type ENUM 확장)
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
