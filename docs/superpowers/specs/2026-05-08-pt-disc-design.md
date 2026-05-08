# PT 회원 페이지 — DISC 검사 (Design)

- 작성일: 2026-05-08
- 대상: pt.soritune.com (DEV: dev-pt.soritune.com)
- 코드 수정 범위: pt-dev (DEV_PT, dev 브랜치) — 운영 반영은 사용자 명시 요청 시
- 본 spec scope: DISC 검사 회원 셀프 응시 흐름. **선행 spec (오감각 검사) 의 인프라 재사용**

**선행 인프라 (이미 dev 에 머지·배포됨):**
- `/me/` 회원 포털 SPA + 로그인/대시보드/시험 러너 프레임워크
- `test_results` 테이블 (`test_type ENUM('disc','sensory')`, `result_data JSON`)
- 어드민·코치 차트의 `formatTestResult(testType, data)` 카드 렌더 + legacy fallback
- 회원 본인 결과 조회 API `/api/member_tests.php?action=latest`
- PHP↔JS 데이터 동기화 가드 패턴

본 spec 은 위 인프라 위에 DISC 만 plug-in.

---

## 1. 배경 / 목적

운영팀은 회원의 성향(DISC) 데이터를 코치 매칭·코칭 자료로 활용하기 위해 현재 구글 폼/시트로 응답을 받고 있음. 오감각 검사와 동일하게 PT 자체 페이지로 대체:

- 회원이 `/me/` 대시보드에서 DISC 카드 클릭 → 직접 응시
- 결과가 즉시 본인 차트에 저장 → 코치/어드민 조회
- 회원 본인은 자기 결과를 다시 볼 수 있고, 재시험 가능
- 향후 자동 매칭 입력 데이터로 활용 (별도 spec)

## 2. 비-목표 (YAGNI)

- 시험 진행 중 자동 저장 / 이어 풀기 (10문항 3분 분량)
- 회원 본인이 자기 이력 전체 타임라인 보기 (회원은 "최신 결과" 만)
- 시험 결과 PDF / 이메일 / SNS 공유
- 자동 매칭 입력 통합 (별도 spec)
- 부 유형 조합(DI / IS 등 12+ 변형) — primary 1개만
- 회원 신원 강한 검증 — 구글폼 동등 신뢰 모델 (sensory 와 동일)

## 3. 사용자 결정 사항 (브레인스토밍 결과)

- **응답 입력 UI** → 순차 클릭 (4점 → 3점 → 2점 클릭, 1점은 자동, "다음" 버튼은 명시 클릭). 정정은 단어별 X 버튼.
- **결과 화면 범위** → 주 유형 1개 + 4 점수 바 (가장 높은 유형이 맨 위) + 키워드·특징·학습 스타일
- **유형 설명 카피** → Claude 초안 (사용자 spec 검토 단계에서 수정 가능)
- **동점 처리** → D > I > S > C 순서 (결정적, 드물게 발생)

## 4. 아키텍처 개요

### 4.1 라우팅 / UI 진입

기존 `/me/?test=disc` 가 dashboard 카드에 연결되어 있음. 카드 활성화만 하면 됨 — DISC 카드의 "준비 중" → "시험 시작하기 / 내 결과 보기 / 다시 보기" 로 변경.

### 4.2 시험 러너 모듈 분리

오감각의 `MeTestRunner` 와 응답 모델이 다름 (boolean vs 1~4 순위) → **별도 모듈** `me/js/disc-runner.js`. 두 러너는 `MeApp.go('test', {testType})` 에서 testType 기준으로 분기.

미래 3번째 시험 추가 시 공통 추상화 고려 (현재는 YAGNI — 두 러너 자체 모듈로 충분).

### 4.3 채점

오감각의 `Sensory::score()` 와 평행하게 `Disc::score(array $answers): array` PHP 클래스 (`includes/tests/disc_meta.php`). `api/member_tests.php?action=submit` 의 `test_type` 화이트리스트에 `'disc'` 추가.

### 4.4 파일 레이아웃

**신규**
```
public_html/
├─ me/js/
│  ├─ disc-runner.js                NEW — 순위 입력 러너
│  └─ disc.js                        NEW — questions + 결과 카테고리 + clientScore
├─ includes/tests/
│  └─ disc_meta.php                  NEW — 10문항 + 4유형 + Disc::score()
tests/
├─ disc_scoring_test.php             NEW
├─ disc_php_js_parity_test.php       NEW
└─ member_tests_disc_submit_test.php NEW
```

