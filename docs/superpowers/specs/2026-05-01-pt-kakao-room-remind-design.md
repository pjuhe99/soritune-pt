# PT 카톡방 입장 리마인드 알림톡 시나리오 설계

**작성일**: 2026-05-01
**도메인**: pt.soritune.com (PT 알림톡)
**상태**: design

## 1. 배경 및 목적

`pt.soritune.com`은 보유한 알림톡 인프라(`notify_*` 테이블 + dispatcher + Solapi 클라이언트, 2026-04-29 T1~T12 완료)를 사용해 첫 운영 시나리오를 도입한다.

**시나리오 의도**: 매일 19시(KST), 이번 달 cohort `소리튜닝 음성PT` **진행중** 회원 중 1:1PT 카톡방에 아직 입장하지 않은 사람에게 입장 리마인드 알림톡을 보낸다. 입장이 체크되면 다음 발송에서 자동 제외된다.

이번 작업 범위:
- 새 시나리오 정의 1개 (`pt_kakao_room_remind`)
- 새 데이터 소스 어댑터 1개 (`source_pt_orders_query`) — PT DB orders 직접 조회 방식
- 시나리오 카드에 `description` 요약 텍스트 표시 (기존 어드민 UI 소폭 확장)
- DEV DB 테스트 시드 (kongtest29 외 모두 입장 체크)
- PROD dispatcher cron 등록

기존 시트 어댑터(`source_pt_sheet_member`)는 무해하게 그대로 둔다. 향후 시트 기반 시나리오 추가 시 재사용.

## 2. 시나리오 정의

`public_html/includes/notify/scenarios/pt_kakao_room_remind.php`:

```php
<?php
return [
  'key' => 'pt_kakao_room_remind',
  'name' => '카톡방 입장 리마인드 (음성PT)',
  'description' =>
      '매일 19시, 이번 달 cohort 소리튜닝 음성PT 진행중인 회원 중 '
    . '1:1PT 카톡방 미입장자에게 리마인드 알림톡 발송. '
    . '회원 phone, 담당 코치, 코치 카톡방 링크가 모두 있어야 발송. '
    . '입장하면 다음 날부터 자동 제외.',
  'source' => [
    'type' => 'pt_orders_query',
    'product_name' => '소리튜닝 음성PT',     // 정확 일치
    'status' => ['진행중'],                   // 환불/연기/중단/종료/매칭대기/매칭완료 제외
    'kakao_room_joined' => 0,
    'cohort_mode' => 'current_month_kst',     // 매월 자동 동적
  ],
  'template' => [
    'templateId'   => 'KA01TP260429031809566vKO0c8WyDAl',
    'fallback_lms' => false,
    'variables' => [
      // notify_functions.php::notifyRenderVariables 형식: '#{변수}' => 'col:컬럼명' | 'const:값'
      '#{회원}'        => 'col:회원',
      '#{담당 코치}'   => 'col:담당 코치',
      '#{채팅방 링크}' => 'col:채팅방 링크',
    ],
  ],
  'schedule' => '0 19 * * *',   // 매일 19:00 KST
  'cooldown_hours' => 23,        // 매일 1회 도배 방지 (cron 분 단위 흔들림 흡수)
  'max_attempts' => 0,           // 무제한 (입장할 때까지)
];
```

기본 `is_active=0`으로 등록 → 어드민에서 운영자가 토글 ON 해야 가동 (기존 인프라 패턴).

**Solapi 채널/키**: 기존 `keys/solapi.json` 그대로 사용. `pfId_override` 없음 → `defaultPfId` 사용.

## 3. 새 어댑터 `source_pt_orders_query.php`

### 3.1 책임

cfg를 받아 PT orders + members + coaches를 한 SQL로 조인해 발송 후보 행을 dispatcher가 기대하는 형태로 반환한다.

### 3.2 동작

```sql
SELECT DISTINCT
  o.member_id,
  m.name        AS member_name,
  m.phone       AS member_phone,
  c.coach_name  AS coach_name,
  c.kakao_room_url AS kakao_room_url,
  o.cohort_month
FROM orders o
JOIN members m ON m.id = o.member_id
LEFT JOIN coaches c ON c.id = o.coach_id
WHERE o.product_name      = :product_name
  AND o.status            = '진행중'
  AND o.kakao_room_joined = 0
  AND o.cohort_month      = :cohort_month
ORDER BY m.name, o.member_id
```

- `JOIN members`: phone 룩업.
- `LEFT JOIN coaches`: 코치 미매칭(`coach_id` NULL)도 일단 끌어와서 어드민 메시지 목록에 노출되도록 (운영자가 후속 조치 식별 가능).
- `DISTINCT` + `member_id` 단위 그룹: 같은 회원이 같은 cohort에 음성PT 주문 여러 개 보유해도 1행만 발송.
- `cohort_month` 바인딩: cfg `cohort_mode`에 따라 결정.

