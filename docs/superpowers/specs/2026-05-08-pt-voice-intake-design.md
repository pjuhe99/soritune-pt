# PT 회원 페이지 — 음성 케어 매칭 사전 질문 (Design)

- 작성일: 2026-05-08
- 대상: pt.soritune.com (DEV: dev-pt.soritune.com)
- 코드 수정 범위: pt-dev (DEV_PT, dev 브랜치) — 운영 반영은 사용자 명시 요청 시
- 본 spec scope: 11문항 사전 설문 회원 셀프 응답 흐름. **선행 spec (오감각·DISC) 의 인프라 재사용**

**선행 인프라 (이미 dev 에 머지·배포됨)**
- `/me/` 회원 포털 SPA + 로그인/대시보드/시험 러너 프레임워크
- `test_results` 테이블 (`test_type ENUM('disc','sensory')`, `result_data JSON`)
- 어드민·코치 차트의 `formatTestResult(testType, data)` 카드 렌더 + legacy fallback
- 회원 본인 결과 조회 API `/api/member_tests.php?action=latest`
- PHP↔JS 데이터 동기화 가드 패턴

본 spec 은 위 인프라 위에 음성 설문 plug-in.

---

## 1. 배경 / 목적

운영팀은 회원의 기본 프로필(성별·연령·거주·시간) 과 학습 동기·코치 스타일 선호도 데이터를 코치 매칭 자료로 활용하기 위해 현재 구글 폼으로 받음. PT 자체 페이지로 대체:

- 회원이 `/me/` 대시보드에서 음성 설문 카드 클릭 → 직접 응답
- 응답이 본인 차트에 저장 → 코치/어드민 매칭 시 11문항 모두 펼쳐 참조
- 회원 본인은 자기 응답을 다시 볼 수 있고, 재응답으로 갱신 가능
- 향후 자동 매칭 시스템 입력으로 활용 (별도 spec)

오감각·DISC 와 함께 대시보드의 3번째 카드로 자리잡음.

## 2. 비-목표 (YAGNI)

- 시험 진행 중 자동 저장 / 이어 풀기 (11문항 5분 분량)
- 회원 본인 이력 전체 타임라인 (회원은 "최신 응답" 만)
- PDF / 이메일 / SNS 공유
- 자동 매칭 통합 (별도 spec)
- 채점 / 유형 분류 (설문은 채점 대상 아님)
- 회원 신원 강한 검증 — 구글폼 동등 신뢰 모델 (sensory/DISC 와 동일)

## 3. 사용자 결정 사항 (브레인스토밍 결과)

- **"기타" 옵션** → 자유 텍스트 입력 필수 (`other` 필드, 200자 제한)
- **노출 범위** → 모든 회원 (sensory/DISC 와 동일)
- **저장 모델** → `test_results` 테이블 ENUM 확장 (`'voice_intake'` 추가)
- **제출 후 화면** → 감사 안내 + "메인으로" 버튼
- **진행 형식** → 2섹션 (Q1~Q6 기본정보 / Q7~Q11 훈련방향)
- **재응답 정책** → 매 회 새 row, 회원은 최신만, 어드민·코치 이력 전체
- **어드민 차트 표시** → 11행 펼친 카드형 (Q번호 · 짧은 라벨 · 답변)
- **회원 결과 재조회** → 11문항 펼친 화면 (원본 긴 질문문)
- **Q10 "해당없음"** → 다른 항목과 상호 배타 (체크 시 다른 항목 자동 해제, 그 반대도)

## 4. 아키텍처 개요

### 4.1 라우팅 / UI 진입

대시보드의 3번째 카드 (sensory · DISC 옆). `/me/?test=voice_intake` 진입.

### 4.2 시험 러너 모듈 분리

오감각의 boolean checklist, DISC 의 ranking 과 응답 모델이 다름 (폼 입력) → **별도 모듈** `me/js/voice-intake-runner.js`. `MeApp.go('test', {testType:'voice_intake'})` 분기.

### 4.3 검증

서버 단일 source — `VoiceIntake::validate(array $answers): array` PHP 클래스. 채점 없으니 검증만 수행하고 result_data JSON 빌드. `api/member_tests.php?action=submit` 의 `test_type` 화이트리스트에 `'voice_intake'` 추가.

### 4.4 파일 레이아웃

