# PT 카톡방 입장 체크 — 쿠폰지급/특이건 플래그 추가 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `pt.soritune.com` 카톡방 입장 체크 페이지(어드민/코치)에 "쿠폰 지급" / "특이 건" 체크박스 2개를 추가하고, 세 플래그(`kakao_room_joined`, `coupon_issued`, `special_case`) 중 하나라도 1이면 알림톡 발송에서 제외.

**Architecture:** `orders` 테이블에 새 컬럼 7개(2 플래그 + 메타 4 + 메모 1) 추가. `api/kakao_check.php`에 단일 통합 액션 `toggle_flag` 도입(기존 `toggle_join` 호환 유지). 어댑터(`source_pt_orders_query.php`) WHERE 절에 `coupon_issued=0 AND special_case=0` 상수 추가. 어드민/코치 JS 양쪽에 컬럼 + prompt 기반 메모 UX. `include_joined` 파라미터를 `include_processed`로 일반화(서버는 둘 다 호환).

**Tech Stack:** PHP 8.x + MySQL 8 + 바닐라 JS (Admin/Coach SPA). 테스트는 자체 PHP harness (`tests/run_tests.php`).

**Spec:** `docs/superpowers/specs/2026-05-06-pt-kakao-check-extra-flags-design.md`

---

## File Structure

**Created:**
- `migrations/20260506_orders_add_coupon_special.sql` — 7개 컬럼 추가

**Modified:**
- `public_html/api/kakao_check.php` — `kakaoCheckToggleFlag()` 추가, `list` 쿼리 SELECT 확장, 라우터에 `toggle_flag` 액션 추가, `include_processed` 파라미터 지원
- `public_html/includes/notify/source_pt_orders_query.php` — WHERE 절 2줄 추가
- `public_html/admin/js/pages/kakao-check.js` — 컬럼 2개 추가, 토글/메모 핸들러
- `public_html/coach/js/pages/kakao-check.js` — 컬럼 2개 추가, 토글/메모 핸들러
- `tests/kakao_check_test.php` — toggle_flag 케이스 추가
- `tests/notify_pt_orders_query_test.php` — coupon/special 차단 케이스 추가

---

## Task 1: DB 마이그레이션 작성 + DEV 적용

**Files:**
- Create: `migrations/20260506_orders_add_coupon_special.sql`

- [ ] **Step 1: 마이그 SQL 작성**

`migrations/20260506_orders_add_coupon_special.sql`:

```sql
-- 2026-05-06: orders 테이블에 쿠폰 지급/특이 건 플래그 추가
-- Spec: docs/superpowers/specs/2026-05-06-pt-kakao-check-extra-flags-design.md
--
-- 적용 (DEV):
--   mysql --defaults-file=/root/.my.cnf SORITUNECOM_DEV_PT < migrations/20260506_orders_add_coupon_special.sql
-- 적용 (PROD):
--   mysql --defaults-file=/root/.my.cnf SORITUNECOM_PT < migrations/20260506_orders_add_coupon_special.sql
--
-- 멱등성: ALTER ... ADD COLUMN IF NOT EXISTS (MySQL 8+).
-- 롤백: 수동 (DROP COLUMN). 자동 롤백 스크립트 없음.

SET NAMES utf8mb4;

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `coupon_issued` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `kakao_room_joined_by`,
  ADD COLUMN IF NOT EXISTS `coupon_issued_at` DATETIME DEFAULT NULL
    AFTER `coupon_issued`,
  ADD COLUMN IF NOT EXISTS `coupon_issued_by` INT DEFAULT NULL
    COMMENT 'admin.id 또는 coach.id (actor 구분은 change_logs)'
    AFTER `coupon_issued_at`,
  ADD COLUMN IF NOT EXISTS `special_case` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `coupon_issued_by`,
  ADD COLUMN IF NOT EXISTS `special_case_at` DATETIME DEFAULT NULL
    AFTER `special_case`,
  ADD COLUMN IF NOT EXISTS `special_case_by` INT DEFAULT NULL
    COMMENT 'admin.id 또는 coach.id (actor 구분은 change_logs)'
    AFTER `special_case_at`,
  ADD COLUMN IF NOT EXISTS `special_case_note` VARCHAR(255) DEFAULT NULL
    AFTER `special_case_by`;
```

- [ ] **Step 2: DEV DB에 적용**

Run:
```bash
mysql --defaults-file=/root/.my.cnf SORITUNECOM_DEV_PT < /root/pt-dev/migrations/20260506_orders_add_coupon_special.sql
```

검증:
```bash
mysql --defaults-file=/root/.my.cnf SORITUNECOM_DEV_PT -e "SHOW COLUMNS FROM orders LIKE 'coupon_issued%'"
mysql --defaults-file=/root/.my.cnf SORITUNECOM_DEV_PT -e "SHOW COLUMNS FROM orders LIKE 'special_case%'"
```
Expected: 7개 컬럼 모두 출력 (coupon_issued / coupon_issued_at / coupon_issued_by / special_case / special_case_at / special_case_by / special_case_note)

- [ ] **Step 3: 커밋**

```bash
cd /root/pt-dev
git add migrations/20260506_orders_add_coupon_special.sql
git commit -m "feat(pt): orders에 쿠폰지급/특이건 플래그 컬럼 추가 마이그

- coupon_issued / _at / _by (3컬럼)
- special_case / _at / _by / _note (4컬럼)
- DEV DB 적용 완료. PROD는 운영 반영 시점에.

Spec: docs/superpowers/specs/2026-05-06-pt-kakao-check-extra-flags-design.md"
```

---

## Task 2: `kakaoCheckToggleFlag()` 함수 (TDD)

**Files:**
- Modify: `public_html/api/kakao_check.php` (lib only — 라우터 변경은 Task 4)
- Modify: `tests/kakao_check_test.php`

- [ ] **Step 1: 실패 테스트 작성 — coupon ON**

`tests/kakao_check_test.php` 끝에 추가:

```php
t_section('toggle_flag — coupon ON');

if ($activeCoach === 0 || $adminId === 0) {
    echo "  SKIP  active 코치/admin 없음\n";
} else {
    $db->beginTransaction();
    $o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);

    $changed = kakaoCheckToggleFlag($db, $o, 'coupon', true, null, 'admin', $adminId);
    t_assert_true($changed, 'coupon ON: changed=true');

    $row = $db->query("SELECT coupon_issued, coupon_issued_at, coupon_issued_by FROM orders WHERE id={$o}")->fetch();
    t_assert_eq(1, (int)$row['coupon_issued'], 'coupon_issued=1');
    t_assert_true($row['coupon_issued_at'] !== null, 'coupon_issued_at NOT NULL');
    t_assert_eq($adminId, (int)$row['coupon_issued_by'], 'coupon_issued_by = adminId');

    $log = $db->query("SELECT action, actor_type FROM change_logs WHERE target_type='order' AND target_id={$o} ORDER BY id DESC LIMIT 1")->fetch();
    t_assert_eq('coupon_issued_set', $log['action'], 'log action = coupon_issued_set');
    t_assert_eq('admin', $log['actor_type'], 'log actor_type=admin');

    $db->rollBack();
}
```

