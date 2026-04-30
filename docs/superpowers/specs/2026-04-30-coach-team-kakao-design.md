# 코치 팀/카톡방 정보 추가 설계

**작성일**: 2026-04-30
**도메인**: pt.soritune.com (PT 코치 관리)
**상태**: design

## 1. 배경 및 목적

PT 코치마다 (1) 소속 팀과 (2) 1:1PT 카톡방 링크를 관리할 수 있어야 한다.

- **팀**: 팀 이름은 그 팀의 팀장 코치 이름을 그대로 사용한다 (예: "Kel팀"). 어드민에서 코치별 팀 소속과 팀장 여부를 설정/수정한다. 향후 팀장이 자기 팀원을 관리하고 팀 데이터를 볼 수 있게 확장할 예정.
- **카톡방 링크**: 코치당 1개. 어드민에서 설정/수정한다. 향후 코치 본인 편집 가능 여부는 미정. 알림톡 시나리오에서 변수로 사용될 데이터.

이번 작업 범위는 **데이터 모델 + 어드민 편집 + 코치 페이지 "내 정보" 읽기 전용 노출**까지. 알림톡 시나리오 연동과 코치 본인 편집 기능은 후속 작업.

## 2. 데이터 모델

### 2.1 스키마 변경

```sql
-- migrations/20260430_add_coach_team_and_kakao.sql
ALTER TABLE coaches
  ADD COLUMN team_leader_id INT NULL DEFAULT NULL AFTER role,
  ADD COLUMN kakao_room_url VARCHAR(255) NULL DEFAULT NULL AFTER memo,
  ADD INDEX idx_team_leader (team_leader_id),
  ADD CONSTRAINT fk_coach_team_leader
      FOREIGN KEY (team_leader_id) REFERENCES coaches(id) ON DELETE SET NULL;
```

### 2.2 시맨틱

| `team_leader_id` | 의미 |
|------------------|------|
| `= self.id`      | 본인이 팀장 (자기 팀의 멤버이기도 함) |
| `= 다른 코치 id` | 그 코치가 이끄는 팀의 팀원 |
| `IS NULL`        | 미배정 (신규/평가 전/inactive 등) |

`kakao_room_url IS NULL` → 카톡방 미설정.

**별도 `teams` 테이블을 두지 않는 이유**: 팀이 "팀장 이름 + '팀'" 외 메타데이터(설명/목표/시작일 등)를 갖지 않는다. 팀 정체성 = 팀장 정체성. self-FK 한 컬럼으로 충분하며 YAGNI 원칙.

### 2.3 시드 데이터 (같은 마이그레이션 파일 안)

**팀 구성** — Active 코치 30명을 3개 팀에 배정:

- **Kel팀** (팀장 Kel): Kel, Lulu, Ella, Jay, Darren, Cera, Jacey, Ethan, Sen, Sophia (10명)
- **Nana팀** (팀장 Nana): Nana, Hyun, Raina, Bree, Kathy, Anne, Ej, Tia, Rin, Jenny (10명)
- **Flora팀** (팀장 Flora): Flora, Rachel, Julia, Frida, Jun, Salley, Hani, Tess, Hazel, Sophie (10명)

**카톡방 링크** — 28명에게 시드 (Jenny, Frida 제외). 28개 URL은 `migrations/20260430_add_coach_team_and_kakao.sql` 안에 인라인.

**inactive 코치 28명**은 모두 `team_leader_id = NULL`, `kakao_room_url = NULL` (현재 운영 외).

**대소문자 표준화**: 사용자 입력 데이터의 "EJ"는 DB의 `coach_name = 'Ej'`로 통일하여 시드.

## 3. API 변경 (`public_html/api/coaches.php`)

### 3.1 list

응답에 `team_leader_name`, `team_member_count` 추가:

```sql
SELECT c.*,
  leader.coach_name AS team_leader_name,
  (SELECT COUNT(*) FROM coaches m WHERE m.team_leader_id = c.id) AS team_member_count,
  (SELECT COUNT(DISTINCT o.member_id) FROM orders o
   WHERE o.coach_id = c.id AND o.status = '진행중') AS current_count
FROM coaches c
LEFT JOIN coaches leader ON leader.id = c.team_leader_id
ORDER BY c.status ASC, c.coach_name ASC
```

### 3.2 create

입력 처리:
- `is_team_leader` (boolean) + `team_leader_id` (int|null)
- `is_team_leader=true` → INSERT 후 lastInsertId로 `UPDATE coaches SET team_leader_id = id WHERE id = lastInsertId` (단일 트랜잭션)
- `is_team_leader=false` → 입력 `team_leader_id` 그대로 저장 (NULL 허용)

검증:
- `kakao_room_url`: 빈 문자열은 NULL로 정규화. 비어있지 않으면 정규식 `^https://open\.kakao\.com/(o|me)/[A-Za-z0-9_]+$` 통과 필수
- `team_leader_id`가 본인 외 값일 때: 그 코치가 (a) `team_leader_id = self.id`이고 (b) `status = 'active'`인지 확인. 위반 시 차단

### 3.3 update