### 3.3 cohort_mode

| 값 | 동작 |
|----|------|
| `'current_month_kst'` | `(new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m')` 사용 (서버 timezone 무관) |
| `'YYYY-MM'` 형태 문자열 | 고정값 그대로 (디버깅/일회성 백필용 옵션) |

기본 시나리오는 `current_month_kst`. 정규식 `/^\d{4}-\d{2}$/`로 고정값 형식 검증, 그 외는 throw.

### 3.4 반환 row 형태

dispatcher 인터페이스 준수:

```php
[
  'row_key' => "pt_orders:{$cohort_month}:{$member_id}",
  'phone'   => (string) $row['member_phone'] ?? '',   // 빈 값 → dispatcher가 phone_invalid skip
  'name'    => $row['member_name'],
  'columns' => [
    '회원'         => $row['member_name'],
    '담당 코치'    => $row['coach_name']     ?? '',
    '채팅방 링크'  => $row['kakao_room_url'] ?? '',
  ],
]
```

### 3.5 미매칭/누락 케이스 (단순 통합 정책)

| 누락 항목 | 처리 |
|---|---|
| `members.phone` NULL 또는 빈값 | row 포함, `phone=''` → dispatcher가 자동 `phone_invalid` skip |
| `orders.coach_id` NULL | row 포함, `phone=''`로 강제 (변수 빈 값으로 발송 방지) |
| `coaches.kakao_room_url` NULL/빈값 | row 포함, `phone=''`로 강제 |

**정책 의도**: 발송 못 한 사람도 어드민 메시지 목록에 `name`/`row_key` 형태로 보이게 해서 운영자가 누구인지 파악하고 후속 조치(코치 매칭, phone 등록, 카톡방 URL 등록)할 수 있게 한다. dispatcher의 단일 skip 분기(`phone_invalid`)로 통합해 코드 변경 범위를 최소화한다.

### 3.6 dispatcher 통합

`dispatcher.php::notifyFetchRows()`의 match에 한 줄 추가:

```php
'pt_orders_query' => notifySourcePtOrdersQuery($def['source']),
```

`require_once` 한 줄 추가.

### 3.7 스모크 테스트

`tests/notify_pt_orders_query_test.php`. 트랜잭션 + ROLLBACK 패턴(기존 시드 영향 없음). 9 케이스:

| # | 시드 | 기대 |
|---|---|---|
| 1 | matched (모두 채워짐) | row 포함, phone 채워짐, 변수 3개 채워짐 |
| 2 | members.phone NULL | row 포함, `phone=''` |
| 3 | orders.coach_id NULL | row 포함, `phone=''` |
| 4 | coaches.kakao_room_url NULL | row 포함, `phone=''` |
| 5 | status='환불' | row 미포함 |
| 6 | cohort_month 다른 달 | row 미포함 |
| 7 | product_name='소리튜닝 화상PT' | row 미포함 |
| 8 | kakao_room_joined=1 | row 미포함 |
| 9 | 같은 회원 음성PT 주문 2개 | 1 row만 (DISTINCT 검증) |

추가:
- cohort_mode 고정값 'YYYY-MM' 정상 통과
- cohort_mode 형식 위반 ('2026-5', 'abc') → throw

## 4. 시나리오 description 어드민 표시

코드 인스펙션 결과 알림톡 인프라가 이미 description을 fully 지원하고 있다 (boot에서 그대로 복사된 부분). 따라서 본 작업의 UI 변경은 **scenario_registry 옵셔널 type 검증 1개**만이다.

### 4.1 이미 지원되는 부분 (변경 불필요)

- **`api/services/notify.php`** (line 31): list 응답에 `'description' => $def['description'] ?? ''` 이미 포함.
- **`admin/js/pages/notify.js::_renderCard`** (line ~33): `<p class="notify-desc">${UI.esc(s.description)}</p>` 이미 렌더.
- **`assets/css/notify.css`**: `.notify-desc` 클래스 이미 정의 (line 38). 줄바꿈 보존이 필요하면 CSS에 `white-space: pre-line;` 추가만 검토.

### 4.2 추가할 변경 1개

**`includes/notify/scenario_registry.php::notifyValidateScenario()`**:
```php
if (array_key_exists('description', $def) && !is_string($def['description'])) {
    throw new RuntimeException("시나리오 '{$keyLabel}': 'description'은 string이어야 함");
}
```
- 옵셔널 필드. 없으면 통과 (boot 시나리오 호환).
- 있으면 string 강제 (배열/객체로 잘못 적은 typo 조기 발견).

### 4.3 호환성

- 기존 boot의 form_reminder_ot 시나리오와 PT의 향후 시나리오 모두 호환.
- DB 변경 없음.

## 5. DEV DB 테스트 시드