- [ ] **Step 2: 테스트 실행해 실패 확인**

Run: `php /root/pt-dev/tests/run_tests.php 2>&1 | grep -A2 "toggle_flag"`
Expected: FAIL — `Call to undefined function kakaoCheckToggleFlag()` 또는 유사 에러.

- [ ] **Step 3: 실패 테스트 추가 — coupon OFF (idempotent)**

```php
t_section('toggle_flag — coupon OFF + idempotent');

if ($activeCoach === 0 || $adminId === 0) {
    echo "  SKIP\n";
} else {
    $db->beginTransaction();
    $o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);
    $db->prepare("UPDATE orders SET coupon_issued=1, coupon_issued_at=NOW(), coupon_issued_by=999 WHERE id=?")->execute([$o]);

    $changed = kakaoCheckToggleFlag($db, $o, 'coupon', false, null, 'admin', $adminId);
    t_assert_true($changed, 'OFF: 값 바뀜');
    $row = $db->query("SELECT coupon_issued, coupon_issued_at, coupon_issued_by FROM orders WHERE id={$o}")->fetch();
    t_assert_eq(0, (int)$row['coupon_issued'], 'coupon_issued=0');
    t_assert_true($row['coupon_issued_at'] === null, '_at = NULL');
    t_assert_true($row['coupon_issued_by'] === null, '_by = NULL');

    $logCountBefore = (int)$db->query("SELECT COUNT(*) FROM change_logs WHERE target_type='order' AND target_id={$o}")->fetchColumn();
    $changed2 = kakaoCheckToggleFlag($db, $o, 'coupon', false, null, 'admin', $adminId);
    t_assert_eq(false, $changed2, 'idempotent no-op');
    $logCountAfter = (int)$db->query("SELECT COUNT(*) FROM change_logs WHERE target_type='order' AND target_id={$o}")->fetchColumn();
    t_assert_eq($logCountBefore, $logCountAfter, 'log 추가 없음');

    $db->rollBack();
}
```

- [ ] **Step 4: 실패 테스트 추가 — special with note**

```php
t_section('toggle_flag — special ON with note');

if ($activeCoach === 0 || $adminId === 0) {
    echo "  SKIP\n";
} else {
    $db->beginTransaction();
    $o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);

    $changed = kakaoCheckToggleFlag($db, $o, 'special', true, '문의함 — 다음달 진행 예정', 'admin', $adminId);
    t_assert_true($changed, 'special ON');

    $row = $db->query("SELECT special_case, special_case_note, special_case_by FROM orders WHERE id={$o}")->fetch();
    t_assert_eq(1, (int)$row['special_case'], 'special_case=1');
    t_assert_eq('문의함 — 다음달 진행 예정', $row['special_case_note'], 'note 저장');
    t_assert_eq($adminId, (int)$row['special_case_by'], '_by');

    $log = $db->query("SELECT action FROM change_logs WHERE target_type='order' AND target_id={$o} ORDER BY id DESC LIMIT 1")->fetch();
    t_assert_eq('special_case_set', $log['action'], 'log action = special_case_set');

    $db->rollBack();
}
```

- [ ] **Step 5: 실패 테스트 추가 — special 빈 메모 → NULL**

```php
t_section('toggle_flag — special ON with empty note → NULL');

if ($activeCoach === 0 || $adminId === 0) {
    echo "  SKIP\n";
} else {
    $db->beginTransaction();
    $o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);

    kakaoCheckToggleFlag($db, $o, 'special', true, '', 'admin', $adminId);
    $note = $db->query("SELECT special_case_note FROM orders WHERE id={$o}")->fetchColumn();
    t_assert_true($note === null, '빈 문자열 → NULL');

    $db->rollBack();
}

t_section('toggle_flag — special OFF resets note');

if ($activeCoach === 0 || $adminId === 0) {
    echo "  SKIP\n";
} else {
    $db->beginTransaction();
    $o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);
    $db->prepare("UPDATE orders SET special_case=1, special_case_at=NOW(), special_case_by=999, special_case_note='기존 메모' WHERE id=?")->execute([$o]);

    kakaoCheckToggleFlag($db, $o, 'special', false, null, 'admin', $adminId);
    $row = $db->query("SELECT special_case, special_case_at, special_case_by, special_case_note FROM orders WHERE id={$o}")->fetch();
    t_assert_eq(0, (int)$row['special_case'], 'special_case=0');
    t_assert_true($row['special_case_at'] === null, '_at NULL');
    t_assert_true($row['special_case_by'] === null, '_by NULL');
    t_assert_true($row['special_case_note'] === null, '_note NULL');

    $db->rollBack();
}
```

- [ ] **Step 6: 실패 테스트 추가 — flag='kakao' 위임**

```php
t_section('toggle_flag — flag=kakao 위임');

if ($activeCoach === 0 || $adminId === 0) {
    echo "  SKIP\n";
} else {
    $db->beginTransaction();
    $o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);

    $changed = kakaoCheckToggleFlag($db, $o, 'kakao', true, null, 'admin', $adminId);
    t_assert_true($changed, 'kakao ON');

    $row = $db->query("SELECT kakao_room_joined FROM orders WHERE id={$o}")->fetch();
    t_assert_eq(1, (int)$row['kakao_room_joined'], 'kakao_room_joined=1');

    $log = $db->query("SELECT action FROM change_logs WHERE target_type='order' AND target_id={$o} ORDER BY id DESC LIMIT 1")->fetch();
    t_assert_eq('kakao_room_join', $log['action'], 'log action = kakao_room_join (기존 그대로)');

    $db->rollBack();
}

t_section('toggle_flag — invalid flag throws');

t_assert_throws(function() use ($db, $adminId) {
    kakaoCheckToggleFlag($db, 1, 'unknown', true, null, 'admin', $adminId);
}, 'InvalidArgumentException', 'flag 값 검증');
```

- [ ] **Step 7: 모든 테스트 실행해 실패 확인**

Run: `php /root/pt-dev/tests/run_tests.php 2>&1 | tail -30`
Expected: 새로 추가한 toggle_flag 섹션 모두 FAIL.

- [ ] **Step 8: `kakaoCheckToggleFlag()` 함수 구현**

`public_html/api/kakao_check.php`에서 `kakaoCheckToggle()` 함수 **바로 아래**에 추가 (기존 함수는 그대로 둠):