**수정**
- `public_html/me/index.php` — `<script src="/me/js/disc-runner.js">` `<script src="/me/js/disc.js">` 추가
- `public_html/me/js/app.js` — `render()` 의 `'test'` 분기에서 testType → `MeTestRunner` 또는 `MeDiscRunner` 선택. `'result'` 분기도 `MeResultView` 또는 `MeDiscResultView` 선택.
- `public_html/me/js/dashboard.js` — DISC 카드 활성화 (기존 sensory 카드 로직 복제, `loadCards()` 에서 DISC latest 도 fetch)
- `public_html/me/js/result-view.js` — DISC 결과를 `MeDiscResultView` 가 별도로 렌더링 (sensory 와 분리). 또는 기존 파일에 `MeDiscResultView` 추가.
- `public_html/api/member_tests.php` — submit 의 `test_type` 화이트리스트 `['sensory']` → `['sensory', 'disc']`. `Sensory::score()` 호출도 testType 분기로 `Disc::score()` 호출.
- `public_html/admin/js/pages/member-chart.js` & `coach/js/pages/member-chart.js` — `formatTestResult` 의 `isNewDisc` 분기 추가

## 5. 데이터 모델

### 5.1 `test_results` 재사용 (스키마 변경 없음)

기존 테이블 그대로. 마이그 없음.

### 5.2 `result_data` JSON v1 — disc

```json
{
  "version": 1,
  "answers": [[4,1,3,2], [3,4,2,1], [4,2,3,1], ...],
  "scores": { "D": 32, "I": 24, "S": 22, "C": 22 },
  "ranks":  [
    {"type":"D","score":32,"rank":1},
    {"type":"I","score":24,"rank":2},
    {"type":"S","score":22,"rank":3},
    {"type":"C","score":22,"rank":3}
  ],
  "primary": "D",
  "title": "주도형",
  "subtitle": "도전적이고 결과 지향",
  "submitted_at": "2026-05-08T16:02:11+09:00"
}
```

- `answers` — 10개 inner array. 각 inner = `[D점수, I점수, S점수, C점수]` 순서 고정 (각 문항의 4단어가 D/I/S/C 순으로 매핑됨)
- `scores` — 유형별 합계 (총합 항상 100, 각 유형 10~40 범위)
- `ranks` — 점수 내림차순 정렬 + 동점은 같은 rank 번호 (예: 22점 두 개면 둘 다 rank 3)
- `primary` — 최고 점수 유형 한 글자. 동점 시 D > I > S > C 결정적 순서
- `title`/`subtitle` — 저장 시점 카테고리 스냅샷
- `version` — 메타 개정 시 증가 (현재 1)

### 5.3 검증 규칙 (`Disc::score()` throws `InvalidArgumentException`)

- `answers` 길이 = 10
- 각 inner 길이 = 4
- 각 inner 의 4개 값이 `{1,2,3,4}` 의 순열 (모두 다르고 sum=10)

### 5.4 동점 처리

`primary` 결정: 점수 같으면 D > I > S > C 순서. `ranks` 배열에서는 같은 rank 번호 부여.

예) 점수 D=32, I=24, S=22, C=22 → primary=D, ranks=[{D,32,1},{I,24,2},{S,22,3},{C,22,3}]

### 5.5 카테고리 데이터 (4개)

`disc_meta.php` `Disc::categories()` 반환:

```php
[
  'D' => [
    'title'    => '주도형',
    'subtitle' => '도전적이고 결과 지향',
    'keywords' => '도전 · 결과 · 빠른 결정',
    'content'  => '<ul><li>...</li></ul>',  // 특징 4개
    'learning' => '<ul><li>...</li></ul>',  // 학습 스타일·코치 매칭 힌트 4개
  ],
  'I' => [...],
  'S' => [...],
  'C' => [...],
]
```

(섹션 9 에 4유형 카피 전문)

## 6. UI — 시험 진행 화면

### 6.1 진행 흐름

10문항 = 10단계. 한 화면에 1문항. 진행바 10칸.

각 문항: 4단어 + 안내문 + 이전/다음 버튼.

### 6.2 클릭 시퀀스 (한 문항 안)

1. 안내문: "가장 잘 어울리는 단어는?" → 사용자 클릭 → 그 단어 = 4점, 비활성화
2. 안내문 자동 변경: "두 번째로 잘 어울리는 단어는?" → 클릭 → 3점
3. 안내문 자동 변경: "세 번째로 잘 어울리는 단어는?" → 클릭 → 2점
4. 마지막 남은 단어 = 1점 자동 부여 → 안내문: "✓ 모두 매겼습니다" → "다음" 버튼 활성화

### 6.3 정정 (X 버튼)