위 검증 동일 적용. 추가로 cascade 차단 (Q4-C):

| 시도 | 차단 조건 | 메시지 |
|------|-----------|--------|
| 팀장의 `status = 'inactive'`로 변경 | 팀원 N >= 1 | "이 팀에 팀원 N명이 있습니다. 먼저 다른 팀장을 지정하거나 팀원을 미배정 처리하세요" |
| 팀장의 `team_leader_id`를 본인 외 값으로 변경 (= 팀장 해제: 다른 팀장 id 또는 NULL) | 팀원 N >= 1 | (동일) |

여기서 "팀원 N"은 `SELECT COUNT(*) FROM coaches WHERE team_leader_id = ? AND id != ?` (자신 제외 — 팀장 본인은 카운트에 포함되지 않음).

### 3.4 delete

기존 차단(`진행중` 회원 있음)에 추가:
- 팀원 1명 이상 있는 팀장 → 차단 (위와 동일 메시지)

ON DELETE SET NULL FK가 있으나, 팀이 무방비 해체되는 사고 방지를 위해 API 레벨 차단을 우선 적용.

## 4. 어드민 UI (`public_html/admin/js/pages/coaches.js`)

### 4.1 목록 표

새 컬럼 1개: **"팀"** (위치: "직급" 옆)

표시 규칙:
- 팀장 (`c.team_leader_id == c.id`): `★ Kel팀` (Soritune Orange `#FF5E00` 강조)
- 팀원 (`c.team_leader_id`가 있고 본인 아님): `Kel팀` (일반 텍스트)
- 미배정: `-` (secondary 색상)

팀명 = `team_leader_name + '팀'` (서버에서 JOIN)

### 4.2 편집/추가 모달

#### 4.2.1 "팀" 그룹 (직급/평가 행 옆 새 row)

```html
<div class="form-group">
  <label class="form-label">팀장 여부</label>
  <label style="display:flex;align-items:center;gap:8px">
    <input type="checkbox" name="is_team_leader" value="1">
    팀장으로 지정 (본인 이름의 팀이 자동 생성됨)
  </label>
</div>
<div class="form-group">
  <label class="form-label">소속 팀</label>
  <select class="form-select" name="team_leader_id">
    <option value="">(미배정)</option>
    <!-- active 팀장 목록 -->
  </select>
</div>
```

상호작용:
- 팀장 체크 시 → "소속 팀" 드롭다운 disabled (소속은 본인 자동)
- 팀장 해제 시 → 드롭다운 enabled

팀장 목록: `coaches.list` 응답에서 `c.team_leader_id == c.id && c.status == 'active'`인 코치 클라이언트 측 필터링. 별도 API 불필요.

#### 4.2.2 카톡방 링크 (메모 위 새 row)

```html
<div class="form-group">
  <label class="form-label">1:1PT 카톡방 링크</label>
  <input class="form-input" type="url" name="kakao_room_url"
         placeholder="https://open.kakao.com/o/...">
  <div class="form-hint" id="kakaoUrlError" style="display:none;color:var(--text-negative)"></div>
</div>
```

클라이언트 측 검증: submit 전 정규식 `^https?://open\.kakao\.com/(o|me)/[A-Za-z0-9_]+$` 체크 (서버와 동일). 실패 시 인라인 에러 메시지.

### 4.3 제출 시 직렬화

```js
body.is_team_leader = form.elements.is_team_leader.checked ? 1 : 0;
body.team_leader_id = body.is_team_leader
  ? null  // 서버가 self.id로 채움
  : (form.elements.team_leader_id.value || null);
body.kakao_room_url = (body.kakao_room_url || '').trim() || null;
```

## 5. 코치 페이지 — "내 정보" 신규

### 5.1 사이드바 등록 (`public_html/coach/index.php`)

```html
<nav class="sidebar-nav">
  <a href="#my-members" data-page="my-members">내 회원</a>
  <a href="#kakao-check" data-page="kakao-check">카톡방 입장 체크</a>
  <a href="#my-info" data-page="my-info">내 정보</a>
</nav>
```

스크립트 등록: `<script src="/coach/js/pages/my-info.js"></script>`.

### 5.2 신규 API (`public_html/api/coach_self.php`)

`requireCoach()` 가드. 액션 1개: `GET ?action=get_info`.

응답 구조 (팀원/팀장/미배정 분기):

```json
// 팀원
{
  "ok": true,
  "data": {
    "self": { "coach_name": "Lulu", "korean_name": "...", "kakao_room_url": "https://..." },
    "team": { "name": "Kel팀", "leader_name": "Kel" },
    "is_leader": false
  }
}

// 팀장 (members 배열 포함)
{
  "ok": true,
  "data": {
    "self": { ... },
    "team": { "name": "Kel팀", "leader_name": "Kel" },
    "is_leader": true,
    "members": [
      { "coach_name": "Lulu", "korean_name": "...", "kakao_room_url": "https://..." },
      ...
    ]
  }
}

// 미배정
{
  "ok": true,
  "data": { "self": { ... }, "team": null, "is_leader": false }
}
```

### 5.3 프론트 (`public_html/coach/js/pages/my-info.js`)