```php
/**
 * 통합 flag 토글. flag별로 컬럼 매핑.
 *
 * @param string      $flag       'kakao' | 'coupon' | 'special'
 * @param bool        $value      true=ON / false=OFF
 * @param string|null $note       flag='special' && value=true 일 때만 사용. 빈 문자열은 NULL로 저장.
 * @param string      $actorType  'admin' | 'coach'
 * @param int         $actorId    user.id
 * @return bool  실제로 값이 바뀌었는지 (false면 no-op)
 */
function kakaoCheckToggleFlag(PDO $db, int $orderId, string $flag, bool $value, ?string $note, string $actorType, int $actorId): bool
{
    if ($flag === 'kakao') {
        return kakaoCheckToggle($db, $orderId, $value, $actorType, $actorId);
    }
    if ($flag !== 'coupon' && $flag !== 'special') {
        throw new InvalidArgumentException("flag must be 'kakao' | 'coupon' | 'special', got '{$flag}'");
    }

    $colVal = $flag === 'coupon' ? 'coupon_issued'    : 'special_case';
    $colAt  = $flag === 'coupon' ? 'coupon_issued_at' : 'special_case_at';
    $colBy  = $flag === 'coupon' ? 'coupon_issued_by' : 'special_case_by';
    $hasNote = ($flag === 'special');
    $colNote = 'special_case_note';

    $selCols = $hasNote ? "{$colVal}, {$colNote}" : $colVal;
    $current = $db->prepare("SELECT {$selCols} FROM orders WHERE id = ?");
    $current->execute([$orderId]);
    $row = $current->fetch();
    if (!$row) return false;

    $oldVal  = (int)$row[$colVal];
    $oldNote = $hasNote ? ($row[$colNote] ?? null) : null;
    $newVal  = $value ? 1 : 0;
    $newNote = null;
    if ($hasNote && $value) {
        $note = $note !== null ? trim($note) : '';
        $newNote = $note === '' ? null : $note;
    }

    // no-op: 값 동일 + (special의 경우) note도 동일
    if ($oldVal === $newVal && (!$hasNote || $oldNote === $newNote)) {
        return false;
    }

    if ($value) {
        if ($hasNote) {
            $db->prepare("UPDATE orders SET {$colVal}=1, {$colAt}=NOW(), {$colBy}=?, {$colNote}=? WHERE id=?")
               ->execute([$actorId, $newNote, $orderId]);
        } else {
            $db->prepare("UPDATE orders SET {$colVal}=1, {$colAt}=NOW(), {$colBy}=? WHERE id=?")
               ->execute([$actorId, $orderId]);
        }
        $action = "{$flag}_" . ($flag === 'coupon' ? 'issued_set' : 'case_set');
    } else {
        if ($hasNote) {
            $db->prepare("UPDATE orders SET {$colVal}=0, {$colAt}=NULL, {$colBy}=NULL, {$colNote}=NULL WHERE id=?")
               ->execute([$orderId]);
        } else {
            $db->prepare("UPDATE orders SET {$colVal}=0, {$colAt}=NULL, {$colBy}=NULL WHERE id=?")
               ->execute([$orderId]);
        }
        $action = "{$flag}_" . ($flag === 'coupon' ? 'issued_unset' : 'case_unset');
    }

    $oldLog = [$colVal => $oldVal];
    $newLog = [$colVal => $newVal];
    if ($hasNote) {
        $oldLog[$colNote] = $oldNote;
        $newLog[$colNote] = $newNote;
    }
    logChange($db, 'order', $orderId, $action, $oldLog, $newLog, $actorType, $actorId);
    return true;
}
```

- [ ] **Step 9: 테스트 실행해 통과 확인**

Run: `php /root/pt-dev/tests/run_tests.php 2>&1 | tail -10`
Expected: PASS — 모든 toggle_flag 섹션 통과. 기존 `toggle_join` 섹션도 그대로 통과.

- [ ] **Step 10: 커밋**

```bash
cd /root/pt-dev
git add public_html/api/kakao_check.php tests/kakao_check_test.php
git commit -m "feat(pt): kakaoCheckToggleFlag() 통합 함수 + 테스트

flag in {'kakao','coupon','special'} 단일 함수로 토글. special일 때만
note 처리(빈 문자열 → NULL). 기존 kakaoCheckToggle()은 호환 위해 유지.
change_logs action: coupon_issued_set/unset, special_case_set/unset.

Spec: docs/superpowers/specs/2026-05-06-pt-kakao-check-extra-flags-design.md"
```

---

## Task 3: `list` 액션 SELECT 확장 + `include_processed` 파라미터

**Files:**
- Modify: `public_html/api/kakao_check.php` (`kakaoCheckList()` 함수)
- Modify: `tests/kakao_check_test.php`

- [ ] **Step 1: 실패 테스트 작성 — list가 새 컬럼 반환**

`tests/kakao_check_test.php` 끝에 추가:

```php
t_section('list — coupon/special 컬럼 노출');

if ($activeCoach === 0) {
    echo "  SKIP\n";
} else {
    $db->beginTransaction();
    $o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);
    $db->prepare("UPDATE orders SET coupon_issued=1, special_case=1, special_case_note='메모테스트' WHERE id=?")->execute([$o]);

    $result = kakaoCheckList($db, [
        'cohort' => '2026-04',
        'coach_id' => $activeCoach,
        'include_processed' => true,
        'product' => null,
    ]);
    $found = null;
    foreach ($result['orders'] as $r) {
        if ((int)$r['order_id'] === $o) { $found = $r; break; }
    }
    t_assert_true($found !== null, 'order 찾음');
    t_assert_eq(1, (int)$found['coupon_issued'], 'coupon_issued 노출');
    t_assert_eq(1, (int)$found['special_case'], 'special_case 노출');
    t_assert_eq('메모테스트', $found['special_case_note'], 'note 노출');

    $db->rollBack();
}

t_section('list — include_processed=false: 세 플래그 모두 0인 행만');

if ($activeCoach === 0) {
    echo "  SKIP\n";
} else {
    $db->beginTransaction();
    $oClean = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);
    $oKakao = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-11', 'end_date' => '2026-07-10']);
    $oCoup  = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-12', 'end_date' => '2026-07-11']);
    $oSpec  = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-13', 'end_date' => '2026-07-12']);
    $db->prepare("UPDATE orders SET kakao_room_joined=1 WHERE id=?")->execute([$oKakao]);
    $db->prepare("UPDATE orders SET coupon_issued=1 WHERE id=?")->execute([$oCoup]);
    $db->prepare("UPDATE orders SET special_case=1 WHERE id=?")->execute([$oSpec]);

    $result = kakaoCheckList($db, [
        'cohort' => '2026-04',
        'coach_id' => $activeCoach,
        'include_processed' => false,
        'product' => null,
    ]);
    $ids = array_map(fn($r) => (int)$r['order_id'], $result['orders']);
    t_assert_true(in_array($oClean, $ids, true), 'clean: 포함');
    t_assert_true(!in_array($oKakao, $ids, true), 'kakao=1: 제외');
    t_assert_true(!in_array($oCoup, $ids, true), 'coupon=1: 제외');
    t_assert_true(!in_array($oSpec, $ids, true), 'special=1: 제외');

    $db->rollBack();
}

t_section('list — include_joined alias 호환 (기존 호출자 보호)');

if ($activeCoach === 0) {
    echo "  SKIP\n";
} else {
    $db->beginTransaction();
    $o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);
    $db->prepare("UPDATE orders SET kakao_room_joined=1 WHERE id=?")->execute([$o]);

    // 기존 호출 (include_joined=true) → 여전히 포함
    $r = kakaoCheckList($db, ['cohort' => '2026-04', 'coach_id' => $activeCoach, 'include_joined' => true, 'product' => null]);
    $ids = array_map(fn($x) => (int)$x['order_id'], $r['orders']);
    t_assert_true(in_array($o, $ids, true), 'include_joined=true alias 동작');

    $db->rollBack();
}
```

