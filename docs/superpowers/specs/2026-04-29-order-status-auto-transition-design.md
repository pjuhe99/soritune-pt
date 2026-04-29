# Order Status 자동 전환 설계

- **작성일**: 2026-04-29
- **대상**: pt.soritune.com (`_______site_SORITUNECOM_PT`)
- **범위**: `orders.status` 자동 전이 (cron + 명시 액션 후크)

## 1. 배경

현재 `orders.status` 의 자동 전이는 두 가지뿐이다.

1. order 생성 시 default `매칭대기`
2. 매칭 batch confirm 시 `매칭대기` → `매칭완료` (`/api/matching.php`)

이외 전이(`매칭완료` → `진행중`, `진행중` → `종료`, 코치 해제 시 `매칭대기` 되돌림)는 모두 운영자의 수동 처리에 의존한다. 결과적으로:

- end_date 가 지난 order가 계속 `진행중` 으로 남아 통계 왜곡
- 코치가 해제됐는데 status 가 `매칭완료/진행중` 으로 남아 매칭 대기열에서 누락
- 회차 다 소진됐는데도 `진행중` 으로 남음

회원 화면의 `display_status`는 `helpers.php::memberStatusSQL()` 이 orders 의 status 우선순위 룩업으로 즉시 파생하므로, **`orders.status` 자체를 정확히 유지하는 것** 이 본 설계의 핵심이다.

## 2. 결정 사항 (확정)

| 항목 | 결정 |
|---|---|
| 보호 상태 | `연기 / 중단 / 환불 / 종료` — 자동 전환에서 제외 |
| 횟수형 종료 조건 | `today > end_date` OR `used_sessions >= total_sessions` |
| 종료 즉시 전환 | 보류 단계 없이 곧바로 `종료` |
| 코치 해제 시 되돌림 | `매칭완료/진행중` 이고 `coach_id IS NULL` 이면 `매칭대기` 로 (보호 상태 제외) |
| 트리거 | cron 일 1회 (03:00 KST) + 명시 액션 직후 부분 재평가 |
| 변경 로그 | 모든 자동 전환을 `change_logs` 에 `actor_type='system'`, 사유별 라벨 분리 |

## 3. 결정 트리

입력: `coach_id`, `product_type`, `start_date`, `end_date`, `total_sessions`, `today` (KST 기준)

```
if status IN ('연기','중단','환불','종료')
    → 변경 없음 (보호 상태)

else if coach_id IS NULL
    → 매칭대기

else if 종료 조건 만족
    period: today > end_date
    count : today > end_date  OR  used_sessions >= total_sessions
    → 종료

else if today >= start_date
    → 진행중

else
    → 매칭완료
```

`used_sessions` 산출:
```sql
SELECT COUNT(*) FROM order_sessions
WHERE order_id = ? AND completed_at IS NOT NULL
```

경계 정의:
- `start_date == today` → **진행중** (시작일 당일부터 진행)
- `end_date == today` → **진행중 유지** (종료일 당일까지 PT 가능); `today > end_date` 부터 `종료`

`today` 산정: `DATE(NOW())` (서버 timezone 기준 — PT 서버는 KST, 별도 변환 불필요. 함수 내 한 곳에서만 계산해 같은 값을 트리에 흘림으로써 시·분 경계의 race를 막음)

## 4. 핵심 함수: `recomputeOrderStatus()`

위치: `public_html/includes/helpers.php` (기존 `getMemberDisplayStatus`, `logChange` 와 같은 파일)

### 시그니처

```php
/**
 * 결정 트리에 따라 단일 order의 status를 재평가하고 필요 시 갱신한다.
 * cron 과 명시 액션 후크가 공통으로 호출하는 단일 진입점.
 *
 * @param PDO      $db
 * @param int      $orderId
 * @param string   $today    YYYY-MM-DD. cron이 같은 값으로 모든 order를 평가하기 위해 외부 주입 가능. 생략 시 DATE(NOW()).
 * @return string|null       변경된 새 status. 변경이 없으면 null.
 */
function recomputeOrderStatus(PDO $db, int $orderId, ?string $today = null): ?string
```

### 내부 동작

본 함수는 **호출자 책임 모델**로 동작한다 — 트랜잭션과 row lock 은 호출자가 관리하고, 본 함수는 평가/UPDATE/로그만 수행한다.

1. `$today` 가 없으면 `date('Y-m-d')` 로 산정.
2. order row 조회 (`coach_id`, `status`, `product_type`, `start_date`, `end_date`, `total_sessions`). 호출자가 `FOR UPDATE` 로 lock 한 상태를 가정.
3. `status` 가 보호 상태 → return null.
4. 결정 트리(섹션 3) 평가 → `$newStatus`.
5. `$newStatus === $row['status']` → return null.
6. `UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?`.
7. `change_logs` INSERT (`logChange()` 재사용; `actor_type='system'`, `actor_id=0`).
8. return `$newStatus`.