**신규**
```
public_html/
├─ me/js/
│  ├─ voice-intake-runner.js        NEW — 2섹션 폼 + 제출 + 응답 보기
│  └─ voice-intake-data.js           NEW — 11문항 메타 (JS)
├─ includes/tests/
│  └─ voice_intake_meta.php          NEW — 11문항 + validate()
migrations/
└─ 20260508_test_results_add_voice_intake.sql   NEW
tests/
├─ voice_intake_validate_test.php                NEW
├─ voice_intake_php_js_parity_test.php           NEW
└─ member_tests_voice_intake_submit_test.php     NEW
```

**수정**
- `public_html/me/index.php` — `<script src="/me/js/voice-intake-runner.js">`, `voice-intake-data.js` 추가
- `public_html/me/js/app.js` — `render()` 의 `'test'` 분기에 `testType==='voice_intake'` 라우팅 추가
- `public_html/me/js/dashboard.js` — `renderVoiceIntakeCard` 추가, `loadCards` 가 voice_intake latest 도 fetch
- `public_html/api/member_tests.php` — submit 화이트리스트 `+'voice_intake'` + dispatch 분기
- `public_html/admin/js/pages/member-chart.js` & `coach/js/pages/member-chart.js` — `formatTestResult` 의 `isNewVoiceIntake` 분기 (11행 펼침)

## 5. 데이터 모델

### 5.1 마이그레이션 — ENUM 확장

`migrations/20260508_test_results_add_voice_intake.sql`:
```sql
ALTER TABLE test_results
  MODIFY COLUMN test_type ENUM('disc', 'sensory', 'voice_intake') NOT NULL;
```

기존 row 영향 없음. DEV 먼저 적용, 사용자 운영 반영 요청 시 PROD 동일 적용.

### 5.2 `result_data` JSON v1 — voice_intake

```json
{
  "version": 1,
  "answers": {
    "q1":  { "value": "여성" },
    "q2":  { "value": "30대" },
    "q3":  { "value": "국내" },
    "q4":  { "value": "기타", "other": "특정 자격시험 대비" },
    "q5":  { "value": "1시간~2시간" },
    "q6":  { "value": "저녁(18시~0시)" },
    "q7":  { "value": "바쁜 일상으로 훈련 시간 부족" },
    "q8":  { "value": "꾸준히 하는 훈련 습관 형성" },
    "q9":  { "value": "타이트하게 끌어주는 코치" },
    "q10": { "values": ["목소리가 잘 쉰다.", "영어공부 꾸준히 해본적이 없다."] },
    "q11": { "value": "그렇다" }
  },
  "submitted_at": "2026-05-08T17:30:11+09:00"
}
```

### 5.3 필드 규칙

- 단일 선택 (Q1~Q9, Q11): `{value: string}`
- 다중 선택 (Q10): `{values: string[]}` (최소 1개)
- "기타" 선택 (`value === '기타'`): `other` 필드 필수, trim 후 비어있지 않아야, max 200자
- "기타" 가 아닌 경우 `other` 무시 (저장 안 함)

### 5.4 검증 규칙 (`VoiceIntake::validate()` throws `InvalidArgumentException`)

- `answers.q1` ~ `answers.q11` 11개 키 모두 존재
- 단일 문항: `value` 가 해당 문항 옵션 중 하나 (string equality)
- 다중 문항 (Q10): `values` 배열, 비어있지 않음, 모든 항목이 옵션 중 하나, 중복 없음
- "기타" 인 경우: `other` trim 후 1자~200자
- Q10 "해당없음" + 다른 항목 동시 체크 → 검증 실패 (상호 배타)

### 5.5 인덱스

기존 `idx_member_id` 그대로 사용. 추가 없음.

### 5.6 "최신 1건" 쿼리 (sensory/DISC 와 동일 패턴)

```sql
SELECT * FROM test_results
WHERE member_id = ? AND test_type = 'voice_intake'
ORDER BY tested_at DESC, id DESC
LIMIT 1
```

## 6. 11문항 메타

각 문항 키: `id`, `text` (원본 질문문), `short_label` (어드민 차트용 짧은 라벨), `type` (`single`/`multi`), `options` (string[]), `allow_other` (boolean), `section` (1 또는 2).

### 섹션 1 — 기본 정보