- [ ] **Step 2: 테스트 실행해 실패 확인**

Run: `php /root/pt-dev/tests/run_tests.php 2>&1 | grep -E "FAIL|coupon/special|include_processed" | head -10`
Expected: 새 섹션 FAIL.

- [ ] **Step 3: `kakaoCheckList()` 수정**

`public_html/api/kakao_check.php`의 `kakaoCheckList()` 함수 본문 수정.

수정 1 — 옵션 해석 (함수 상단):

```php
$cohort = $opts['cohort'];
$coachId = $opts['coach_id'] ?? null;
// include_processed (신) 우선, 없으면 include_joined (구) fallback
$includeProcessed = array_key_exists('include_processed', $opts)
    ? !empty($opts['include_processed'])
    : !empty($opts['include_joined']);
$product = $opts['product'] ?? null;
```

수정 2 — `$pWhere` 안의 미체크 필터 (기존 `o.kakao_room_joined = 0` 한 줄을 OR-equiv로):

```php
if (!$includeProcessed) {
    $pWhere[] = "o.kakao_room_joined = 0 AND o.coupon_issued = 0 AND o.special_case = 0";
}
```

수정 3 — `$oSql` SELECT 컬럼 확장. `o.kakao_room_joined_at,` 다음 줄에 3개 추가:

```php
$oSql = "
    SELECT
      o.id AS order_id,
      o.member_id,
      m.name,
      m.phone,
      m.email,
      o.product_name,
      o.start_date,
      o.end_date,
      o.status,
      CASE WHEN o.status = '매칭완료' THEN '진행예정' ELSE o.status END AS display_status,
      o.cohort_month AS cohort_month_override,
      COALESCE(o.cohort_month, DATE_FORMAT(o.start_date, '%Y-%m')) AS effective_cohort,
      o.kakao_room_joined,
      o.kakao_room_joined_at,
      o.coupon_issued,
      o.special_case,
      o.special_case_note,
      o.coach_id,
      c.coach_name
    FROM orders o
    JOIN members m ON m.id = o.member_id
    LEFT JOIN coaches c ON c.id = o.coach_id
    WHERE " . implode(' AND ', $oWhere) . "
    ORDER BY o.start_date ASC, m.name ASC
";
```

- [ ] **Step 4: 테스트 실행해 통과 확인**

Run: `php /root/pt-dev/tests/run_tests.php 2>&1 | tail -10`
Expected: 새 섹션 모두 PASS. 기존 list 섹션도 그대로 PASS (include_joined alias 덕분).

- [ ] **Step 5: 커밋**

```bash
cd /root/pt-dev
git add public_html/api/kakao_check.php tests/kakao_check_test.php
git commit -m "feat(pt): list 액션 — coupon/special 컬럼 노출, include_processed 일반화

- SELECT에 coupon_issued/special_case/special_case_note 추가
- 미체크 필터: 세 플래그 모두 0인 행만 (OR 차단)
- include_processed (신) 우선, include_joined (구)는 fallback alias로 유지

Spec: docs/superpowers/specs/2026-05-06-pt-kakao-check-extra-flags-design.md"
```

---

## Task 4: 라우터에 `toggle_flag` 액션 추가 + `include_processed` 파라미터

**Files:**
- Modify: `public_html/api/kakao_check.php` (라우터 switch)

- [ ] **Step 1: `list` 액션의 옵션 빌드 수정**

`public_html/api/kakao_check.php`의 라우터 `case 'list':` 블록에서 `kakaoCheckList()` 호출 인자 변경:

```php
case 'list':
    $cohort = trim($_GET['cohort'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $cohort)) {
        jsonError('cohort 파라미터가 필요합니다 (YYYY-MM)');
    }
    $coachId = null;
    if ($user['role'] === 'coach') {
        $coachId = (int)$user['id'];
    } elseif ($user['role'] === 'admin' && !empty($_GET['coach_id'])) {
        $coachId = (int)$_GET['coach_id'];
    }
    // include_processed (신) 우선, include_joined (구) fallback
    $rawProcessed = $_GET['include_processed'] ?? $_GET['include_joined'] ?? '';
    $includeProcessed = $rawProcessed !== '' && $rawProcessed !== '0';
    $result = kakaoCheckList($db, [
        'cohort' => $cohort,
        'coach_id' => $coachId,
        'include_processed' => $includeProcessed,
        'product' => trim($_GET['product'] ?? '') ?: null,
    ]);
    jsonSuccess($result);
```

- [ ] **Step 2: `toggle_flag` 라우터 액션 추가**

라우터 switch에서 `case 'toggle_join':` **바로 아래**에 추가 (기존 `toggle_join`은 그대로):

```php
case 'toggle_flag':
    $input = getJsonInput();
    $orderId = (int)($input['order_id'] ?? 0);
    $flag    = (string)($input['flag'] ?? '');
    $value   = !empty($input['value']);
    $note    = array_key_exists('note', $input) ? (string)$input['note'] : null;
    if (!$orderId) jsonError('order_id가 필요합니다');
    if (!in_array($flag, ['kakao', 'coupon', 'special'], true)) {
        jsonError("flag는 'kakao'|'coupon'|'special' 중 하나여야 합니다");
    }

    // 권한: order 존재 확인 + coach는 자기 order만
    $stmt = $db->prepare("SELECT coach_id FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    if (!$row) jsonError('order를 찾을 수 없습니다', 404);
    if ($user['role'] === 'coach' && (int)$row['coach_id'] !== (int)$user['id']) {
        jsonError('권한이 없습니다', 403);
    }

    try {
        $changed = kakaoCheckToggleFlag($db, $orderId, $flag, $value, $note, $user['role'], (int)$user['id']);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage());
    }
    jsonSuccess(['flag' => $flag, 'value' => $value ? 1 : 0, 'changed' => $changed]);
```

