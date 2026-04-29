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
| 보호 상태 (기본) | `연기 / 중단 / 환불 / 종료` — 자동 전환에서 제외 |
| 종료 보호의 예외 | `complete_session` 토글 경로 한정으로 `allowRevertTerminated=true` 호출 시 `종료` 만 보호 컷 우회. `연기/중단/환불` 은 어떤 경우에도 보호. |
| 횟수형 종료 조건 | `today > end_date` OR `used_sessions >= total_sessions` (단 `total_sessions` NULL/0 이면 회차 조건은 false) |
| 종료 즉시 전환 | 보류 단계 없이 곧바로 `종료` |
| 코치 해제 시 되돌림 | `매칭완료/진행중` 이고 `coach_id IS NULL` 이면 `매칭대기` 로 (보호 상태 제외) |
| 운영자 명시 status update 보호 | `api/orders.php update` 페이로드에 `status` 키가 포함되면 같은 요청 내 후크 스킵. cron 시점에는 결정 트리에 따라 다시 정정될 수 있음. |
| 트리거 | cron 일 1회 (03:00 KST) + 명시 액션 직후 부분 재평가 |
| 변경 로그 | 모든 자동 전환을 `change_logs` 에 `actor_type='system'`, 사유별 라벨 분리 |

## 3. 결정 트리

입력: `coach_id`, `product_type`, `start_date`, `end_date`, `total_sessions`, `today` (KST 기준)

**Step A — 보호 컷** (호출자의 `$allowRevertTerminated` 플래그가 영향):
```
if status IN ('연기','중단','환불')
    → 변경 없음

if status == '종료' AND $allowRevertTerminated == false
    → 변경 없음

if status == '종료' AND $allowRevertTerminated == true
    → Step B 진입 (예: complete_session 토글로 마지막 회차 취소된 경우)
```

**Step B — 결정 트리** (보호 컷 통과 시):
```
if coach_id IS NULL
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
 * 호출자가 row 를 FOR UPDATE 로 잠그고 트랜잭션을 관리해야 한다.
 * 트랜잭션 활성 여부는 호출자가 책임 — 본 함수는 BEGIN/COMMIT 을 하지 않는다.
 *
 * @param PDO      $db
 * @param int      $orderId
 * @param string   $today                YYYY-MM-DD. cron이 같은 값으로 모든 order를 평가하기 위해 외부 주입 가능. 생략 시 date('Y-m-d').
 * @param bool     $allowRevertTerminated false 가 기본. true 이면 status='종료' 인 order 도 보호 컷을 통과시키고 결정 트리 평가 (complete_session 토글 경로 전용).
 *                                       단 '연기/중단/환불' 은 이 플래그와 무관하게 항상 보호.
 * @return string|null                    변경된 새 status. 변경이 없거나 order 가 존재하지 않으면 null.
 */
function recomputeOrderStatus(
    PDO $db,
    int $orderId,
    ?string $today = null,
    bool $allowRevertTerminated = false
): ?string
```

### 내부 동작

본 함수는 **호출자 책임 모델**로 동작한다 — 트랜잭션과 row lock 은 호출자가 관리하고, 본 함수는 평가/UPDATE/로그만 수행한다.

1. `$today` 가 없으면 `date('Y-m-d')` 로 산정.
2. order row 조회 (`coach_id`, `status`, `product_type`, `start_date`, `end_date`, `total_sessions`). 호출자가 `FOR UPDATE` 로 lock 한 상태를 가정.
3. **order 가 없으면 return null** (예외 던지지 않음 — race 로 삭제된 order 에 안전).
4. 보호 상태 컷:
   - `status` 가 `연기/중단/환불` 이면 무조건 return null.
   - `status` 가 `종료` 이면 `$allowRevertTerminated === false` 일 때 return null. true 이면 결정 트리에 진입.
5. 결정 트리(섹션 3) 평가 → `$newStatus`. **count 상품에서 `total_sessions` 가 NULL/0 인 경우 회차 소진 조건은 false 로 취급** (기간 만료만으로 종료 결정).
6. `$newStatus === $row['status']` → return null.
7. `UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?`.
8. `change_logs` INSERT (`logChange()` 재사용; `actor_type='system'`, `actor_id=0`).
9. return `$newStatus`.

### 호출 패턴 — 두 가지 케이스

**(A) 호출자에 활성 트랜잭션이 없는 경우** — `withOrderLock()` 헬퍼 사용:

```php
withOrderLock($db, $orderId, fn() => recomputeOrderStatus($db, $orderId));
```

**(B) 호출자가 이미 자체 트랜잭션 내에 있는 경우** (`api/matching.php` confirm, `api/orders.php` create/update 등) — 트랜잭션을 새로 열지 말고 같은 트랜잭션 안에서 lock 후 호출:

```php
// 이미 $db->beginTransaction() 호출된 컨텍스트
$db->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE')->execute([$orderId]);
recomputeOrderStatus($db, $orderId);
// 호출자가 책임지고 commit / rollback
```

`withOrderLock()` 은 `$db->inTransaction()` 이 true 이면 예외를 던져, B 패턴이 들어가야 할 곳에 A 가 잘못 쓰이는 사고를 방지한다.

## 5. 트리거 위치

| # | 위치 | 시점 | 호출 범위 | 패턴 | 비고 |
|---|---|---|---|---|---|
| 1 | `cron/auto_status_transition.php` | 매일 03:00 KST | 보호 상태 아닌 모든 order | A (order별 `withOrderLock`) | |
| 2 | `api/matching.php` confirm | batch 확정 직후 | 그 batch에 속한 order들 | B (기존 트랜잭션 내) | 기존 `UPDATE orders SET coach_id=?, status='매칭완료'` 두 컬럼 동시 UPDATE 라인을 **`coach_id` 만 UPDATE 후 `recomputeOrderStatus()` 호출**로 분리. status 는 결정 트리가 결정 (보통 `매칭완료`, start_date 가 이미 지난 경우 `진행중`). |
| 3 | `api/orders.php` create | order INSERT 직후 | 그 order 1건 | B (기존 트랜잭션 내) | |
| 4 | `api/orders.php` update | order UPDATE 직후 | 그 order 1건 | B (기존 트랜잭션 내) | **단, 입력 페이로드에 `status` 키가 포함된 경우 후크 스킵.** 사람이 명시한 status 는 같은 요청에서 덮지 않음 (다음 cron 시 정정). |
| 5 | `api/orders.php` complete_session | 회차 완료/해제 토글 후 | 그 order 1건 | A (`withOrderLock`) | complete_session 핸들러는 트랜잭션을 쓰지 않으므로 패턴 A. 토글 UPDATE 직후, `jsonSuccess()` 호출 전에 `withOrderLock($db, $orderId, fn() => recomputeOrderStatus($db, $orderId, null, true))` 호출. **`allowRevertTerminated=true`** 로 호출 → 자동 종료된 order 의 마지막 회차를 취소하면 `진행중` 으로 복귀. |
| 6 | `api/import.php` CSV import 후 | 임포트 트랜잭션 끝난 뒤 | 영향받은 order들 | A (각 order 별 `withOrderLock`) | 임포트 트랜잭션이 commit 된 뒤 별도 루프로 호출. import 트랜잭션이 길어 매번 재평가하기보다 import 종료 후 일괄. |

> 어드민이 코치만 해제하는 케이스(`coach_id`만 NULL 로 업데이트하고 `status` 키는 미포함)는 트리거 #4 가 흡수한다. 후크가 돌아가서 결정 트리에 따라 `매칭대기` 로 자동 되돌림.

### `withOrderLock()` 헬퍼 (패턴 A 전용)