| ID | type | allow_other | short_label | text | options |
|----|------|-------------|-------------|------|---------|
| q1 | single | × | 성별 | 성별을 알려주세요. | 여성 / 남성 |
| q2 | single | ✓ | 연령대 | 연령대를 알려주세요. | 10대 / 20대 / 30대 / 40대 / 50대 / 60대 / 70대 이상 / 기타 |
| q3 | single | ✓ | 거주지역 | 거주지역은 어디신가요? | 국내 / 해외 / 기타 |
| q4 | single | ✓ | 학습 목표 | 소리튠 영어를 하는 목표를 알려주세요. | 승진이나 취업을 위해서 / 업무에 필요해서 / 유학을 위해서 / 해외여행을 위해서 / 영어를 유창하게 하고 싶어서. / 일상에서의 원활한 의사소통을 위해서 / 자연스러운 영어소리와 리스닝 향상을 위해서 / 영화나 미드를 자막없이 보기 위해서 / 자기 만족& 자신감을 위해서 / 기타 |
| q5 | single | ✓ | 하루 투자 시간 | 소리튠영어 훈련하는데 하루에 투자할 수 있는 시간을 알려주세요. | 30분 이하 / 30분~1시간 / 1시간~2시간 / 2시간~3시간 / 3시간~4시간 / 4시간 이상 / 기타 |
| q6 | single | ✓ | 훈련 시간대 | 주로 훈련하는 시간대를 알려주세요. (한국시간 기준) | 오전(6시~12시) / 오후(12시~18시) / 저녁(18시~0시) / 새벽(0시~6시) / 기타 |

### 섹션 2 — 훈련 방향

섹션 헤더: "훈련 방향 설정을 위한 설문입니다."

| ID | type | allow_other | short_label | text | options |
|----|------|-------------|-------------|------|---------|
| q7 | single | ✓ | 지속 어려움 | 그동안 영어 공부를 지속하기 어려웠던 상황은 무엇인가요. | 낮은 영어 훈련 의지 / 불규칙한 생활 패턴 / 바쁜 일상으로 훈련 시간 부족 / 나에게 맞는 훈련법이 없음 / 기타 |
| q8 | single | ✓ | 코칭 도움 | 음성코칭서비스를 통해 어떤 도움을 받고 싶나요. | 꾸준히 하는 훈련 습관 형성 / 함께 한다는 심리적 지지 / 끝까지 완주하는 성취감 / 코치와의 소통 / 기타 |
| q9 | single | ✓ | 코치 스타일 | 원하시는 코치 스타일을 알려주세요. | 타이트하게 끌어주는 코치 / 상냥하게 밀어주는 코치 / 상관없음 / 기타 |
| q10 | **multi** | × | 해당 사항 | 해당 사항에 체크해주세요. (복수체크 가능) | 목소리가 잘 쉰다. / 한국어 딕션이 명확하지 않다. / 매일 1시간이상 영어훈련에 투자하기 어렵다. / 영어공부 꾸준히 해본적이 없다. / 음치박치다. / 듣고 이해하는 것보다 글을 읽고 이해하는 게 빠르다. / 해외 여행시 영어가 두렵다. / 말을 할 때 조음기관(입, 혀, 턱)의 움직임이 크지 않다. / 해당없음 |
| q11 | single | × | 자기개방 편안함 | 자신의 이야기를 편안하게 나눌 수 있나요? | 매우 그렇다 / 그렇다 / 그렇지않다 / 매우그렇지않다 |

## 7. UI — 시험 진행 화면

### 7.1 섹션 1 (Q1~Q6)

```
← 메인으로                            음성 케어 매칭 사전 질문

1 / 2  기본 정보

Q1. 성별을 알려주세요.
○ 여성    ○ 남성

Q2. 연령대를 알려주세요.
○ 10대  ○ 20대  ○ 30대 ... ○ 기타
   └ "기타" 체크 시: 텍스트 입력란 (200자)

Q3 ~ Q6 동일 패턴

[                다음 →                ]   ← 모든 문항 충족 시 활성
```

### 7.2 섹션 2 (Q7~Q11)

```
← 이전 (섹션 1로)                     음성 케어 매칭 사전 질문

2 / 2  훈련 방향 설정을 위한 설문입니다.

Q7 ~ Q9 단일 + 기타
Q10 다중 (체크박스 9개, "해당없음" 상호 배타)
Q11 4점 척도 단일

[ ← 이전 ]   [        제출하기        ]
```

### 7.3 클라이언트 검증

- 단일 문항: 라디오 선택 필수
- Q10 다중: 최소 1개
- "기타" 선택 시 텍스트 비어있으면 빨간 안내문 ("내용을 입력해주세요")
- "해당없음" 체크 시 다른 8개 자동 해제 + 다른 8개 중 하나 체크 시 "해당없음" 자동 해제
- 미충족이면 "다음" / "제출하기" 비활성