```sql
-- DEV DB(SORITUNECOM_DEV_PT)에서만 실행
UPDATE orders o
LEFT JOIN members m ON m.id = o.member_id
   SET o.kakao_room_joined    = 1,
       o.kakao_room_joined_at = COALESCE(o.kakao_room_joined_at, NOW()),
       o.kakao_room_joined_by = NULL
 WHERE o.kakao_room_joined = 0
   AND (m.soritune_id IS NULL OR m.soritune_id <> 'kongtest29');
```

- `kongtest29` 회원의 모든 주문은 미체크 그대로 유지 → 시나리오 발송 테스트 대상 1명.
- `kakao_room_joined_at`이 이미 있는 행은 보존(NULL일 때만 NOW() 채움).
- `kakao_room_joined_by=NULL`: 시스템 일괄 시드 표식 (어드민의 카톡방 입장 체크 탭에서 책임자 미표시).

**검증 쿼리**:

```sql
-- (1) 미체크 주문은 kongtest29만 남는지
SELECT m.soritune_id, COUNT(*) FROM orders o
 JOIN members m ON m.id=o.member_id
WHERE o.kakao_room_joined = 0
GROUP BY m.soritune_id;
-- 기대: kongtest29만 (혹은 m.soritune_id IS NULL인 고아 주문 — 데이터 정합성 별도 이슈)

-- (2) 시나리오 SQL이 정확히 kongtest29 1명만 잡는지
SELECT m.soritune_id, m.name, m.phone, c.coach_name, c.kakao_room_url
FROM orders o
JOIN members m ON m.id = o.member_id
LEFT JOIN coaches c ON c.id = o.coach_id
WHERE o.product_name = '소리튜닝 음성PT'
  AND o.status = '진행중'
  AND o.kakao_room_joined = 0
  AND o.cohort_month = '2026-05';
-- 기대: kongtest29 1행 (단, kongtest29의 진행중 음성PT 주문이 cohort 2026-05에 있어야 함 — 사전 확인 필요)
```

만약 사전 확인에서 kongtest29의 cohort='2026-05' 진행중 음성PT 주문이 없으면, 시드 SQL에 kongtest29 주문 1건을 보정 INSERT 또는 UPDATE 추가 (구현 단계에서 결정).

## 6. PROD cron 등록

```cron
* * * * * /usr/bin/php /root/pt-prod/cron/notify_dispatch.php >> /root/pt-prod/cron/logs/notify_dispatch.log 2>&1
```

- dispatcher가 매분 돌며 active 시나리오의 schedule을 cron-match. `0 19 * * *` 매칭되는 분에만 시나리오 1회 실행.
- PT의 기존 cron(status auto-transition PROD 03:00, DEV 03:05)과 충돌 없음.
- DEV에는 dispatcher cron 등록하지 않음 (실수 발송 방지). DEV 검증은 어드민 수동 트리거(dry_run) 또는 CLI 직접 호출.

**KST 타임존**: cron job의 분/시 해석은 시스템 timezone 기준. 서버가 KST(`Asia/Seoul`)면 `0 19 * * *`이 19:00 KST. 서버가 UTC면 10:00 UTC = 19:00 KST이므로 다른 cron 표기 필요. 기존 PT auto_status_transition cron이 KST 03:00 가정으로 등록되어 있으므로 PT 서버는 KST. 구현 단계에서 `date` 명령으로 한 번 더 검증.

## 7. 구현 순서 (요약, 상세는 plan 문서 분리)

1. 어댑터 코드 + 단위 테스트
2. 시나리오 PHP 파일
3. dispatcher match 한 줄 추가
4. scenario_registry / api / notify.js / notify.css 변경
5. 자동 테스트 실행 (단위 + 기존 인프라 회귀)
6. DEV DB 시드 적용 + 검증
7. 사용자 매뉴얼 검증 (어드민 카드 표시 + dry_run 미리보기에서 kongtest29 1건)
8. dev push 결정 게이트
9. (사용자 명시 승인 후) main 머지 + push + pt-prod pull
10. PROD dispatcher cron 등록 + 토글 ON
11. 19:00 직후 PROD 배치 결과 확인

## 8. 비범위 (이번 작업 제외)

- 솔라피 키 폐기/재발급 (보안 별도 처리)
- 어드민 시나리오 편집 UI (코드 수정 없이 운영 변경)
- DEV cron 자동 발송
- 다른 cohort/상품 조합용 시나리오 (필요 시 본 시나리오 복제하여 추가)
- 회원 phone 미등록/코치 미매칭/카톡방 URL 미등록 자동 알림 (운영자가 어드민 메시지 목록에서 수동 식별)

## 9. 보안 / 운영 메모

- 솔라피 API 키 폐기/재발급 미해결 (`memory/feedback_*`, `project_pt_notify_wip.md`). PT가 boot와 동일 키를 공유하므로 영향권. 본 시나리오 가동과 분리된 보안 작업.
- 시나리오 첫 가동 시 dry_run으로 1회 미리보기 후 실발송 토글 권장.