```php
function withOrderLock(PDO $db, int $orderId, callable $fn): mixed
{
    if ($db->inTransaction()) {
        throw new RuntimeException(
            'withOrderLock() must not be called inside an active transaction. ' .
            'Use SELECT ... FOR UPDATE + recomputeOrderStatus() directly instead.'
        );
    }
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

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$candidates = $db->query("
    SELECT id FROM orders
    WHERE status NOT IN ('연기','중단','환불','종료')
")->fetchAll(PDO::FETCH_COLUMN);

$summary = ['total' => count($candidates), 'changed' => 0];

foreach ($candidates as $orderId) {
    $newStatus = withOrderLock($db, (int)$orderId, function () use ($db, $orderId, $today) {
        return recomputeOrderStatus($db, (int)$orderId, $today);
    });
    if ($newStatus !== null) {
        $summary['changed']++;
    }
}

// 사유별 카운트는 change_logs 에서 사후 집계 가능 — 파일 로그는 요약만 append
$logLine = sprintf(
    "[%s] candidates=%d changed=%d\n",
    date('Y-m-d H:i:s'), $summary['total'], $summary['changed']
);
file_put_contents($logDir . '/auto_status.log', $logLine, FILE_APPEND);
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
- `api/logs.php` 는 이미 `actor_type='system'` 일 때 `actor_name='system'` 으로 내려주고 있음 (`logs.php:27`). 본 spec 에서는 **API 응답은 손대지 않고, JS 측에서 매핑**한다.
- 매핑 위치:
  - `coach/js/pages/member-chart.js::loadLogs()`
  - 어드민의 동등 위치 (`admin/js/pages/member-chart.js` 변경로그 렌더 부분)
- 매핑 규칙:
  - `actor_name === 'system'` → "시스템 자동" 으로 표시
  - `action` 한국어 라벨 매핑:
    - `auto_match_complete` → "자동 매칭완료"
    - `auto_in_progress` → "자동 진행중 전환"
    - `auto_terminate` → "기간/회차 만료 자동 종료"
    - `auto_revert_to_pending` → "코치 해제로 매칭대기 복귀"

`includes/helpers.php::logChange()` 의 docblock 도 함께 수정한다 (`@param string $actorType 'admin', 'coach', or 'system'`). 현재는 `'admin' or 'coach'` 만 적혀 있어 스키마와 어긋남.

## 8. 어드민 직접 수정과의 상호작용

운영자가 어드민에서 status 를 직접 변경 (즉 update API 호출 페이로드에 `status` 키 포함):
- **같은 요청 내** : update 후크가 status 키 존재를 보고 스킵. 사람이 명시한 값이 그대로 저장됨 (트리거 #4 비고 참조).
- **이후 cron 시점** :
  - 일반 status (`매칭대기/매칭완료/진행중`) 로 바꾼 경우 → 다음 cron 이 결정 트리 재평가로 다시 변경할 수 있음. 이는 의도된 동작 (사람이 잘못 누른 경우 cron 이 정정).
  - 보호 상태 (`연기/중단/환불/종료`) 로 바꾼 경우 → cron 은 손대지 않음. 다시 활성화하려면 운영자가 일반 status 로 수동 복귀.

complete_session 토글 경로 (트리거 #5) 는 `allowRevertTerminated=true` 로 호출되어 `종료` 보호도 우회한다. 운영자가 직접 어드민에서 status 를 `종료` 로 둔 경우는 회차 토글로 우회하는 것이 의도와 충돌할 수 있으나, 그 시나리오에서 운영자가 회차를 다시 손대는 경우 자체가 드물고, 발생하더라도 변경 로그가 남아 추적 가능.

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
| 14 | status `종료`, 기본 호출 | 변경 없음 (보호) |
| 15 | status `종료`, `allowRevertTerminated=true` + 회차 미소진 + 기간 안 지남 | `진행중` 또는 `매칭완료` 로 복귀 |
| 16 | status `연기`, `allowRevertTerminated=true` | 변경 없음 (연기/중단/환불 은 플래그와 무관하게 보호) |
| 17 | 두 트랜잭션이 같은 order 호출 (lock 검증) | 한 번만 변경 적용 |
| 18 | order 가 존재하지 않는 id 전달 | return null (예외 없음) |
| 19 | count 상품, total_sessions = NULL, end_date 미래 | `진행중` (회차 소진 조건 false 처리) |
| 20 | count 상품, total_sessions = 0, end_date 과거 | `종료` (기간 만료) |
| 21 | `api/orders.php update` 호출 페이로드에 status 포함 | 후크가 status 를 덮지 않음 (사람 입력 보존) |
| 22 | 자동 종료된 order 의 마지막 회차 완료 취소 (`complete_session` 경로) | `진행중` 으로 복귀 |
| 23 | 호출자가 이미 트랜잭션 안에서 `recomputeOrderStatus()` 직접 호출 (lock 후) | 정상 동작 |
| 24 | 호출자가 이미 트랜잭션 안에서 `withOrderLock()` 호출 시도 | RuntimeException |

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
| 회차 토글 후 즉시 종료 안 되는 race | 트리거 #5(complete_session 후) 추가로 즉시 반영 보장 |
| `withOrderLock()` 을 활성 트랜잭션 안에서 잘못 호출 | 함수가 `inTransaction()` 검사 후 RuntimeException — 사고 즉시 표면화 |
| 운영자가 같은 요청에서 status 를 명시했는데 후크가 덮어씀 | update 페이로드의 `status` 키 존재 시 후크 스킵 |
