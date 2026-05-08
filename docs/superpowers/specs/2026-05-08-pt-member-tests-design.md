# PT 회원 페이지 — 오감각 테스트 + DISC 검사 (Design)

- 작성일: 2026-05-08
- 대상: pt.soritune.com (DEV: dev-pt.soritune.com)
- 코드 수정 범위: pt-dev (DEV_PT, dev 브랜치) — 운영 반영은 사용자 명시 요청 시
- 본 spec scope: **오감각 테스트** 회원 셀프 응시 흐름 (DISC 는 이후 동일 패턴으로 추가 — 시험 러너 인터페이스만 본 spec 에 포함)

---

## 1. 배경 / 목적

PT 운영팀은 회원의 학습 감각 유형(오감각) 과 성향(DISC) 데이터를 코치 매칭·코칭에 사용하기 위해 현재 **구글 폼**으로 응답을 받고 코치/어드민이 수기로 `test_results` 테이블에 옮겨 적고 있음. 이 흐름을 PT 자체 페이지로 대체:

- 회원이 PT 회원 페이지에서 직접 응시 → 결과가 즉시 본인 차트에 저장
- 코치/어드민은 기존 차트 화면에서 자동으로 결과 조회
- 회원 본인은 자기 결과를 다시 볼 수 있고, 원하면 재시험 가능
- 향후 자동 매칭 입력 데이터로 활용 (본 spec scope 밖)

기존 공개 마케팅 페이지 `test.soritune.com/sensestest/` 는 비회원 funnel 용도로 그대로 유지. PT 회원 페이지는 별도 코드 — 마케팅 페이지의 변경이 회원 페이지를 흔들지 않도록 분리.

## 2. 비-목표 (YAGNI)

- 시험 진행 중 자동 저장 / 이어 풀기 (48문항 5분 분량 — 단순 stateless)
- 회원 본인이 자기 이력 전체 타임라인 보기 (회원은 "최신 결과" 만)
- 시험 결과 PDF 다운로드 / 이메일 발송
- 회원 본인의 시험 결과를 SNS 공유 (마케팅 페이지에만 있는 기능)
- 자동 매칭 입력 통합 (별도 spec)
- DISC 시험 본 구현 (러너 인터페이스만 본 spec, 데이터·UI 는 명세 도착 시 별도 spec)
- 회원 신원 강한 검증 — 구글폼 동등 신뢰 모델 (Section 11 알려진 한계)

## 3. 사용자 결정 사항 (브레인스토밍 결과)

- **로그인 매칭 실패** → "고객센터 문의" 안내, 자동 회원 생성 안 함
- **로그인 다중 매칭** → `created_at DESC LIMIT 1` (최신 회원)
- **통합 방식** → 마케팅 페이지의 questions/scoring/categories 를 PT 내부로 **복사** (cross-domain coupling 회피)
- **UI 테마** → PT Spotify 다크 (`#121212` / `#FF5E00` / Pretendard)
- **재시험 정책** → 매 회 새 row, 회원은 "최신 결과" 만, 어드민/코치는 이력 전체
- **결과 노출 범위** → 회원: 점수 + 유형 + 특징 + 추천학습법 / 어드민·코치: 점수 + 유형 + memo (특징·학습법·공유 버튼 제외)
- **진입 경로** → 독립 URL `pt.soritune.com/me/` + 로그인 후 시험 목록 대시보드

## 4. 아키텍처 개요

### 4.1 URL / 진입

```
pt.soritune.com/                기존 (관리자/코치 입구) — 변경 없음
            /admin/             기존
            /coach/             기존
            /me/                NEW — 회원 포털 SPA
                /me/             로그인 화면 또는 (로그인 시) 대시보드
                /me/?test=sensory 시험 진행
                /me/?test=disc    시험 진행 (DISC 본 구현 시)
```

운영팀은 회원에게 `pt.soritune.com/me/` URL 을 공유 (카카오 알림톡, 카페 공지 등). 직접 시험으로 이동하는 deep-link 는 본 spec 에서 제공하지 않음 — 회원이 로그인 → 대시보드 → 시험 카드 선택의 흐름.

### 4.2 세션 모델

기존 `PT_SESSION` 쿠키 재사용. `$_SESSION['pt_user']` 에 다음 형태:

```php
['role' => 'member', 'id' => 123, 'soritune_id' => 'soritunehong', 'name' => '홍길동']
```

`includes/auth.php` 에 `requireMember()` 헬퍼 추가 — admin/coach 가드와 동일 패턴. 세션 라이프타임 24h (기존과 동일).