- [ ] **Step 3: 라우터 sanity check (수동)**

수동으로 dev-pt에 로그인 후 브라우저 콘솔에서:

```javascript
// 카톡방 입장 체크 페이지에서 (어드민 / 코치)
const r = await API.post('/api/kakao_check.php?action=toggle_flag', {
  order_id: 123, flag: 'coupon', value: 1
});
console.log(r);
```

또는 `curl`로 직접 호출 — 자세한 검증은 UI 작업 후 통합으로 충분. 지금은 PHP 문법 에러만 확인:

```bash
php -l /root/pt-dev/public_html/api/kakao_check.php
```
Expected: `No syntax errors detected`

- [ ] **Step 4: 전체 테스트 재실행 (회귀 확인)**

Run: `php /root/pt-dev/tests/run_tests.php 2>&1 | tail -5`
Expected: 모든 섹션 PASS (라이브러리 함수는 변경 없음, 라우터만 추가).

- [ ] **Step 5: 커밋**

```bash
cd /root/pt-dev
git add public_html/api/kakao_check.php
git commit -m "feat(pt): kakao_check 라우터에 toggle_flag 액션 + include_processed

- POST toggle_flag {order_id, flag, value, note?}
- 권한: coach는 자기 coach_id order만 (toggle_join과 동일 규칙)
- list 액션 include_processed 우선, include_joined fallback

Spec: docs/superpowers/specs/2026-05-06-pt-kakao-check-extra-flags-design.md"
```

---

## Task 5: 알림톡 어댑터 차단 (TDD)

**Files:**
- Modify: `public_html/includes/notify/source_pt_orders_query.php`
- Modify: `tests/notify_pt_orders_query_test.php`

- [ ] **Step 1: 실패 테스트 작성 — coupon/special 차단**

`tests/notify_pt_orders_query_test.php`의 기존 시드 블록 (try 블록 안, `$insOrder($m9, ...)` 두 줄 다음, `$cfg = [...]` 바로 위)에 회원 2명 + order 2건 추가:

```php
    $m10 = $insMember('__t_m10__', '01045454545');  // coupon_issued=1 → 미포함
    $m11 = $insMember('__t_m11__', '01056565656');  // special_case=1 → 미포함

    $oid10 = $insOrder($m10, $coachOK, '소리튜닝 음성PT', '진행중', 0, '2026-05');
    $oid11 = $insOrder($m11, $coachOK, '소리튜닝 음성PT', '진행중', 0, '2026-05');
    $db->prepare("UPDATE orders SET coupon_issued=1 WHERE id=?")->execute([$oid10]);
    $db->prepare("UPDATE orders SET special_case=1 WHERE id=?")->execute([$oid11]);
```

그리고 케이스 9 다음 (`$m9rows = ...` 블록 다음)에 새 케이스 추가:

```php
    // 13) coupon_issued=1 → 미포함
    t_assert_true(!isset($byM[$m10]), '13) coupon_issued=1 미포함');

    // 14) special_case=1 → 미포함
    t_assert_true(!isset($byM[$m11]), '14) special_case=1 미포함');
```

- [ ] **Step 2: 테스트 실행해 실패 확인**

Run: `php /root/pt-dev/tests/run_tests.php 2>&1 | grep -E "FAIL.*1[34]\)"`
Expected: `FAIL  13) coupon_issued=1 미포함`, `FAIL  14) special_case=1 미포함`.

- [ ] **Step 3: 어댑터 WHERE 절 수정**

`public_html/includes/notify/source_pt_orders_query.php`의 SQL 변경. 기존 WHERE:

```sql
WHERE o.product_name      = ?
  AND o.kakao_room_joined = ?
  AND o.cohort_month      = ?
  AND o.status IN ({$statusPlaceholders})
```

다음으로 변경:

```sql
WHERE o.product_name      = ?
  AND o.kakao_room_joined = ?
  AND o.coupon_issued     = 0
  AND o.special_case      = 0
  AND o.cohort_month      = ?
  AND o.status IN ({$statusPlaceholders})
```

(`coupon_issued=0`, `special_case=0`은 cfg에서 받지 않고 상수.)

- [ ] **Step 4: 테스트 실행해 통과 확인**

Run: `php /root/pt-dev/tests/run_tests.php 2>&1 | grep -E "1[34]\)"`
Expected: `PASS  13) coupon_issued=1 미포함`, `PASS  14) special_case=1 미포함`. 1~12 케이스도 그대로 PASS.

- [ ] **Step 5: 커밋**

```bash
cd /root/pt-dev
git add public_html/includes/notify/source_pt_orders_query.php tests/notify_pt_orders_query_test.php
git commit -m "feat(pt): 알림톡 어댑터에 coupon_issued/special_case 차단

source_pt_orders_query WHERE 절에 두 플래그 = 0 상수 조건 추가.
세 플래그 중 하나라도 1이면 알림톡 발송 대상에서 제외.

Spec: docs/superpowers/specs/2026-05-06-pt-kakao-check-extra-flags-design.md"
```

---

## Task 6: 어드민 UI — 컬럼 추가, 토글, 메모 prompt

**Files:**
- Modify: `public_html/admin/js/pages/kakao-check.js`

- [ ] **Step 1: `includeJoined` → `includeProcessed` 리네임 + 라벨 변경**

`public_html/admin/js/pages/kakao-check.js`에서 모든 `includeJoined` 출현을 `includeProcessed`로 일괄 치환 (변수, 메서드 `toggleIncludeJoined` → `toggleIncludeProcessed`).

`renderFilters()` 안의 라벨 변경:

```javascript
<input type="checkbox" ${this.includeProcessed ? 'checked' : ''}
       onchange="App.pages['kakao-check'].toggleIncludeProcessed(this.checked)">
처리 완료도 보기
```

`loadList()` 안의 URL 파라미터:

```javascript
const params = new URLSearchParams({
  action: 'list',
  cohort: this.selectedCohort,
  include_processed: this.includeProcessed ? '1' : '0',
});
```

`toggleJoin()` 메서드 안에서도 `includeJoined` → `includeProcessed`. (체크 해제 후 fade-out 로직.)

- [ ] **Step 2: 표 헤더에 컬럼 2개 추가**

`loadList()` 안의 `<thead>` 부분 변경:

```javascript
<thead>
  <tr>
    <th style="width:32px"><input type="checkbox" id="kakaoSelectAll"
        onclick="App.pages['kakao-check'].toggleSelectAll(this.checked)"></th>
    <th style="width:32px" title="카톡방 입장">입장</th>
    <th style="width:32px" title="쿠폰 지급">쿠폰</th>
    <th style="width:60px" title="특이 건">특이</th>
    <th>이름</th>
    <th>전화번호</th>
    <th>이메일</th>
    <th>상품</th>
    <th>코치</th>
    <th>시작일</th>
    <th>상태</th>
    <th>코호트</th>
  </tr>
</thead>
```

- [ ] **Step 3: `_row()` 변경 — 두 체크박스 + 메모 표시 + dim 조건**

기존 `_row()` 전체를 다음으로 교체:

```javascript
_row(o) {
  const kakaoOn  = parseInt(o.kakao_room_joined, 10) === 1;
  const couponOn = parseInt(o.coupon_issued, 10) === 1;
  const specialOn = parseInt(o.special_case, 10) === 1;
  const dim = kakaoOn || couponOn || specialOn;
  const selected = this.selectedOrderIds.has(o.order_id);
  const overrideMark = o.cohort_month_override ? ' <span style="color:#888;font-size:11px;">(override)</span>' : '';

  const note = (o.special_case_note || '').trim();
  const noteShort = note.length > 16 ? note.slice(0, 16) + '…' : note;
  const noteHtml = specialOn
    ? `<small style="display:block; color:#888; cursor:pointer; font-size:11px;"
              title="${UI.esc(note)}"
              onclick="App.pages['kakao-check'].editSpecialNote(${o.order_id})">${UI.esc(noteShort) || '메모 없음'}</small>`
    : '';

  return `
    <tr id="kakao-row-${o.order_id}" style="${dim ? 'opacity:0.55' : ''}">
      <td><input type="checkbox" ${selected ? 'checked' : ''}
                 onclick="App.pages['kakao-check'].toggleSelect(${o.order_id}, this.checked)"></td>
      <td><input type="checkbox" ${kakaoOn ? 'checked' : ''}
                 onclick="App.pages['kakao-check'].toggleFlag(${o.order_id}, 'kakao', this.checked)"></td>
      <td><input type="checkbox" ${couponOn ? 'checked' : ''}
                 onclick="App.pages['kakao-check'].toggleFlag(${o.order_id}, 'coupon', this.checked)"></td>
      <td>
        <input type="checkbox" ${specialOn ? 'checked' : ''}
               onclick="App.pages['kakao-check'].toggleFlag(${o.order_id}, 'special', this.checked)">
        ${noteHtml}
      </td>
      <td>${UI.esc(o.name)}</td>
      <td style="color:var(--text-secondary)">${UI.esc(o.phone) || '-'}</td>
      <td style="color:var(--text-secondary)">${UI.esc(o.email) || '-'}</td>
      <td>${UI.esc(o.product_name)}</td>
      <td>${UI.esc(o.coach_name) || '-'}</td>
      <td>${UI.formatDate(o.start_date)}</td>
      <td>${UI.statusBadge(o.display_status)}</td>
      <td>${UI.esc(o.effective_cohort)}${overrideMark}</td>
    </tr>
  `;
},
```

- [ ] **Step 4: `toggleFlag()` 메서드 추가 (`toggleJoin` 대체) + `editSpecialNote()` 추가**

기존 `toggleJoin()` 메서드 **바로 아래**에 추가 (toggleJoin은 호환을 위해 남겨도 되지만 호출자가 없어지므로 함께 제거 가능 — 본 plan은 안전하게 toggleJoin은 그대로 남기고 새 핸들러로 대체):

```javascript
async toggleFlag(orderId, flag, checked) {
  const row = document.getElementById(`kakao-row-${orderId}`);
  const checkbox = row?.querySelector(`td input[type=checkbox][onclick*="'${flag}'"]`);

  let note = null;
  if (flag === 'special' && checked) {
    note = prompt('특이 사유를 입력하세요 (없으면 비워두세요)', '');
    if (note === null) {
      // Cancel → 토글 취소
      if (checkbox) checkbox.checked = false;
      return;
    }
  }

  const res = await API.post('/api/kakao_check.php?action=toggle_flag', {
    order_id: orderId, flag, value: checked ? 1 : 0, note,
  });
  if (!res.ok) {
    alert(res.message || '실패');
    if (checkbox) checkbox.checked = !checked;
    return;
  }

  // include_processed=false에서 새로 처리됨 → 행 제거 (셋 다 0이었던 행이 1로 바뀐 경우)
  if (!this.includeProcessed && checked) {
    if (row) {
      row.style.transition = 'opacity 0.3s';
      row.style.opacity = '0';
      setTimeout(() => this.loadList(), 320);
    }
  } else {
    // 다른 플래그 상태에 따라 dim 갱신을 위해 리로드
    this.loadList();
  }
},

async editSpecialNote(orderId) {
  const row = document.getElementById(`kakao-row-${orderId}`);
  const small = row?.querySelector('td:nth-child(4) small');
  const currentNote = small?.getAttribute('title') || '';
  const note = prompt('특이 사유를 입력하세요 (없으면 비워두세요)', currentNote);
  if (note === null) return; // Cancel

  const res = await API.post('/api/kakao_check.php?action=toggle_flag', {
    order_id: orderId, flag: 'special', value: 1, note,
  });
  if (!res.ok) { alert(res.message || '실패'); return; }
  this.loadList();
},
```

- [ ] **Step 5: 브라우저에서 수동 검증**

Run (DEV 사이트):

브라우저로 `https://dev-pt.soritune.com/admin/#kakao-check` 접속. 다음 시나리오 직접 확인:

1. 표에 "입장 / 쿠폰 / 특이" 컬럼이 뜨는지
2. "쿠폰" 체크 → 행이 dim(0.55)되고 "처리 완료도 보기" OFF면 사라짐
3. "특이" 체크 → prompt 떠서 사유 입력 → 저장. 행 아래 메모 ellipsis로 표시
4. 메모 클릭 → prompt에 기존값 채워진 채로 재편집 가능
5. 특이 체크 해제 → 메모도 사라짐
6. "처리 완료도 보기" ON → 처리 완료된 행도 함께 보임 (dim 상태)

Expected: 모두 OK. 하나라도 안 되면 콘솔 에러 확인 후 fix.

- [ ] **Step 6: 커밋**

```bash
cd /root/pt-dev
git add public_html/admin/js/pages/kakao-check.js
git commit -m "feat(pt-admin): 카톡방 입장 체크에 쿠폰/특이 컬럼 추가

- 표 헤더 12열로 확장 (선택/입장/쿠폰/특이/...)
- toggleFlag(orderId, flag, checked) 통합 핸들러
- 특이 체크 시 prompt로 메모 입력 (Cancel=토글 취소, 빈 문자열=메모 NULL)
- editSpecialNote(orderId) — 메모 클릭 시 재편집
- includeJoined → includeProcessed 리네임 + '처리 완료도 보기' 라벨

Spec: docs/superpowers/specs/2026-05-06-pt-kakao-check-extra-flags-design.md"
```