순위 매겨진 단어 옆에 작은 X 버튼. 클릭하면 그 단어만 미선택 복귀, 안내문이 적절한 차순위 안내로 되돌아감 (남은 슬롯 기준).

### 6.4 "이전" 동작

이전 문항으로 이동. 그 문항의 순위 그대로 보존 — 재방문해서 X 로 정정 가능.

### 6.5 "다음" / "결과 보기" 활성화 조건

해당 문항의 4단어 모두 순위 매겨진 상태. 그렇지 않으면 비활성화 (회색).

### 6.6 진행 상태 보존

세션 메모리만 (오감각과 동일). 새로고침 / 탭 닫기 → 처음부터. 10문항 3분 분량이라 자동 저장 불필요.

### 6.7 모바일 검수 포인트

- 4단어 카드는 한 행에 단어 1개씩 (세로 스택)
- X 버튼 터치 영역 44px 이상
- 단어 카드 전체가 클릭 영역

## 7. 채점 (서버 단일 source)

- **클라이언트 1차** → 결과 화면 즉시 표시 (UX)
- **서버 2차** → submit 시 `Disc::score(answers)` 재채점, 클라가 보낸 `primary`/`title`/`scores` 무시 (위조 방지)

`Disc::score()`:
1. 길이·값 검증 (위 §5.3)
2. `scores`: 각 inner array 의 D/I/S/C 위치 점수 합산
3. `ranks`: 점수 내림차순 정렬, 동점은 같은 rank
4. `primary`: 점수 최대 유형 (동점 시 D>I>S>C)
5. `title`/`subtitle`/`keywords`: `Disc::categories()[primary]` 에서 스냅샷

## 8. 결과 화면 (회원용)

```
← 메인으로

╔═════════════════════════════════════════╗
║   나의 DISC 유형                          ║   (오렌지 그라디언트)
║   D 주도형                                ║
║   도전적이고 결과 지향                    ║
╚═════════════════════════════════════════╝

[ 도전 ] [ 결과 ] [ 빠른 결정 ]              ← 키워드 chips

  D  ████████████████████  32   1순위
  I  █████████████          24   2순위
  S  ████████████           22   3순위
  C  ████████████           22   3순위(공동)

──────────────────────────────────
특징
  • 명확한 목표를 향해 적극적으로 추진...

──────────────────────────────────
학습 스타일
  • 명확한 목표와 기한이 있는 학습 플랜...

──────────────────────────────────
[ 다시 하기 ]    [ 메인으로 ]
```