호출자는 `withOrderLock()` 헬퍼(섹션 5)를 사용해 `BEGIN → SELECT FOR UPDATE → recomputeOrderStatus → COMMIT` 패턴을 통일한다.

### 호출 예 (명시 액션 후크)

```php
$db->beginTransaction();
try {
    $db->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE')
       ->execute([$orderId]);
    recomputeOrderStatus($db, $orderId);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
```

## 5. 트리거 위치

| # | 위치 | 시점 | 호출 범위 |
|---|---|---|---|
| 1 | `cron/auto_status_transition.php` | 매일 03:00 KST | 보호 상태 아닌 모든 order |
| 2 | `api/matching.php` confirm | batch 확정 직후 | 그 batch에 속한 order들 (기존 직접 UPDATE 라인은 함수 호출로 대체) |
| 3 | `api/orders.php` create / update | order 저장 직후 | 그 order 1건 |
| 4 | `api/orders.php` complete_session | 회차 완료/해제 토글 후 | 그 order 1건 (count 상품의 마지막 회차 체크 시 즉시 종료 보장) |
| 5 | `api/import.php` CSV import 후 | 임포트 트랜잭션 끝난 뒤 | 영향받은 order들 |
| 6 | 어드민 코치 해제 흐름 (`api/orders.php` update에서 coach_id NULL 변경 포함) | 저장 직후 | 그 order 1건 (#3과 같은 진입점이면 #3에 흡수) |

명시 액션 후크는 모두 동일 패턴(`BEGIN` → `FOR UPDATE` → `recomputeOrderStatus` → `COMMIT`)을 사용한다. 코드 중복을 줄이기 위해 헬퍼 `withOrderLock(PDO $db, int $orderId, callable $fn)` 도 추가한다.

```php
function withOrderLock(PDO $db, int $orderId, callable $fn): mixed
{
    $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE')->execute([$orderId]);
        $result = $fn();
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
```

## 6. cron 구현

파일: `cron/auto_status_transition.php`

```php
<?php
require __DIR__ . '/../public_html/includes/db.php';
require __DIR__ . '/../public_html/includes/helpers.php';

$db = getDB();
$today = date('Y-m-d');

$candidates = $db->query("
    SELECT id FROM orders
    WHERE status NOT IN ('연기','중단','환불','종료')
")->fetchAll(PDO::FETCH_COLUMN);

$summary = ['total' => count($candidates), 'changed' => 0, 'by_action' => []];

foreach ($candidates as $orderId) {
    $newStatus = withOrderLock($db, (int)$orderId, function () use ($db, $orderId, $today) {
        return recomputeOrderStatus($db, (int)$orderId, $today);
    });
    if ($newStatus !== null) {
        $summary['changed']++;
    }
}

// summary는 change_logs 카운트로 사후 집계 가능 — 별도 파일 로그도 추가
$logLine = sprintf(
    "[%s] candidates=%d changed=%d\n",
    date('Y-m-d H:i:s'), $summary['total'], $summary['changed']
);
file_put_contents(__DIR__ . '/logs/auto_status.log', $logLine, FILE_APPEND);
```

crontab:
```
0 3 * * * /usr/bin/php /var/www/html/_______site_SORITUNECOM_PT/cron/auto_status_transition.php >> /var/www/html/_______site_SORITUNECOM_PT/cron/logs/cron.log 2>&1
```

`cron/logs/` 디렉토리가 없으면 생성. SELinux 컨텍스트는 PT 서버 정책에 맞춤(httpd_log_t 등 적용 여부는 배포 시 확인).

후보 수가 약 1만 row 이내로 예상되므로 단일 트랜잭션 다수 호출 패턴(=order별 BEGIN/COMMIT)이 충분히 견딘다. 부하 테스트 결과 5만 row 이상으로 늘면 batch 처리(IN 절 100개씩 묶어서 lock)로 전환 검토.

## 7. 변경 로그 (change_logs)

| 전이 | action 라벨 |
|---|---|
| `매칭대기` → `매칭완료` | `auto_match_complete` |
| `매칭완료` → `진행중` | `auto_in_progress` |
| `진행중` → `종료`, `매칭완료` → `종료` | `auto_terminate` |
| `매칭완료/진행중` → `매칭대기` (코치 해제) | `auto_revert_to_pending` |

페이로드:
- `target_type = 'order'`
- `target_id = order.id`
- `old_value = {"status": "<old>"}`
- `new_value = {"status": "<new>"}`
- `actor_type = 'system'`
- `actor_id = 0`

회원 차트 "변경로그" 탭은 이미 `change_logs` 를 그대로 표시하므로 추가 작업은 표시 라벨 정도만 손본다.

UI 표시 보강:
- `coach/js/pages/member-chart.js::loadLogs()` 와 어드민의 동등 위치에서, `actor_type='system'` 인 row를 "시스템 자동" 라벨로 표시한다.
- `action` 별 한국어 라벨 매핑 (예: `auto_terminate` → "기간/회차 만료 자동 종료").

## 8. 어드민 직접 수정과의 상호작용

운영자가 어드민에서 status 를 직접 변경하면:
- 일반 status 로 바꾼 경우(`매칭대기/매칭완료/진행중`) → 다음 cron 시 결정 트리 재평가에 의해 다시 변경될 수 있음. 이는 의도된 동작 (사람이 잘못 누른 경우 cron 이 정정).
- 보호 상태로 바꾼 경우(`연기/중단/환불/종료`) → cron 은 손대지 않음. 다시 활성화하려면 운영자가 `매칭완료/진행중/매칭대기` 중 적절한 값으로 수동 복귀.

## 9. 마이그레이션 / 백필

신기능 도입 시점에 1회 실행할 백필 cron:
- 본 cron 파일 그대로 1회 수동 실행.
- 보호 상태가 아닌 기존 모든 order가 룰에 맞게 정렬된다.
- 첫 실행은 변경 row 가 다수일 수 있으므로 사용량 적은 시간대(03:00 등)에 진행 권장.

스키마 변경 없음 (기존 `orders.status` ENUM, `change_logs.actor_type='system'` 모두 그대로 사용).

## 10. 테스트 전략

테스트 파일 위치는 plan 단계에서 PT 기존 테스트 디렉토리(`tests/` 또는 `public_html/tests/`) 를 확인 후 동일 위치에 배치 (없으면 신규 디렉토리 생성). 테스트 러너는 PT 기존 관행을 따름 (`php tests/<file>.php` 직접 실행 또는 PHPUnit). 케이스 자체는 다음과 같다:

각 케이스마다 fixture order 1건 생성 → `recomputeOrderStatus()` 호출 → 결과 검증 + change_logs 1건 INSERT 확인.

| # | 시나리오 | 기대 결과 |
|---|---|---|
| 1 | coach_id NULL, status `매칭완료` | `매칭대기` (auto_revert_to_pending) |
| 2 | coach_id NULL, status `진행중` | `매칭대기` (auto_revert_to_pending) |
| 3 | coach_id NULL, status `매칭대기` | 변경 없음 |
| 4 | coach 있음, start_date 미래 | `매칭완료` |
| 5 | coach 있음, start_date == today | `진행중` |
| 6 | coach 있음, start_date 과거, end_date 미래 | `진행중` |
| 7 | period, end_date < today | `종료` |
| 8 | period, end_date == today | `진행중` 유지 |
| 9 | count, used_sessions == total_sessions | `종료` |
| 10 | count, end_date 만료 (회차 미소진) | `종료` |
| 11 | status `연기` (모든 조건 무시) | 변경 없음 |
| 12 | status `중단` | 변경 없음 |
| 13 | status `환불` | 변경 없음 |
| 14 | status `종료` | 변경 없음 |
| 15 | 두 트랜잭션이 같은 order 호출 (lock 검증) | 한 번만 변경 적용 |

## 11. Out of Scope

- 새 status 값 추가 (`종료_보류` 등) — 도입하지 않음.
- 자동 매칭 로직 변경 — 본 설계는 `orders.status` 만 다루며, 매칭 알고리즘은 기존 그대로.
- members 테이블에 status 컬럼 추가 — 현 파생 모델 유지.
- 알림(메일/카톡) — 별도 작업 (필요 시 후속 spec).

## 12. 리스크 / 완화

| 리스크 | 완화 |
|---|---|
| cron 실행 중 어드민이 동일 order 수정 | `FOR UPDATE` row lock |
| 첫 백필 시 다수 row 변경으로 change_logs 폭증 | 1만 row 미만 예상; 변경된 row 만 로그 → 실제 행 수는 수백~수천 추정 |
| `today` 시점 차이로 cron 끝물에 status 흔들림 | 함수 외부에서 `today` 한 번 산정, 모든 호출에 같은 값 주입 |
| 보호 상태로 잘못 둔 order 영구 동결 | 어드민 변경로그 탭에서 추적 가능; 의도적이라면 그대로 둠 |
| 회차 토글 후 즉시 종료 안 되는 race | 트리거 #4(complete_session 후) 추가로 즉시 반영 보장 |