### 7.4 진행 상태 보존

세션 메모리만. 새로고침 시 처음부터. 5분 분량이라 자동 저장 불필요 (YAGNI).

### 7.5 PHP↔JS 데이터 동기화

`includes/tests/voice_intake_meta.php` 와 `me/js/voice-intake-data.js` 양쪽에 동일 11문항 메타. parity 가드는 questions 의 `id`, `text`, `type`, `allow_other`, `options` 까지 검증. 한쪽만 수정 시 즉시 fail.

## 8. 결과 화면 (회원용)

### 8.1 제출 직후

```
✓ 응답이 저장되었습니다

소중한 응답 감사합니다. 코치 매칭에 반영됩니다.

[ 메인으로 ]
```

### 8.2 "내 응답 보기" — 11문항 펼친 화면

대시보드 카드의 "내 응답 보기" 클릭 시. `MeApp.go('result', {testType:'voice_intake', resultData})` → `MeVoiceIntakeRunner` 의 view 모드.

```
← 메인으로                            음성 케어 매칭 사전 질문

[ 응시일: 2026-05-08 ]

Q1. 성별을 알려주세요.
   여성

Q2. 연령대를 알려주세요.
   30대

... Q3~Q11

Q10. 해당 사항에 체크해주세요. (복수체크 가능)
   • 목소리가 잘 쉰다.
   • 영어공부 꾸준히 해본적이 없다.
   • 해외 여행시 영어가 두렵다.

[ 수정하려면 다시 응답 ]   [ 메인으로 ]
```

원본 긴 질문문 (text) 사용. 어드민 차트의 짧은 라벨과 다름.

## 9. 어드민·코치 차트 카드

```
음성 케어 매칭 사전 질문
┌──────────────────────────────────────────────────────┐
│ 2026-05-08      [삭제]                                 │
│ Q1. 성별                       여성                    │
│ Q2. 연령대                     30대                    │
│ Q3. 거주지역                   국내                    │
│ Q4. 학습 목표                  영어를 유창하게 하고 싶어서. │
│ Q5. 하루 투자 시간             1시간~2시간             │
│ Q6. 훈련 시간대                저녁(18시~0시)           │
│ Q7. 지속 어려움                바쁜 일상으로 훈련 시간 부족  │
│ Q8. 코칭 도움                  꾸준히 하는 훈련 습관 형성   │
│ Q9. 코치 스타일                타이트하게 끌어주는 코치    │
│ Q10. 해당 사항                목소리가 잘 쉰다.          │
│                              영어공부 꾸준히 해본적이 없다.│
│                              해외 여행시 영어가 두렵다.   │
│ Q11. 자기개방 편안함           그렇다                    │
│ memo: ...                                              │
└──────────────────────────────────────────────────────┘
[+ 결과 추가]   ← 어드민만
```

`formatTestResult` 의 `isNewVoiceIntake` 분기:
```js
const isNewVoiceIntake = testType === 'voice_intake' && parsed.version === 1 && parsed.answers;
```

분기 우선순위: `isNewSensory` → `isNewDisc` → `isNewVoiceIntake` → legacy fallback.

**렌더 규칙**
- Q번호 + `short_label` + 답변
- "기타" 선택의 경우 `other` 텍스트 표시 (옵션 텍스트 "기타" 자체는 표시 안 함)
- Q10 다중은 줄바꿈으로 나열
- short_label 매핑은 어드민/코치 JS 안에 inline (PHP 메타와 별도 — 옵션 텍스트는 byte-identical 필요지만 short_label 은 client-only display)

## 10. 보안 / 가드 / 감사

- `requireMember()` 가드: submit / latest 모두
- `member_tests.php?action=latest&test_type=voice_intake` 본인만 (URL 파라미터 무시)
- 어드민/코치는 기존 `api/tests.php?action=list` 그대로
- 클라가 위조한 검증 결과 보내도 서버 재검증이 우선
- session/CSRF 정책 sensory/DISC 와 동일

## 11. 테스트

### 11.1 PHP 검증 단위 — `tests/voice_intake_validate_test.php`

- `VoiceIntake::questions()` 길이 11
- 정상 정상 응답 → 검증 통과, result_data 빌드
- Q1 누락 → throws
- Q1 옵션 외 값 → throws
- Q4 "기타" + other 비어있음 → throws
- Q4 "기타" + other 200자 초과 → throws
- Q4 "비기타" 옵션 + other 무시 (저장 안 됨)
- Q10 빈 배열 → throws
- Q10 옵션 외 값 → throws
- Q10 "해당없음" + 다른 항목 동시 체크 → throws