- 바는 4개 모두 같은 accent (#FF5E00) — 길이로 차이 표현
- `ranks` 기준 점수 내림차순 정렬 (1순위가 맨 위)
- 동점은 같은 순위 라벨 ("3순위(공동)")
- 추천 강의 / 공유 버튼 없음 (오감각과 동일 spec)

## 9. 4유형 카피 (초안 — 사용자 검토 후 확정)

### D — 주도형
- 부제: 도전적이고 결과 지향
- 키워드: 도전 · 결과 · 빠른 결정
- 특징
  - 명확한 목표를 향해 적극적으로 추진하는 스타일
  - 빠른 결정과 결과 확인을 선호 — 답답한 진행은 지루함
  - 도전적인 환경과 새로운 시도에서 활기를 얻음
  - 의견을 직설적으로 표현하고 자기 주장이 분명함
- 학습 스타일
  - 명확한 목표와 기한이 있는 학습 플랜에 강함
  - 빠른 피드백·결과 확인을 통해 진도 체감
  - 코치와의 관계는 효율 중심 — 단도직입적이고 명확한 코칭 선호
  - 답답한 반복보다 새로운 도전 과제로 자극 필요

### I — 사교형
- 부제: 설득과 관계 중심
- 키워드: 설득 · 관계 · 낙천
- 특징
  - 말솜씨와 에너지로 사람들을 끌어당기는 스타일
  - 관계 속에서 학습 효과가 극대화 — 혼자보다 함께 학습이 잘 맞음
  - 칭찬·인정에 강하게 반응하며 동기부여 받음
  - 새로운 경험과 다양한 시도를 즐기고 즉흥적
- 학습 스타일
  - 대화·상호작용이 풍부한 코칭에서 빛남
  - 칭찬과 격려를 적극 받아 자신감 쌓는 학습이 효과적
  - 코치와의 관계는 친근·열정·재미 — 분위기가 학습 효과를 좌우
  - 너무 단조롭거나 정적인 학습은 금세 흥미를 잃을 수 있음

### S — 안정형
- 부제: 협력과 인내
- 키워드: 협력 · 인내 · 일관성
- 특징
  - 차분하고 신뢰감 있는 태도로 꾸준히 진행하는 스타일
  - 갑작스러운 변화보다 일관성 있는 흐름을 선호
  - 깊이 있게 듣고 상대를 배려하는 능력이 강함
  - 천천히, 그러나 멀리 가는 인내심
- 학습 스타일
  - 단계적·꾸준한 진도 관리가 가장 잘 맞음
  - 안정감 있는 환경과 예측 가능한 코칭 일정에서 효과 큼
  - 코치와의 관계는 따뜻함·인내·신뢰 — 충분히 익숙해진 후에 자신감 발휘
  - 갑작스러운 방식 변화나 급한 진도는 저항감을 만들 수 있음

### C — 신중형
- 부제: 분석과 정확성
- 키워드: 분석 · 정확성 · 계획
- 특징
  - 데이터·근거에 기반해 신중하게 판단하는 스타일
  - 완벽함을 추구하고 세부사항을 점검하는 능력 강함
  - 충분한 자료와 시간을 가진 후 결정 내림
  - 객관성을 유지하며 감정에 휘둘리지 않음
- 학습 스타일
  - 체계적이고 논리적인 자료 기반 학습이 효과적
  - 정확한 피드백과 근거 있는 설명에 동기부여 받음
  - 코치와의 관계는 준비된 자료·논리 — 충분한 정보 제공이 신뢰의 핵심
  - 즉흥적·감정적 코칭은 거리감을 만들 수 있음

## 10. 어드민 · 코치 차트 카드

```
DISC 진단
┌──────────────────────────────────────┐
│ 2026-05-08      [삭제]                │
│ 주도형 (D)                            │
│ D 32  ·  I 24  ·  S 22  ·  C 22       │
│ memo: ...                              │
└──────────────────────────────────────┘
```

`formatTestResult` 의 분기:
- `isNewSensory = testType==='sensory' && version===1 && percents` (기존)
- `isNewDisc    = testType==='disc'    && version===1 && scores`    (신규)
- 둘 다 아니면 legacy free-form JSON dump

DISC 카드는 점수 raw 값 (0~40 범위) 으로 표시. percent 변환 안 함.

## 11. 10문항 데이터 (마스터)

번호 / D / I / S / C 순서. JS 와 PHP 양쪽에 동일하게 들어가야 함 (parity 가드 검증).

| # | D | I | S | C |
|---|---|---|---|---|
| 1 | 자기 주장이 강한 | 즐거운 | 배려하는 | 신중한 |
| 2 | 단호한 | 열정적인 | 너그러운 | 객관적인 |
| 3 | 자신감 있는 | 낙천적인 | 인내심 있는 | 분석적인 |
| 4 | 빠르게 결정하는 | 말솜씨가 있는 | 잘 들어주는 | 완벽추구적인 |
| 5 | 대담한 | 유연성이 있는 | 양보하는 | 계획적인 |
| 6 | 적극적인 | 호감을 주는 | 협조하는 | 자제하는 |
| 7 | 경쟁적인 | 친화력이 좋은 | 일관성 있는 | 논리적인 |
| 8 | 감정에 둔감한 | 말이 많은 | 변화를 꺼리는 | 위험을 회피하는 |
| 9 | 독선적인 | 충동적인 | 우유부단한 | 냉정한 |
| 10 | 남의 말을 잘 듣지 못하는 | 사후관리가 약한 | 감정에 치우치는 | 비판적인 |

데이터 구조 (PHP):
```php
public static function questions(): array {
  return [
    ['D'=>'자기 주장이 강한','I'=>'즐거운','S'=>'배려하는','C'=>'신중한'],
    ['D'=>'단호한','I'=>'열정적인','S'=>'너그러운','C'=>'객관적인'],
    // ...
  ];
}
```

JS 측 동일 구조. parity 가드는 questions 배열을 텍스트로 파싱해 PHP 와 비교.

## 12. 보안 / 가드 / 감사

- `requireMember()` 가드: submit / latest 모두 (기존 그대로)
- `member_tests.php?action=latest&test_type=disc` 는 자기 자신만 (URL `member_id` 파라미터 무시)
- 어드민/코치는 기존 `api/tests.php?action=list` 그대로 (이미 권한 가드)
- 클라가 위조한 `primary`/`title`/`scores` 는 서버 재계산이 우선
- session/CSRF 정책 sensory 와 동일

## 13. 테스트

### 13.1 PHP 채점 단위 — `tests/disc_scoring_test.php`

- `Disc::questions()` 길이 10, 각 항목 D/I/S/C 키 모두 존재
- `Disc::categories()` D/I/S/C 4개 키
- `Disc::score()` 모든 D 가 4점 (10문항 × 4점) → scores=`{D:40,I:?,S:?,C:?}`, primary=`D`, title=`주도형`
- 모든 I 가 4점 → primary=`I`
- D=I 동점 (각 35) → primary=`D` (D>I 우선)
- 길이 ≠ 10 → throws
- inner 길이 ≠ 4 → throws
- inner 가 `{1,2,3,4}` 순열 아님 (예: `[1,1,2,2]`) → throws
- inner 값에 0 또는 5 포함 → throws

### 13.2 PHP↔JS parity — `tests/disc_php_js_parity_test.php`

- JS 의 `DiscData.questions` 텍스트 파싱 → PHP `Disc::questions()` 와 비교
- 길이 10, 각 항목 D/I/S/C 텍스트 일치
- 한쪽만 수정되면 즉시 fail

### 13.3 Submit — `tests/member_tests_disc_submit_test.php`

- 정상 disc submit → row 생성, `test_type='disc'`, `version=1`, primary 검증
- 위조 `primary='D'` 보내도 서버가 정확한 primary 로 덮어씀
- inner 가 순열 아닌 경우 → 400
- `test_type='disc'` 가 화이트리스트에 추가됐는지 확인

### 13.4 Latest — `tests/member_tests_self_test.php` 추가

(기존 sensory latest 테스트 파일에 disc latest 케이스 추가)

- disc 미응시 → result=null
- disc 응시 후 latest → 본인 disc row 반환
- sensory + disc 동시 응시 후 각각 latest → 분리 조회 OK

### 13.5 어드민 차트 카드 렌더 (수동 smoke)

회원 차트 > 테스트결과 탭에 DISC 카드 신규 schema 로 표시

### 13.6 통합 (수동 browser smoke)

- `/me/` 회원 로그인 → 대시보드 DISC 카드 활성 → 시험 시작
- 10문항 순차 클릭 → 각 문항 4 클릭 (실제로 3 클릭 + 1 자동) → 결과
- 결과 화면: 주 유형 + 4 점수 바 (정렬·동점 라벨) + 특징·학습 스타일
- "다시 하기" → 두 번째 응시 → 두 row 생성
- 어드민으로 그 회원 차트에서 두 row 카드형 표시 확인
- 모바일 1회 검수

### 13.7 회귀 가드

- 기존 sensory 시험 / 결과 / 어드민 차트 정상 동작 (submit 의 화이트리스트 확장이 sensory 영향 없는지)
- 405/405 PHP 테스트 그대로 통과 + DISC 신규 추가

### 13.8 PROD invariant

- `SELECT COUNT(*) FROM test_results WHERE test_type='disc' AND JSON_EXTRACT(result_data,'$.version')=1` ≥ 1 (응시 발생 후)
- `result_data->>'$.scores.D'` ∈ [10, 40]
- 4 score 합 = 100 (`scores.D + I + S + C`)
- `result_data->>'$.primary'` ∈ {D,I,S,C}

## 14. 알려진 한계

### 14.1 회원 신원 검증 없음 (오감각과 동일)

오감각 spec §11.1 동일. 변동 없음.

### 14.2 questions / categories 변경 시 과거 결과 의미 변동

`version` 분리 + 저장 당시 `title`/`subtitle` 스냅샷으로 부분 완화. v1 → v2 비교는 같은 카테고리 정의 보장 안 됨.

### 14.3 동점 정책

D > I > S > C 결정적 순서로 primary 픽. 코치가 "이 회원은 진짜 D 인가 I 인가" 판단할 때는 ranks 배열의 점수 차이를 직접 봐야 함 (예: 32 vs 31 → 거의 동등).

## 15. 마이그레이션 / 배포 순서

1. **DEV (dev-pt.soritune.com, dev 브랜치)**
   - 코드 머지 → `tests/` 전부 PASS
   - DEV DB 시드 회원으로 수동 smoke
2. **사용자 확인** (DEV 검수)
3. **PROD (pt.soritune.com, main 브랜치)** — 사용자 명시 요청 시
   - 마이그 없음 (스키마 변경 없음)
   - main 머지 → prod pull → smoke
   - 운영팀이 회원 안내 (이미 회원에게 `/me/` URL 공유했다면 추가 공지 불필요)

## 16. 향후 작업

- DISC 부 유형 조합 (DI / IS / SC 등) — 코치 매칭 정밀도 향상 시
- 자동 매칭이 `test_results` (sensory + disc) 를 입력으로 활용 (별도 spec)
- 회원 OTP 인증 (강한 신원 검증)