### 4.3 시험 러너 프레임워크

오감각·DISC 가 동일한 구조 (questions → score → categorize → category 표시). 범용 JS 러너:

```js
TestRunner.run({
  testType: 'sensory',
  questions: [...],
  totalSteps: 5,
  scoring: (checkedSet, questions) => scores,
  categorize: (scores) => 'key',
  categories: { ... },
  onSubmit: (clientResult) => fetch('/api/member_tests.php?action=submit', ...)
});
```

`me/js/sensory.js` 가 본 시험 데이터를 들고 위 러너에 주입. DISC 추가 시 `me/js/disc.js` 가 동일 패턴.

### 4.4 파일 레이아웃

```
public_html/
├─ me/
│  ├─ index.php              회원 포털 entry (SSR 최소: 로그인 상태에 따라 분기)
│  └─ js/
│     ├─ app.js              SPA 라우팅(login/dashboard/test/result)
│     ├─ test-runner.js      범용 러너
│     ├─ sensory.js          오감각 questions + categories + scoring 헬퍼
│     ├─ result-view.js      결과 화면 렌더 (회원용 — 특징·학습법 포함)
│     └─ dashboard.js        시험 목록 카드 렌더
├─ api/
│  ├─ member_auth.php        login / logout / me
│  └─ member_tests.php       submit / latest / history(self)
├─ includes/
│  ├─ auth.php               +requireMember()
│  └─ tests/
│     └─ sensory_meta.php    PHP 측 questions + categories + scoring (단일 source of truth)
└─ assets/css/
   └─ member.css             /me/ 전용 스타일 (PT 토큰 재사용)
```

## 5. 데이터 모델

### 5.1 기존 `test_results` 재사용 (스키마 변경 없음)

```sql
test_results (
  id INT PK,
  member_id INT,
  test_type ENUM('disc','sensory'),
  result_data JSON,
  tested_at DATE,
  memo TEXT,
  created_at DATETIME
)
```

마이그레이션 불필요. 기존 어드민 수동 입력 row 와 신규 회원 셀프 응시 row 가 같은 테이블에 공존.

### 5.2 `result_data` JSON 스키마 — sensory v1

```json
{
  "version": 1,
  "answers": [0, 1, 1, 0, ...],
  "scores": {
    "auditory":    { "checked": 7,  "total": 12 },
    "visual":      { "checked": 9,  "total": 14 },
    "kinesthetic": { "checked": 6,  "total": 22 }
  },
  "percents": { "auditory": 58, "visual": 64, "kinesthetic": 27 },
  "key": "1,1,0",
  "title": "청각 + 시각 혼합 학습자",
  "subtitle": "소리와 이미지를 결합한 학습법이 효과적",
  "submitted_at": "2026-05-08T14:23:11+09:00"
}
```

- `answers` 는 원시 응답 (향후 문항·카테고리 개정 시 재분석 가능)
- `percents` 는 결과 그래프 즉시 렌더 (DB 에서 재계산 안 함)
- `key`/`title`/`subtitle` 은 검색·요약 표시용 스냅샷 (저장 시점 메타 기준)
- `version` 은 메타 개정 시 증가 (현재 `1`)

### 5.3 DISC 스키마 (본 spec 미구현 — 명세 도착 시 보강)

동일 구조: `{version, answers, scores, percents 또는 type, ...}`. 본 spec 의 시험 러너·인증·저장 흐름은 그대로 활용.

### 5.4 인덱스

기존 `INDEX idx_member_id` 가 모든 조회 패턴 (본인/코치/어드민 회원별 이력) 커버. 추가 인덱스 불필요.

### 5.5 "최신 1건" 쿼리

```sql
SELECT * FROM test_results
WHERE member_id = ? AND test_type = ?
ORDER BY tested_at DESC, id DESC
LIMIT 1
```

`id DESC` 는 같은 날 재시험 시 안전한 tiebreaker.

## 6. 인증 흐름

### 6.1 로그인 폼 (단일 입력 + 자동 판별)

```
┌───────────────────────────────────┐
│ PT 회원 페이지                       │
│ [ 소리튠 아이디 또는 휴대폰번호    ] │
│ [        시작하기        ]          │
└───────────────────────────────────┘
```

서버 자동 판별 로직:

1. `trim()` + 빈 입력 거절
2. `digitsOnly = preg_replace('/\D/', '', $input)`
3. **숫자만 8자리 이상** 으로 보이면 phone 우선 lookup → 실패 시 soritune_id fallback
4. 그 외엔 soritune_id 우선 lookup → 실패 시 phone fallback (입력에 숫자 섞여있을 수 있으니)
5. 둘 다 실패하면 `NOT_FOUND`
6. 매칭 회원이 `merged_into IS NOT NULL` 이면 primary 회원으로 자동 follow-through (재귀 1단계 — 병합은 1단 가정, 2단 이상이면 마지막 primary 까지)

phone 정규화 규칙:
- 입력 `010-1234-5678` / `010 1234 5678` / `01012345678` 모두 `01012345678` 로 비교
- DB 의 `phone` 컬럼도 같은 normalize 함수 적용 후 비교 (기존 `members.phone` 은 이미 normalize 된 값으로 import 됨 — boot 의 `feedback_excel_phone_corruption` 메모 참조)

### 6.2 API

#### `POST /api/member_auth.php?action=login`

```json
요청: { "input": "soritunehong" }
성공: { "ok": true, "member": { "id": 123, "name": "홍길동", "soritune_id": "soritunehong" } }
실패: { "ok": false, "code": "NOT_FOUND", "message": "입력하신 정보로 등록된 회원을 찾을 수 없습니다. 고객센터로 문의해주세요." }
```

성공 시 `$_SESSION['pt_user']` 채움. 응답에 `password_hash` 등 민감 필드 포함 안 함 (member 테이블엔 없지만 명시).

#### `POST /api/member_auth.php?action=logout`

세션 파기 후 `{ ok: true }`. 클라가 `/me/` 로 리다이렉트.

#### `GET /api/member_auth.php?action=me`

```json
로그인됨:   { "ok": true, "member": { "id": 123, ... } }
로그인안됨: { "ok": false, "code": "UNAUTHENTICATED" }
```

페이지 로드 시 SPA 가 호출해서 로그인/대시보드 분기.

### 6.3 매칭 실패 화면

```
⚠ 회원 정보를 찾을 수 없습니다
입력하신 아이디 / 휴대폰번호로 등록된 PT 회원이 없습니다.
📞 고객센터: (운영팀과 연결 채널/번호 확정 후 본 spec 업데이트)
[ 다시 입력 ]
```

→ 구현 전에 **고객센터 연결 수단 (카카오 채널 / 전화번호 / 이메일)** 을 사용자에게 한 번 확인 받음. 일단 본 spec 의 placeholder 는 카카오 채널 `@소리튠` 가정.

## 7. 시험 러너 (오감각 본 구현)

### 7.1 진행 흐름

- 5단계, 단계당 10문항 (마지막 단계는 8문항 — `Math.ceil(48/5)=10`, 마지막 step start=40 end=48)
- 각 문항: 체크박스 단일 토글 (예/아니오). 미체크 = 0, 체크 = 1
- 단계별 진행바 5칸 + "이전/다음" 버튼
- 마지막 단계 → "결과 보기" CTA 로 변환 → submit
- Pretendard 다크 카드, 체크 색상 `#FF5E00`, 점수바는 청각=#FF5E00 / 시각=#ffaa4d / 체각=#cc4e00

### 7.2 questions 데이터

기존 sensestest 의 48문항 그대로 복사 — `me/js/sensory.js` const 배열, `includes/tests/sensory_meta.php` PHP 배열 양쪽에 동일 데이터. 각 항목: `{ type: '청각형'|'시각형'|'체각형', text: '...' }`.

문항 분포:
- 청각형: 12문항
- 시각형: 14문항
- 체각형: 22문항

(분포 불균형이지만 비율(percent)로 정규화하므로 카테고리 결정에는 영향 없음 — 기존 마케팅 버전 그대로)

### 7.3 채점

```
percent[type] = checked[type] / total[type] * 100
key bit[type] = (percent[type] > 50) ? 1 : 0
key string    = "auditory_bit,visual_bit,kinesthetic_bit"
```

- **임계 50% 초과** (>) — 정확히 50% 는 0 (기존 sensestest 동작 그대로)
- key 8가지 → categories 맵에서 title/subtitle/content/learning/courses 조회

### 7.4 categories 데이터

기존 sensestest 의 8개 카테고리 (`0,0,0` ~ `1,1,1`) title·subtitle·content·learning·courses 그대로 복사. `courses` 는 회원 결과 화면에 미노출이지만 데이터는 보존 (DISC 도 동일 구조 유지 + 향후 활용).