### 11.2 PHP↔JS parity — `tests/voice_intake_php_js_parity_test.php`

- JS `voice-intake-data.js` 텍스트 파싱 → PHP `VoiceIntake::questions()` 와 비교
- 11문항 모두 `id`, `text`, `type`, `allow_other`, `options` 일치
- 한쪽만 수정 시 즉시 fail

### 11.3 Submit — `tests/member_tests_voice_intake_submit_test.php`

- 정상 submit → row 생성, `test_type='voice_intake'`, `version=1`
- 위조 응답 (예: Q1=`잘못된값`) → 400
- Q4 "기타" + other 누락 → 400
- Q10 "해당없음" + 다른 항목 → 400
- 같은 회원 두 번 → 두 row, `id DESC` tiebreaker

### 11.4 Latest — 기존 `tests/member_tests_self_test.php` 보강

- voice_intake 미응시 → result=null
- voice_intake 응시 후 latest → 본인 row
- sensory + disc + voice_intake 동시 응시 후 각각 latest → 분리

### 11.5 통합 (수동 browser smoke)

- `/me/` 회원 로그인 → 대시보드 음성 설문 카드 → "응답하기"
- 섹션 1 (Q1~Q6) 진행, "기타" 텍스트 입력 시도, 미선택 시 "다음" 비활성 확인
- 섹션 2 진행, Q10 "해당없음" 토글 동작 확인 (다른 항목 자동 해제 + 그 반대)
- "이전" 으로 섹션 1 돌아가서 수정
- "제출하기" → 감사 화면 → "메인으로"
- 대시보드 카드 "응시 완료 + 응시일" 표시
- "내 응답 보기" → 11문항 펼친 화면
- 어드민 차트에서 11행 카드 표시 (sensory/DISC 와 분리된 섹션)
- 모바일 1회

### 11.6 회귀 가드

- sensory / DISC 시험 정상 (submit 화이트리스트 확장이 영향 없는지 단위테스트)
- 510/510 PHP 테스트 그대로 통과 + voice_intake 신규 추가

### 11.7 PROD invariant

- `SELECT COUNT(*) FROM test_results WHERE test_type='voice_intake' AND JSON_EXTRACT(result_data,'$.version')=1` ≥ 1 (응시 발생 후)
- `result_data->>'$.answers.q1.value'` ∈ {여성, 남성}
- `result_data->>'$.answers.q11.value'` ∈ {매우 그렇다, 그렇다, 그렇지않다, 매우그렇지않다}

## 12. 알려진 한계

### 12.1 회원 신원 검증 없음 (sensory/DISC 와 동일)

기존 spec 들과 동일.

### 12.2 questions / categories 변경 시 과거 응답 의미 변동

`version` 분리. 옵션 텍스트가 바뀌면 과거 row 의 `value` 가 새 옵션과 매칭 안 될 수 있음 — 그래서 답변 자체가 옵션 텍스트 그대로 저장됨. 옵션 변경 시 과거 row 는 historic 텍스트 그대로 유지.

### 12.3 short_label JS-only 정의

어드민 차트 표시용 `short_label` 은 JS 안에 inline (PHP 메타와 별도). 라벨 변경 시 JS 만 수정해도 충분하지만 운영팀이 PHP 도 같이 본다면 양쪽 동기화 필요. 지금은 차트 표시용으로만 사용되니 JS 단독.

## 13. 마이그레이션 / 배포 순서

1. **DEV (dev-pt.soritune.com, dev 브랜치)**
   - 마이그 적용: `mysql ... < migrations/20260508_test_results_add_voice_intake.sql`
   - 코드 머지 → `tests/` 전부 PASS
   - DEV DB 시드 회원으로 수동 smoke
2. **사용자 확인** (DEV 검수)
3. **PROD (pt.soritune.com, main 브랜치)** — 사용자 명시 요청 시
   - **마이그 PROD 적용 먼저** (ENUM 확장만이라 안전, 무중단)
   - main 머지 → prod pull → smoke
   - 운영팀 회원 안내 (이미 `/me/` 사용 중이라 추가 공지 옵션)

## 14. 향후 작업

- 자동 매칭 시스템이 voice_intake 응답을 입력으로 활용 (별도 spec)
- 회원 OTP 인증 (강한 신원 검증)
- 응답 통계 대시보드 (어드민용 — 분포 시각화)