---

## Task 7: 코치 UI — 컬럼 추가, 토글, 메모 prompt

**Files:**
- Modify: `public_html/coach/js/pages/kakao-check.js`

- [ ] **Step 1: `includeJoined` → `includeProcessed` + 라벨 변경**

`public_html/coach/js/pages/kakao-check.js`에서 모든 `includeJoined` → `includeProcessed`. `toggleIncludeJoined` → `toggleIncludeProcessed`.

라벨: `체크 완료도 보기` → `처리 완료도 보기`.

`loadList()`의 URL 파라미터: `include_joined` → `include_processed`.

`toggleJoin()` 안에서도 `includeJoined` → `includeProcessed`.

- [ ] **Step 2: 표 헤더에 컬럼 2개 추가**

`loadList()` 안의 `<thead>` 변경:

```javascript
<thead>
  <tr>
    <th style="width:32px" title="카톡방 입장">입장</th>
    <th style="width:32px" title="쿠폰 지급">쿠폰</th>
    <th style="width:60px" title="특이 건">특이</th>
    <th>이름</th>
    <th>전화번호</th>
    <th>이메일</th>
    <th>상품</th>
    <th>시작일</th>
    <th>상태</th>
  </tr>
</thead>
```

- [ ] **Step 3: `_row()` 교체**

```javascript
_row(o) {
  const kakaoOn = parseInt(o.kakao_room_joined, 10) === 1;
  const couponOn = parseInt(o.coupon_issued, 10) === 1;
  const specialOn = parseInt(o.special_case, 10) === 1;
  const dim = kakaoOn || couponOn || specialOn;

  const note = (o.special_case_note || '').trim();
  const noteShort = note.length > 16 ? note.slice(0, 16) + '…' : note;
  const noteHtml = specialOn
    ? `<small style="display:block; color:#888; cursor:pointer; font-size:11px;"
              title="${UI.esc(note)}"
              onclick="CoachApp.pages['kakao-check'].editSpecialNote(${o.order_id})">${UI.esc(noteShort) || '메모 없음'}</small>`
    : '';

  return `
    <tr id="kakao-row-${o.order_id}" style="${dim ? 'opacity:0.55' : ''}">
      <td><input type="checkbox" ${kakaoOn ? 'checked' : ''}
                 onclick="CoachApp.pages['kakao-check'].toggleFlag(${o.order_id}, 'kakao', this.checked)"></td>
      <td><input type="checkbox" ${couponOn ? 'checked' : ''}
                 onclick="CoachApp.pages['kakao-check'].toggleFlag(${o.order_id}, 'coupon', this.checked)"></td>
      <td>
        <input type="checkbox" ${specialOn ? 'checked' : ''}
               onclick="CoachApp.pages['kakao-check'].toggleFlag(${o.order_id}, 'special', this.checked)">
        ${noteHtml}
      </td>
      <td>${UI.esc(o.name)}</td>
      <td style="color:var(--text-secondary)">${UI.esc(o.phone) || '-'}</td>
      <td style="color:var(--text-secondary)">${UI.esc(o.email) || '-'}</td>
      <td>${UI.esc(o.product_name)}</td>
      <td>${UI.formatDate(o.start_date)}</td>
      <td>${UI.statusBadge(o.display_status)}</td>
    </tr>
  `;
},
```

- [ ] **Step 4: `toggleFlag()` + `editSpecialNote()` 추가 (코치 버전)**

기존 `toggleJoin()` 바로 아래에 추가:

```javascript
async toggleFlag(orderId, flag, checked) {
  const row = document.getElementById(`kakao-row-${orderId}`);
  const checkbox = row?.querySelector(`td input[type=checkbox][onclick*="'${flag}'"]`);

  let note = null;
  if (flag === 'special' && checked) {
    note = prompt('특이 사유를 입력하세요 (없으면 비워두세요)', '');
    if (note === null) {
      if (checkbox) checkbox.checked = false;
      return;
    }
  }

  const res = await API.post('/api/kakao_check.php?action=toggle_flag', {
    order_id: orderId, flag, value: checked ? 1 : 0, note,
  });
  if (!res.ok) {
    alert(res.message || '실패');
    if (checkbox) checkbox.checked = !checked;
    return;
  }

  if (!this.includeProcessed && checked) {
    if (row) {
      row.style.transition = 'opacity 0.3s';
      row.style.opacity = '0';
      setTimeout(() => this.loadList(), 320);
    }
  } else {
    this.loadList();
  }
},

async editSpecialNote(orderId) {
  const row = document.getElementById(`kakao-row-${orderId}`);
  const small = row?.querySelector('td:nth-child(3) small');
  const currentNote = small?.getAttribute('title') || '';
  const note = prompt('특이 사유를 입력하세요 (없으면 비워두세요)', currentNote);
  if (note === null) return;

  const res = await API.post('/api/kakao_check.php?action=toggle_flag', {
    order_id: orderId, flag: 'special', value: 1, note,
  });
  if (!res.ok) { alert(res.message || '실패'); return; }
  this.loadList();
},
```

- [ ] **Step 5: 브라우저에서 수동 검증**

코치 계정으로 `https://dev-pt.soritune.com/coach/#kakao-check` 접속.

자기 회원 1명에 대해:
1. "쿠폰" 체크 → dim 처리, 사라짐 (처리 완료도 보기 OFF)
2. "특이" 체크 → prompt → 메모 저장 → 표시
3. 메모 클릭 → 재편집

Expected: 어드민과 동일하게 동작. 다른 코치의 회원에는 토글 시도 → API 403 (체크박스가 그 회원에 안 보일 테지만 콘솔 직접 호출 시 차단).

- [ ] **Step 6: 커밋**

```bash
cd /root/pt-dev
git add public_html/coach/js/pages/kakao-check.js
git commit -m "feat(pt-coach): 카톡방 입장 체크에 쿠폰/특이 컬럼 추가

코치 페이지에도 어드민과 동일 UX. 코치는 자기 coach_id 회원에만
토글 가능 (서버 권한 체크는 toggle_flag 라우터에서 강제).

Spec: docs/superpowers/specs/2026-05-06-pt-kakao-check-extra-flags-design.md"
```

---

## Task 8: 통합 회귀 테스트 + 정리

**Files:** (변경 없음, 검증만)

- [ ] **Step 1: 전체 테스트 재실행**