### 7.5 채점 시점 (이중 — 위조 방지)

- **클라이언트 1차 계산** → 결과 화면 즉시 그래프 + 카테고리 표시 (UX)
- **서버 2차 검증** → submit 시 서버에서 `answers` 로 다시 계산해 `scores`/`percents`/`key`/`title` 최종 결정. 클라가 보낸 `key`/`title` 등은 **무시**. 회원이 임의로 결과 위조해 코치 매칭 자료가 잘못되는 것 방지.

### 7.6 PHP/JS 데이터 동기화

questions·scoring 이 양쪽에 중복 — 단일 source of truth 보장 방법:

- `tests/sensory_php_js_parity_test.php` 에서 JS 파일을 텍스트로 파싱해 questions 추출 후 PHP 배열과 비교 (문항 수, 각 항목 type/text)
- 한쪽만 수정되면 즉시 fail → CI 가드

JS 채점 로직 자체는 검증 안 함 (서버가 단일 source of truth, 클라는 즉시 표시용 보조 — 불일치하더라도 최종 저장 값은 서버 계산).

### 7.7 Submit API: `POST /api/member_tests.php?action=submit`

```json
요청: { "test_type": "sensory", "answers": [0,1,1,0,...] }    // 정확히 48개
응답: { "ok": true, "result_id": 456, "result_data": { ...섹션 5.2... } }
```

서버:
1. `requireMember()` — 미인증 → 401
2. `test_type` 화이트리스트 (`sensory`/`disc`)
3. `answers` 길이 검증 (sensory=48), 각 값 ∈ {0,1}
4. `Sensory::score($answers)` 가 비율·key·title 계산
5. `result_data` JSON 빌드 → `INSERT INTO test_results (member_id, test_type, result_data, tested_at, created_at)` (`tested_at` = 오늘 KST, `memo` NULL)
6. 응답 — 클라는 `result_data` 로 결과 화면 렌더

### 7.8 진행 중 이탈

미저장 stateless. 다시 들어오면 처음부터. 48문항 5분 분량이라 자동 저장 불필요 (YAGNI).

### 7.9 메타 데이터 변경 정책

운영 중 questions/categories 가 바뀌면 과거 결과(`result_data`) 의 의미가 달라질 수 있음:
- `result_data.version` 으로 분리 관리 (현재 `1`)
- `includes/tests/sensory_meta.php` 주석에 변경 이력 기록
- 결과 표시는 항상 저장 당시의 `title`/`subtitle` **스냅샷** 사용 (메타 변경에도 과거 결과 안전)

## 8. 결과 화면

### 8.1 회원 — 시험 직후 (full)

```
나의 감각 유형
━━━━━━━━━━━━━━━━━━━━━━━
청각 + 시각 혼합 학습자
소리와 이미지를 결합한 학습법이 효과적

청각  ████████████░░░░░  64%
시각  █████████████████  78%
체각  ███░░░░░░░░░░░░░░  18%

━━━━━━━━━━━━━━━━━━━━━━━
특징
  • 듣기 + 시각적 자료 활용이 중요
  • 영상 & 오디오 학습법이 효과적

추천 학습법
  • 영상 + 오디오 활용 학습
  • 소리튜닝 후 패턴 정리하여 분석
  • 쉐도잉 & 패턴 학습법 활용

[ 다시 하기 ]   [ 대시보드 ]
```

- "추천 강의" / "공유 버튼" 제거
- 상단 헤더는 PT 다크 카드 + 오렌지 액센트 1줄
- 본문은 `cat.content` / `cat.learning` HTML 그대로 렌더 (XSS 안전 — categories 는 자체 정의 데이터)

### 8.2 회원 — 대시보드 (로그인 후 메인)

```
안녕하세요, 홍길동님         [로그아웃]

┌───────────────┐ ┌───────────────┐
│ 오감각 테스트  │ │ DISC 진단      │
│ ─────────────│ │ ─────────────│
│ 최근:        │ │ 미응시         │
│ 2026-05-08   │ │                │
│ 청각+시각    │ │                │
│ 혼합형        │ │                │
│              │ │                │
│ [내 결과 보기]│ │ [시험 시작하기]│
│ [다시 보기]   │ │                │
└───────────────┘ └───────────────┘
```

시험별 카드 1장. 응시 여부에 따라 CTA 분기. "내 결과 보기" 는 저장된 `result_data` 로 8.1 화면 재렌더 (시험 미진행). DISC 카드는 본 spec scope 에서는 "미응시 + 비활성화" 상태로 노출 (DISC 명세 도착 후 활성화).