공통 섹션:
- **내 정보**: 이름·한글이름·카톡방 링크 (링크는 클립보드 복사 버튼 포함)
- **소속 팀**: `Kel팀 (팀장: Kel)` 또는 `미배정`

팀장만 노출:
- **우리 팀 코치** 표 — 본인 포함 전체 팀원의 이름·한글이름·카톡방 링크 (각 행에 복사 버튼)

### 5.4 권한 분리 (Q9-A)

- API에서 본인 외 데이터를 직접 반환하지 않음 (URL param으로 다른 코치 id 못 받음)
- **팀원 응답에 다른 팀원의 카톡방 링크 절대 포함 안 함**
- 팀장 응답의 `members` 배열은 같은 팀(`team_leader_id = self.id`)에 한정

## 6. 테스트 (`tests/coach_team_kakao_test.php`)

기존 패턴(`t_section`, `t_assert_eq`, DB 트랜잭션 격리) 준수.

검증 항목:

1. **URL 검증 정규식** (순수 함수 테스트)
   - PASS: `https://open.kakao.com/o/sz1en1ag`, `https://open.kakao.com/me/raina`
   - FAIL: 빈 문자열은 NULL로 정규화 OK, `http://...`, `https://kakao.com/o/x`, `https://open.kakao.com/x/abc`, JS injection 시도

2. **시드 데이터 정합성** (마이그레이션 후)
   - 팀장 3명(Kel/Nana/Flora) `team_leader_id == self.id`
   - 30명 active 코치 모두 NULL 아닌 팀에 배정
   - 28개 카톡방 URL 정규식 통과 (Jenny, Frida NULL OK)

3. **Cascade 차단 (Q4-C)** — DB 트랜잭션 격리
   - 팀원 있는 팀장 `status='inactive'` UPDATE → 차단
   - 팀원 있는 팀장 `team_leader_id` 변경(팀장 해제) → 차단
   - 팀원 있는 팀장 DELETE → 차단
   - 팀원 0명인 팀장은 위 작업 모두 허용

4. **유효성 검증**
   - inactive 코치를 `team_leader_id`로 지정 → 차단
   - 팀장이 아닌 코치를 `team_leader_id`로 지정 → 차단

5. **코치 self API 권한 분리 (Q9-A)**
   - 팀원 응답에 `members` 필드 없음
   - 다른 팀원의 `kakao_room_url`이 응답에 노출 안 됨
   - 팀장 응답의 `members` 배열은 같은 팀(`team_leader_id = self.id`)에 한정 (다른 팀 팀원이 섞여 들어가지 않음)
   - 비-코치 세션(어드민 등)이 `coach_self.php`를 호출하면 401/403

## 7. 알림톡 변수 노출 (이번 범위)

이번엔 **데이터만** 준비. 향후 시나리오 정의(`includes/notify/scenarios/*.php`)에서 자유롭게 사용:

- 시나리오 변수 매핑 단계에서 `coaches` JOIN하여 `coach_name`, `kakao_room_url` 추출 가능
- 별도 어댑터/헬퍼는 시나리오가 실제로 필요할 때 추가 (YAGNI)

## 8. 결정 이력 (Brainstorming Q&A)

| Q | 결정 |
|---|------|
| Q1 팀-코치 관계 | A&C — 한 코치는 한 팀, 팀장도 팀의 멤버, 미배정 가능 |
| Q2 카톡방 링크 개수 | 코치당 1개 (오픈카톡 URL) |
| Q3 어드민 UX | A — 모달에 "팀장 여부" 체크박스 + "소속 팀" 드롭다운 |
| Q4 팀장 비활성/삭제 시 | C — 팀원 있으면 차단 (안전 우선) |
| Q5 목록 표 컬럼 | A — "팀" 컬럼만 (팀장은 ★) |
| Q6 카톡방 검증 | B — `^https://open\.kakao\.com/(o|me)/[A-Za-z0-9_]+$` |
| Q7 코치 페이지 노출 | C — "내 정보" 페이지 신규 |
| Q8 마이그레이션 | B — 시드 데이터 SQL에 인라인 (사용자 제공) |
| Q9 팀원 명단 가시성 | A — 팀장만 전체 팀원 봄, 팀원은 본인 + 소속 팀명만 |

## 9. 범위 외 (후속 작업)

- 코치 본인의 카톡방 링크 자가 편집 (Q에서 "추후 고민" 명시)
- 팀장의 팀원 데이터 관리 화면 (현재는 명단 표시까지만)
- 알림톡 시나리오 정의 및 변수 매핑 (시나리오가 실제로 필요할 때 별도 spec)
- CSV 일괄 import 도구 (코치 수 적어 ROI 낮음)

## 10. 마이그레이션/배포 흐름

1. `pt-dev`에서 마이그레이션 파일 작성 + DEV DB 적용 + 시드 검증
2. 코드 변경 (API/어드민/코치 페이지/테스트) 후 dev 브랜치 commit/push
3. dev 환경에서 사용자 확인 → 명시적 운영 반영 요청 시 main merge + prod pull + PROD DB 마이그레이션 적용