Run: `php /root/pt-dev/tests/run_tests.php 2>&1 | tail -10`
Expected: 모든 섹션 PASS. 회귀 없음.

- [ ] **Step 2: PHP lint 전체 확인**

```bash
cd /root/pt-dev
php -l public_html/api/kakao_check.php
php -l public_html/includes/notify/source_pt_orders_query.php
```
Expected: `No syntax errors detected` 두 번.

- [ ] **Step 3: 어드민 알림톡 운영 페이지 sanity check (수동)**

`https://dev-pt.soritune.com/admin/#notify` (또는 알림톡 운영 탭) 접속 → `pt_kakao_room_remind` 시나리오 dry-run/preview 기능이 있다면 실행.
- coupon_issued=1로 설정한 회원이 미리보기에서 빠지는지
- special_case=1로 설정한 회원이 미리보기에서 빠지는지

Expected: 미포함. 만약 dry-run UI 없으면 어댑터 직접 호출:

```bash
mysql --defaults-file=/root/.my.cnf SORITUNECOM_DEV_PT -e "
  SELECT o.id, m.name, o.coupon_issued, o.special_case, o.kakao_room_joined
  FROM orders o JOIN members m ON m.id=o.member_id
  WHERE o.product_name='소리튜닝 음성PT' AND o.cohort_month=DATE_FORMAT(CURDATE(),'%Y-%m')
    AND o.status='진행중'"
```
직접 어댑터 결과와 대조 — coupon=1 / special=1 회원이 어댑터 결과에서 빠지는지.

- [ ] **Step 4: dev push**

```bash
cd /root/pt-dev
git status         # working tree clean 확인
git log --oneline origin/dev..HEAD   # push할 커밋 목록 확인
git push origin dev
```

Expected: 모든 커밋 push 완료.

- [ ] **Step 5: ⛔ 사용자에게 dev 확인 요청**

dev push 완료 메시지를 사용자에게 전달. 운영 반영 여부는 사용자 확인 후에만 진행.

다음 메시지 템플릿:

> dev 배포 완료 (커밋 N개 push). 다음을 확인해주세요:
> - https://dev-pt.soritune.com/admin/#kakao-check : 쿠폰/특이 컬럼 표시 + 토글
> - https://dev-pt.soritune.com/coach/#kakao-check : 코치 페이지 동일 동작
> - 특이 메모 prompt + ellipsis 표시 + 재편집
>
> 운영 반영(main 머지 + PROD pull + PROD 마이그)은 사용자 명시적 요청 시에만 진행합니다.

---

## Task 9 (조건부): 운영 반영

**조건:** 사용자가 "운영 반영해줘" 등 명시적 요청을 한 경우에만 실행.

- [ ] **Step 1: PROD DB에 마이그 적용**

```bash
mysql --defaults-file=/root/.my.cnf SORITUNECOM_PT < /root/pt-prod/migrations/20260506_orders_add_coupon_special.sql
```

(실제 파일은 main 머지 직후 pt-prod에 들어감. 하지만 코드 push 전에 PROD 컬럼이 없으면 어댑터 SQL이 unknown column 에러 → **PROD 마이그를 코드 push보다 먼저 실행**.)

검증:
```bash
mysql --defaults-file=/root/.my.cnf SORITUNECOM_PT -e "SHOW COLUMNS FROM orders LIKE '%_issued%'; SHOW COLUMNS FROM orders LIKE '%special_case%'"
```
Expected: 7개 컬럼 모두 존재.

- [ ] **Step 2: pt-dev에서 main 머지 + push**

```bash
cd /root/pt-dev
git checkout main
git pull origin main
git merge dev
git push origin main
git checkout dev
```

Expected: fast-forward 또는 깔끔한 머지. 충돌 없음(혼자 작업 중이면 보통 ff).

- [ ] **Step 3: pt-prod git pull**

```bash
cd /root/pt-prod
git pull origin main
```

Expected: 최신 main으로 업데이트. PHP 직접 서빙이라 빌드/재시작 없음.

- [ ] **Step 4: PROD smoke**

`https://pt.soritune.com/admin/#kakao-check` 접속:
- 표에 쿠폰/특이 컬럼 보임
- 한 행에 쿠폰 체크 → 즉시 dim → "처리 완료도 보기" OFF면 사라짐
- 즉시 체크 해제 → 다시 등장

특정 cohort에 coupon=1 또는 special=1 회원이 있다면 19시 알림톡 cron 실행 결과에서 빠지는지 다음 회차에 확인.

- [ ] **Step 5: 메모리 / 사용자에게 보고**

운영 배포 완료 메시지를 사용자에게 전달.

---

## Self-Review

**1. Spec coverage:**

| Spec 섹션 | 구현 task |
|-----------|-----------|
| 데이터 모델 7컬럼 | Task 1 |
| `toggle_flag` 액션 | Task 2 (함수) + Task 4 (라우터) |
| `list` 새 컬럼 노출 | Task 3 |
| 어드민 UI | Task 6 |
| 코치 UI | Task 7 |
| 알림톡 어댑터 차단 | Task 5 |
| 권한 (coach 자기 order만) | Task 4 (라우터) |
| `_at`/`_by`/`_note` 메타 | Task 1 (DB) + Task 2 (함수) |
| change_logs | Task 2 (모든 액션) |
| `include_joined` → `include_processed` 호환 | Task 3 (lib) + Task 4 (라우터) + Task 6/7 (UI) |
| 빈 메모 → NULL | Task 2 (함수 내 trim+empty 체크) |
| 마이그/배포 순서 | Task 1, 8, 9 |

모든 spec 요구사항이 task에 매핑됨.

**2. Placeholder scan:**
- TBD/TODO 없음
- 모든 코드 step에 실제 코드 포함
- 모든 명령어에 정확한 경로 + expected output

**3. Type consistency:**
- `kakaoCheckToggleFlag(PDO, int, string, bool, ?string, string, int): bool` — Task 2에서 정의, Task 4에서 호출. 시그니처 일치.
- API: `{order_id, flag, value, note}` — Task 4 (라우터) ↔ Task 6/7 (JS) 일치.
- `include_processed` (서버) ↔ `includeProcessed` (JS 변수). 일관.
- change_logs action 명: `coupon_issued_set/unset`, `special_case_set/unset`, `kakao_room_join/unjoin` — Task 2와 spec 일치.

**4. Ambiguity:**
- "체크박스 dim 조건" — 명시적으로 OR로 정의 (`kakaoOn || couponOn || specialOn`).
- "Cancel 시 동작" — `prompt() === null` → 토글 취소 (체크박스 원복) 명시.
- "빈 문자열" — `trim() === ''` → NULL 저장 명시.
- "기존 `toggle_join` 호환" — 함수/라우터 모두 그대로 유지, 새 코드는 `toggle_flag` 사용.