### 8.3 어드민 / 코치 — `member-chart` 의 "테스트결과" 탭 (개선)

기존 dump 형식 (`formatTestData`) 을 신규 schema 인식 카드형으로 교체:

```
DISC 진단
┌──────────────────────────────────────┐
│ 2026-05-08      [삭제]                │
│ 청각+시각 혼합형                      │
│ 청각 64%  시각 78%  체각 18%          │
│ memo: ...                              │
└──────────────────────────────────────┘
[+ 결과 추가]   ← 어드민만
```

- 같은 회원 같은 시험 결과 여러 row → `tested_at DESC, id DESC` 모두 노출 (이력 보존)
- `formatSensoryResult(data)` / `formatDiscResult(data)` 분기:
  - 신규 schema (`{version: 1, percents: {...}, title, ...}`) → 카드형 렌더
  - Legacy free-form JSON (어드민이 손으로 입력했던 기존 데이터, 예: `{"D":35,"I":25}`) → 기존 dump 식 fallback
- "+ 결과 추가" / "삭제" 는 어드민만 노출 — 기존 그대로 동작 (api/tests.php create/delete 변경 없음)
- 코치는 "삭제" 버튼 비노출

### 8.4 자동 매칭 연동

본 spec scope 밖이지만, `result_data` 의 `key`/`percents` 가 향후 매칭 입력으로 기계 처리 가능하도록 일관된 schema 유지. 확인: 현재 `api/matching.php` 와 `includes/matching_engine.php` 는 `test_results` 를 참조하지 않음 (grep 결과 0건) — 본 spec 변경이 매칭 시스템에 영향 없음.

## 9. 보안 / 가드 / 감사

- `requireMember()` 가드: `/api/member_tests.php?action=submit|latest|history` 모두 통과 필요
- `member_tests.php?action=latest|history` 는 자기 자신 (`member_id = $_SESSION['pt_user']['id']`) 만 조회 가능 — URL `member_id` 파라미터 무시
- 어드민/코치의 회원별 이력 조회는 기존 `api/tests.php?action=list&member_id=X` 그대로 (이미 권한 가드 있음 — 코치는 활성 order 보유 회원만)
- session fixation 방지: login 성공 시 `session_regenerate_id(true)` 호출 (현 admin/coach 코드는 regenerate 안 하지만, member 로그인은 이번에 새로 짜므로 좋은 관행을 처음부터 적용. 비용 0)
- CSRF: PT 는 기존에 SameSite=Lax 쿠키만 의존 (admin/coach 와 동일 정책 — 별도 토큰 미사용). member 도 같은 정책
- `change_logs` 기록 안 함 (회원 셀프 행위는 감사 부담 大 — `test_results.id` + `created_at` 자체가 감사 흔적). 어드민의 수동 create/delete 도 기존대로 미기록 (현 구조 유지)

## 10. 테스트

### 10.1 PHP 채점 단위 — `tests/sensory_scoring_test.php`

- 모든 0 → key=`0,0,0`, title="균형형"
- 모든 1 → key=`1,1,1`, title="완전한 멀티 감각형 학습자"
- 청각만 100% → key=`1,0,0`
- 50% 경계 (정확히 50% 는 0)
- 잘못된 입력 (length≠48, {0,1} 외 값) → ValidationError

### 10.2 PHP↔JS 데이터 동기화 — `tests/sensory_php_js_parity_test.php`

- JS 파일 텍스트 파싱 → questions 추출
- PHP 배열과 길이·각 항목 type/text 일치 검증
- 한쪽만 수정 시 즉시 fail

### 10.3 인증·매칭 — `tests/member_auth_test.php`

- soritune_id 정확 매칭 → 성공
- phone 정확 매칭 (`010-1234-5678` → `01012345678` 정규화)
- 매칭 없음 → `NOT_FOUND` + 세션 미생성
- 다중 매칭 (같은 phone 으로 회원 2명 시드) → `created_at DESC LIMIT 1`
- 병합된 회원 (`merged_into IS NOT NULL`) → primary 자동 follow-through
- 입력 길이 0 / 공백만 → ValidationError

### 10.4 Submit — `tests/member_tests_submit_test.php`

- 비로그인 → 401
- coach 세션 → 401 (member only)
- member + 정상 → row 생성, `member_id` = 세션 id, `version=1`, `key`/`title` 검증
- `answers.length=47` → 400
- `answers` 에 2 또는 -1 → 400
- `test_type='unknown'` → 400
- 같은 회원 같은 날 두 번 → 두 row, `id DESC` tiebreaker 동작
- 클라가 위조한 `key`/`title` 보내도 서버 재계산이 우선

### 10.5 회원 본인 조회 — `tests/member_tests_self_test.php`

- `member_tests.php?action=latest&test_type=sensory` → 본인 최신 row
- `action=latest` 결과 없음 → `{ok:true, result: null}`
- 다른 member_id 로 조회 시도 → 무시되고 본인 결과만 반환

### 10.6 어드민/코치 회원차트 렌더 — `tests/admin_member_chart_render_test.php`

- 신규 schema row → 카드형 렌더 (title/percents 표시)
- Legacy free-form JSON row → 기존 dump 식 fallback
- 같은 회원 sensory 3건 → 3개 카드 모두 노출, `tested_at DESC, id DESC` 정렬

### 10.7 통합 (수동 browser smoke)

- pt-dev `/me/` 에서 시드 회원으로 로그인 → 대시보드 → 시험 → 결과 → 다시하기 → 두 번째 row 생성
- 어드민으로 그 회원 chart > 테스트결과 탭에서 두 row 모두 신규 카드 형식으로 렌더링
- 코치 (해당 회원 활성 order 보유) 가 같은 화면에서 같은 결과
- 매칭 안 되는 입력 → 고객센터 안내
- 모바일 (또는 devtools 모바일) 1회 — Spotify 다크 + 5단계 + 재시험 OK

### 10.8 회귀 가드

- 기존 `api/tests.php` admin `create`/`delete` 그대로 동작 (어드민 수동 추가 보존)
- legacy free-form JSON row 1건 시드 후 차트에서 fallback 렌더 확인

### 10.9 PROD 배포 후 invariant

- `SELECT COUNT(*) FROM test_results WHERE test_type='sensory' AND JSON_EXTRACT(result_data,'$.version')=1` 가 신규 셀프 응시 건수와 일치
- `result_data->>'$.percents.auditory'` ∈ [0, 100]
- `result_data->>'$.key'` ∈ {`0,0,0`,`0,0,1`,...,`1,1,1`}

## 11. 알려진 한계

### 11.1 회원 신원 검증 없음 (구글폼 동등 신뢰 모델)

누군가 다른 회원의 soritune_id 또는 phone 을 알면 그 회원 명의로 시험을 봐서 데이터를 오염시킬 수 있음. 기존 구글폼과 동일한 신뢰 수준이며 **사용자가 명시 결정**. 코치/어드민이 결과 보다가 이상하면 어드민이 해당 row 삭제 가능 (기존 delete 액션). 향후 OTP 로 강화 가능 (별도 spec).

### 11.2 운영 중 questions / categories 변경 시 과거 결과 의미 변동

`result_data.version` 분리 + 저장 당시 `title`/`subtitle` 스냅샷으로 부분 완화. 채점 비교 시점이 다르면 같은 회원의 시간순 비교는 주의 (예: v1 → v2 비교는 같은 카테고리 정의 보장 안 됨).

### 11.3 phone 정규화 규칙 일관성

import / login 모두 같은 `normalizePhone()` 함수를 거쳐야 함. 본 spec 은 `preg_replace('/\D/', '', $input)` 단순 규칙 채택. 국제번호 prefix `+` 는 무시 (boot `feedback_excel_phone_corruption` 참조 — 소리튠 회원은 한국 폰 위주). 추후 국제번호 회원 대량 유입 시 보강 필요.

## 12. 마이그레이션 / 배포 순서

1. **DEV (dev-pt.soritune.com, dev 브랜치)**
   - 코드 머지 → `tests/` 전부 PASS 확인
   - DEV DB 시드 회원으로 수동 smoke
2. **사용자 확인** (DEV 검수)
3. **PROD (pt.soritune.com, main 브랜치)** — 사용자 명시 요청 시
   - 마이그레이션 없음 (스키마 변경 없음)
   - main 머지 → prod pull → smoke (시드 회원 1건)
   - 운영팀이 회원에게 `pt.soritune.com/me/` URL 안내 (기존 구글폼 대체)

## 13. 향후 작업

- DISC 본 구현 (questions·categories·UI — 별도 spec)
- 회원 OTP 인증 (강한 신원 검증 — 별도 spec)
- 자동 매칭이 `test_results` 를 입력으로 활용 (별도 spec)
- 시험 결과 PDF / 이메일 발송 (필요 시)
