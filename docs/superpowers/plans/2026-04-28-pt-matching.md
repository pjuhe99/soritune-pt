# PT 매칭 시스템 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 매칭대기 orders를 자동으로 코치에게 배분(이전 코치 우선 + 신규는 `final_allocation` 슬롯 무작위)하고, 어드민이 결과를 검토·수정한 뒤 일괄 확정해 `orders.coach_id` + `coach_assignments`로 commit하는 기능을 PT admin "매칭관리" 탭에 추가한다.

**Architecture:** 별도 staging(`coach_assignment_drafts`) + batch 메타(`coach_assignment_runs`) 테이블에 매칭안 저장. 어드민이 검토·드롭다운 수정 후 "확정" 클릭 시 단일 트랜잭션으로 orders update + coach_assignments INSERT + change_logs 기록. batch 생성 시점에 코치별 `final_allocation`을 `capacity_snapshot` JSON으로 박아 retention 화면 변경에 영향 받지 않게 격리. 한 번에 `status='draft'` row는 0 또는 1개.

**Tech Stack:** PHP 8+ / MariaDB / vanilla JS SPA (PT admin 기존 패턴: `App.registerPage` + `API.get/post` + `UI.esc/toast`)

**Spec:** `docs/superpowers/specs/2026-04-28-pt-matching-design.md`

**DB Credentials:** `.db_credentials` in project root (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`)

**Repo conventions (필수):**
- PT는 DEV/PROD 분리 없음 + main 단일 브랜치. `/var/www/html/_______site_SORITUNECOM_PT/`에서 직접 작업, worktree 없음.
- 각 Task는 local commit까지만. push 금지 (사용자가 직접).
- 본 작업과 무관한 사전 미커밋 변경(다른 api/*.php, member-chart.js, imports CSV 등)은 절대 건드리지 말 것. 매칭 관련 파일만 stage.

---

## File Map

| File | 책임 |
|---|---|
| `migrations/20260428_add_coach_matching.sql` (create) | 두 신규 테이블 생성 |
| `schema.sql` (modify) | 신규 테이블 반영 (미래 설치용) |
| `public_html/includes/matching_engine.php` (create) | 매칭 알고리즘 — Step 1(이전 코치 분류) + Step 2(신규 풀 분배). draft INSERT까지 |
| `public_html/api/matching.php` (create) | action 라우팅 API (current / runs / preview / base_months / start / update_draft / confirm / cancel) |
| `public_html/admin/js/pages/matching.js` (create) | 매칭 관리 SPA 페이지 |
| `public_html/admin/index.php` (modify) | 사이드바 링크 + 스크립트 로드 |
| `public_html/assets/css/style.css` (modify) | 매칭 UI 스타일 (capacity 진행도 카드 / source 배지 / 미매칭 강조) |
| `docs/superpowers/plans/2026-04-28-pt-matching.md` (this file) | 본 계획서 |

---

## Tasks

### Task 1: DB 마이그레이션 작성 + DEV 적용

**Files:**
- Create: `migrations/20260428_add_coach_matching.sql`
- Modify: `schema.sql`

- [ ] **Step 1: 마이그레이션 파일 작성**

Create `migrations/20260428_add_coach_matching.sql`:

```sql
-- 2026-04-28: PT 매칭 시스템 — staging + batch 메타 테이블 추가
-- 기존 테이블 변경 없음 (orders, coaches, members, coach_assignments, coach_retention_scores)

SET NAMES utf8mb4;

-- 1. coach_assignment_runs (batch 메타)
CREATE TABLE IF NOT EXISTS `coach_assignment_runs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `base_month` VARCHAR(7) NOT NULL,
  `status` ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  `started_by` INT NOT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at` DATETIME DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `total_orders` INT NOT NULL DEFAULT 0,
  `prev_coach_count` INT NOT NULL DEFAULT 0,
  `new_pool_count` INT NOT NULL DEFAULT 0,
  `matched_count` INT NOT NULL DEFAULT 0,
  `unmatched_count` INT NOT NULL DEFAULT 0,
  `capacity_snapshot` LONGTEXT DEFAULT NULL,
  FOREIGN KEY (`started_by`) REFERENCES `admins`(`id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_started_at` (`started_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. coach_assignment_drafts (매칭안 row)
CREATE TABLE IF NOT EXISTS `coach_assignment_drafts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `batch_id` INT NOT NULL,
  `order_id` INT NOT NULL,
  `proposed_coach_id` INT DEFAULT NULL,
  `source` ENUM('previous_coach','new_pool','manual_override','unmatched') NOT NULL,
  `prev_coach_id` INT DEFAULT NULL,
  `prev_end_date` DATE DEFAULT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`batch_id`) REFERENCES `coach_assignment_runs`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`proposed_coach_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL,
  UNIQUE KEY `uq_batch_order` (`batch_id`, `order_id`),
  INDEX `idx_proposed_coach` (`proposed_coach_id`),
  INDEX `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: 마이그레이션 적용**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
mysql -uroot -p"$(grep '^DB_PASS=' .db_credentials | cut -d= -f2- | tr -d '"')" SORITUNECOM_PT < migrations/20260428_add_coach_matching.sql
```
Expected: 에러 없음 (출력 없음).

- [ ] **Step 3: 테이블 생성 확인**

Run:
```bash
mysql -uroot -p"$(grep '^DB_PASS=' .db_credentials | cut -d= -f2- | tr -d '"')" -e "
SHOW CREATE TABLE SORITUNECOM_PT.coach_assignment_runs\G
SHOW CREATE TABLE SORITUNECOM_PT.coach_assignment_drafts\G
"
```
Expected:
- `coach_assignment_runs` ENUM `('draft','confirmed','cancelled')`, FK to `admins`, capacity_snapshot LONGTEXT 보임
- `coach_assignment_drafts` ENUM `('previous_coach','new_pool','manual_override','unmatched')`, UNIQUE `uq_batch_order`, 3개 FK 보임

- [ ] **Step 4: `schema.sql` 동기화**

Open `schema.sql`. Find DROP TABLE 블록 (line ~8-20). Insert 두 줄을 `DROP TABLE IF EXISTS coach_retention_runs;` **이전**에:

```sql
DROP TABLE IF EXISTS `coach_assignment_drafts`;
DROP TABLE IF EXISTS `coach_assignment_runs`;
```

이어서 `coach_retention_runs` CREATE 블록 **이후**에 다음 두 CREATE 블록 추가 (마이그레이션과 동일 DDL, `IF NOT EXISTS`만 제거):

```sql
-- 14. coach_assignment_runs
CREATE TABLE `coach_assignment_runs` (
  ... (마이그레이션 DDL과 동일, IF NOT EXISTS 제거)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. coach_assignment_drafts
CREATE TABLE `coach_assignment_drafts` (
  ...
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

번호는 기존 schema의 마지막 번호(`migration_logs` = 14) 다음을 잇도록. 즉 `migration_logs`를 16으로 밀고 14/15에 신규 테이블 삽입.

⚠️ 사전 미커밋 변경(`coach_assignments` DROP/CREATE 블록 제거 등)이 schema.sql에 있을 수 있음. 그 부분은 **건드리지 말고**, 매칭 관련 두 테이블만 깔끔히 추가.

- [ ] **Step 5: schema.sql 변경 검증**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git diff schema.sql | head -80
```
Expected: 두 테이블 DROP + CREATE 블록만 추가되어 있고, 기존 테이블 변경 없음.

- [ ] **Step 6: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add migrations/20260428_add_coach_matching.sql schema.sql
git commit -m "$(cat <<'EOF'
feat(matching): DB schema for matching draft batches

coach_assignment_runs (batch 메타 + capacity_snapshot JSON) +
coach_assignment_drafts (매칭안 row, FK CASCADE). 기존 테이블 변경 없음.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: 매칭 엔진 헬퍼 (`matching_engine.php`)

**Files:**
- Create: `public_html/includes/matching_engine.php`

목표: 입력으로 `(PDO $db, int $batchId, string $baseMonth, array $capacitySnapshot)`를 받아 매칭대기 orders를 분류·분배하고 `coach_assignment_drafts`에 INSERT한 뒤 통계를 반환한다. API 라우터(`matching.php`)와 분리해서 단위 검증을 쉽게 한다.

- [ ] **Step 1: 파일 골격 + 함수 시그니처 작성**

Create `public_html/includes/matching_engine.php`:

```php
<?php
/**
 * 매칭 엔진 — 매칭대기 orders 분류 + 분배 + drafts INSERT
 *
 * 흐름:
 *   1) 매칭대기 orders 전체 로드 (active draft에 이미 들어가있는 건 제외)
 *   2) 각 order에 대해 _classifyOrder() — '이전 코치' or '신규 풀' 분류
 *   3) '신규 풀' 묶음에 _distributeNewPool() — capacity_snapshot 기반 슬롯 셔플 zip
 *   4) 결과를 coach_assignment_drafts에 batch INSERT
 *   5) coach_assignment_runs 통계 컬럼 update
 *
 * 반환: ['total'=>N, 'prev_coach'=>N, 'new_pool'=>N, 'matched'=>N, 'unmatched'=>N]
 */

require_once __DIR__ . '/db.php';

/**
 * Run matching for a newly-created batch.
 *
 * @param PDO    $db
 * @param int    $batchId            coach_assignment_runs.id
 * @param string $baseMonth          YYYY-MM (사용된 final_allocation의 기준월, 메모용)
 * @param array  $capacitySnapshot   [['coach_id'=>1,'coach_name'=>'Tia','final_allocation'=>10], ...]
 *                                   coaches.status='active' 만 포함되어 있다고 가정
 * @return array stats
 */
function runMatchingForBatch(PDO $db, int $batchId, string $baseMonth, array $capacitySnapshot): array {
    $orders = _fetchUnmatchedOrders($db);
    $prevCoachRows = [];
    $newPoolRows   = [];

    foreach ($orders as $o) {
        $cls = _classifyOrder($db, $o);
        if ($cls['source'] === 'previous_coach') {
            $prevCoachRows[] = array_merge(['order_id' => (int)$o['id']], $cls);
        } else {
            // unresolved → 신규 풀로 보냄. 단, 참고 정보(prev_coach_id, prev_end_date)는 유지.
            $newPoolRows[] = array_merge(['order_id' => (int)$o['id']], $cls);
        }
    }

    $distributed = _distributeNewPool($newPoolRows, $capacitySnapshot);
    $allRows = array_merge($prevCoachRows, $distributed);

    _insertDrafts($db, $batchId, $allRows);
    $stats = _summarize($allRows);

    $upd = $db->prepare("
        UPDATE coach_assignment_runs
           SET total_orders     = ?,
               prev_coach_count = ?,
               new_pool_count   = ?,
               matched_count    = ?,
               unmatched_count  = ?
         WHERE id = ?
    ");
    $upd->execute([
        $stats['total'], $stats['prev_coach'], $stats['new_pool'],
        $stats['matched'], $stats['unmatched'], $batchId
    ]);

    return $stats;
}
```

- [ ] **Step 2: `_fetchUnmatchedOrders()` 구현 추가**

Append to same file:

```php
/**
 * 매칭대기이고 active draft batch에 들어가있지 않은 orders.
 */
function _fetchUnmatchedOrders(PDO $db): array {
    $stmt = $db->prepare("
        SELECT o.id, o.member_id, o.coach_id, o.product_name, o.start_date, o.end_date, o.status
          FROM orders o
         WHERE o.status = '매칭대기'
           AND NOT EXISTS (
                 SELECT 1 FROM coach_assignment_drafts d
                          JOIN coach_assignment_runs r ON r.id = d.batch_id
                  WHERE d.order_id = o.id AND r.status = 'draft'
           )
         ORDER BY o.start_date, o.id
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}
```

- [ ] **Step 3: `_classifyOrder()` 구현 추가**

Append:

```php
/**
 * 단일 order에 대해 이전 코치 / 신규 풀 분류.
 *
 * 반환:
 *   ['source'=>'previous_coach',   'proposed_coach_id'=>$cid, 'prev_coach_id'=>$cid, 'prev_end_date'=>$d, 'reason'=>...]
 *   ['source'=>'new_pool',         'proposed_coach_id'=>null, 'prev_coach_id'=>$pid|null, 'prev_end_date'=>$d|null, 'reason'=>...]
 */
function _classifyOrder(PDO $db, array $current): array {
    $stmt = $db->prepare("
        SELECT id, coach_id, end_date
          FROM orders
         WHERE member_id = ?
           AND id        != ?
           AND status NOT IN ('환불','중단')
         ORDER BY end_date DESC
         LIMIT 1
    ");
    $stmt->execute([$current['member_id'], $current['id']]);
    $prev = $stmt->fetch();

    if (!$prev) {
        return [
            'source' => 'new_pool',
            'proposed_coach_id' => null,
            'prev_coach_id'     => null,
            'prev_end_date'     => null,
            'reason'            => '이전 PT 이력 없음 → 신규 풀',
        ];
    }

    $prevCoachId  = $prev['coach_id'] ? (int)$prev['coach_id'] : null;
    $prevEndDate  = $prev['end_date'];

    // gap_days = current.start_date - prev.end_date
    $gapDays = (int)((strtotime($current['start_date']) - strtotime($prevEndDate)) / 86400);

    if ($prevCoachId === null) {
        return [
            'source' => 'new_pool',
            'proposed_coach_id' => null,
            'prev_coach_id'     => null,
            'prev_end_date'     => $prevEndDate,
            'reason'            => "직전 PT의 코치 미지정 → 신규 풀 (gap {$gapDays}일)",
        ];
    }

    $coachStmt = $db->prepare("SELECT status FROM coaches WHERE id = ?");
    $coachStmt->execute([$prevCoachId]);
    $coachRow = $coachStmt->fetch();
    $coachStatus = $coachRow['status'] ?? null;

    if ($coachStatus !== 'active') {
        return [
            'source' => 'new_pool',
            'proposed_coach_id' => null,
            'prev_coach_id'     => $prevCoachId,
            'prev_end_date'     => $prevEndDate,
            'reason'            => "이전 코치 inactive → 신규 풀 (gap {$gapDays}일)",
        ];
    }

    if ($gapDays >= 365) {
        return [
            'source' => 'new_pool',
            'proposed_coach_id' => null,
            'prev_coach_id'     => $prevCoachId,
            'prev_end_date'     => $prevEndDate,
            'reason'            => "이전 PT 종료 후 {$gapDays}일 경과 → 신규 풀",
        ];
    }

    return [
        'source' => 'previous_coach',
        'proposed_coach_id' => $prevCoachId,
        'prev_coach_id'     => $prevCoachId,
        'prev_end_date'     => $prevEndDate,
        'reason'            => "직전 PT (~{$prevEndDate}, gap {$gapDays}일) 담당",
    ];
}
```

- [ ] **Step 4: `_distributeNewPool()` 구현 추가**

Append:

```php
/**
 * 신규 풀을 capacity 슬롯 셔플 zip으로 분배.
 * 풀 < 슬롯: 일부 슬롯 빈 채로 종료 (코치별 정원 미달).
 * 풀 > 슬롯: 남은 풀은 source='unmatched', proposed_coach_id=null.
 *
 * 입력 row 형태(_classifyOrder의 'new_pool' 결과 + order_id):
 *   ['order_id'=>N, 'source'=>'new_pool', 'proposed_coach_id'=>null,
 *    'prev_coach_id'=>X, 'prev_end_date'=>Y, 'reason'=>...]
 *
 * 반환: 같은 형식이지만 분배 후 source/proposed_coach_id/reason 갱신됨.
 */
function _distributeNewPool(array $pool, array $capacitySnapshot): array {
    if (empty($pool)) return [];

    // 1) capacity 슬롯 만들기
    $slots = [];
    foreach ($capacitySnapshot as $c) {
        $cap = (int)$c['final_allocation'];
        for ($i = 0; $i < $cap; $i++) {
            $slots[] = (int)$c['coach_id'];
        }
    }

    // 2) 풀 셔플 + 슬롯 셔플
    shuffle($pool);
    shuffle($slots);

    // 3) zip
    $result = [];
    $slotIdx = 0;
    foreach ($pool as $row) {
        if ($slotIdx < count($slots)) {
            $row['source']            = 'new_pool';
            $row['proposed_coach_id'] = $slots[$slotIdx];
            $row['reason']            = '신규 풀 무작위 추첨';
            $slotIdx++;
        } else {
            $row['source']            = 'unmatched';
            $row['proposed_coach_id'] = null;
            $row['reason']            = '이번 batch 신규 capacity 부족';
        }
        $result[] = $row;
    }

    return $result;
}
```

- [ ] **Step 5: `_insertDrafts()` + `_summarize()` 구현 추가**

Append:

```php
function _insertDrafts(PDO $db, int $batchId, array $rows): void {
    if (empty($rows)) return;
    $stmt = $db->prepare("
        INSERT INTO coach_assignment_drafts
          (batch_id, order_id, proposed_coach_id, source, prev_coach_id, prev_end_date, reason)
        VALUES (?,?,?,?,?,?,?)
    ");
    foreach ($rows as $r) {
        $stmt->execute([
            $batchId,
            (int)$r['order_id'],
            $r['proposed_coach_id'],
            $r['source'],
            $r['prev_coach_id'],
            $r['prev_end_date'],
            $r['reason'],
        ]);
    }
}

function _summarize(array $rows): array {
    $total = count($rows);
    $prevCoach = 0; $newPool = 0; $matched = 0; $unmatched = 0;
    foreach ($rows as $r) {
        if ($r['source'] === 'previous_coach') { $prevCoach++; $matched++; }
        elseif ($r['source'] === 'new_pool')   { $newPool++;   $matched++; }
        elseif ($r['source'] === 'unmatched')  { $newPool++;   $unmatched++; }
    }
    return [
        'total'      => $total,
        'prev_coach' => $prevCoach,
        'new_pool'   => $newPool,
        'matched'    => $matched,
        'unmatched'  => $unmatched,
    ];
}
```

- [ ] **Step 6: `php -l` 검증**

Run:
```bash
php -l /var/www/html/_______site_SORITUNECOM_PT/public_html/includes/matching_engine.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 7: CLI 스모크 — `_classifyOrder` 4가지 케이스**

Create `/tmp/smoke_classify.php`:

```php
<?php
require_once '/var/www/html/_______site_SORITUNECOM_PT/public_html/includes/db.php';
require_once '/var/www/html/_______site_SORITUNECOM_PT/public_html/includes/matching_engine.php';
$db = getDB();

// 임의 매칭대기 order 1개 가져오기
$o = $db->query("SELECT id, member_id, start_date FROM orders WHERE status='매칭대기' ORDER BY id LIMIT 1")->fetch();
if (!$o) { echo "no 매칭대기 order in DB\n"; exit(1); }

$result = _classifyOrder($db, $o);
echo "order_id={$o['id']} member_id={$o['member_id']} start_date={$o['start_date']}\n";
print_r($result);
```

Run:
```bash
php /tmp/smoke_classify.php
```
Expected: source/proposed_coach_id/prev_coach_id/prev_end_date/reason 5필드 모두 채워져 출력. Reason 한글 메시지가 4가지 분기 중 하나.

⚠️ 매칭대기 order가 DB에 없으면 다음 task에서 임의 INSERT로 만들거나, retention 작업 때처럼 사용자 확인 필요.

- [ ] **Step 8: CLI 스모크 — `_distributeNewPool` zip 알고리즘**

Create `/tmp/smoke_distribute.php`:

```php
<?php
require_once '/var/www/html/_______site_SORITUNECOM_PT/public_html/includes/matching_engine.php';

// 풀 5명, 코치 capacity 합 3 → 2명 unmatched
$pool = [];
for ($i=1; $i<=5; $i++) {
    $pool[] = ['order_id'=>$i, 'source'=>'new_pool', 'proposed_coach_id'=>null,
               'prev_coach_id'=>null, 'prev_end_date'=>null, 'reason'=>''];
}
$capacity = [
    ['coach_id'=>10, 'coach_name'=>'A', 'final_allocation'=>2],
    ['coach_id'=>20, 'coach_name'=>'B', 'final_allocation'=>1],
];
$result = _distributeNewPool($pool, $capacity);

$matched = 0; $unmatched = 0;
foreach ($result as $r) {
    if ($r['source']==='new_pool') $matched++;
    if ($r['source']==='unmatched') $unmatched++;
}
echo "matched={$matched} unmatched={$unmatched}\n";
echo "expect: matched=3 unmatched=2\n";
```

Run:
```bash
php /tmp/smoke_distribute.php
```
Expected: `matched=3 unmatched=2`.

- [ ] **Step 9: 스모크 잔해 정리**

Run:
```bash
rm -f /tmp/smoke_classify.php /tmp/smoke_distribute.php
```

- [ ] **Step 10: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/includes/matching_engine.php
git commit -m "$(cat <<'EOF'
feat(matching): 매칭 엔진 헬퍼 (분류 + 신규 풀 분배)

_classifyOrder(): orders.status NOT IN (환불/중단) 중 가장 최근 row 기준
이전 코치 식별. inactive/1년 이상이면 신규 풀.
_distributeNewPool(): capacity 슬롯 셔플 zip. 풀 > 슬롯이면 unmatched.
runMatchingForBatch()가 외부 API 진입점.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: API 골격 + GET 엔드포인트 (current / runs / preview / base_months)

**Files:**
- Create: `public_html/api/matching.php`

- [ ] **Step 1: 파일 골격 + 글로벌 인증 + 라우터**

Create `public_html/api/matching.php`:

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/matching_engine.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$db    = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'current':       _actionCurrent($db);             break;
    case 'runs':          _actionRuns($db);                break;
    case 'preview':       _actionPreview($db);             break;
    case 'base_months':   _actionBaseMonths($db);          break;
    case 'start':         _actionStart($db, $admin);       break;
    case 'update_draft':  _actionUpdateDraft($db, $admin); break;
    case 'confirm':       _actionConfirm($db, $admin);     break;
    case 'cancel':        _actionCancel($db, $admin);      break;
    default:
        jsonError('알 수 없는 action', 400);
}
```

- [ ] **Step 2: `_actionCurrent` 구현**

Append:

```php
/**
 * GET ?action=current
 * 현재 status='draft'인 batch가 있으면 그 batch와 drafts 목록 + capacity_snapshot 반환.
 * 없으면 {"current": null}.
 */
function _actionCurrent(PDO $db): void {
    $run = $db->query("
        SELECT id, base_month, status, started_by, started_at,
               total_orders, prev_coach_count, new_pool_count,
               matched_count, unmatched_count, capacity_snapshot
          FROM coach_assignment_runs
         WHERE status = 'draft'
         ORDER BY started_at DESC
         LIMIT 1
    ")->fetch();

    if (!$run) { jsonSuccess(['current' => null]); }

    $stmt = $db->prepare("
        SELECT d.id, d.order_id, d.proposed_coach_id, d.source,
               d.prev_coach_id, d.prev_end_date, d.reason, d.updated_at,
               m.id AS member_id, m.name AS member_name,
               o.product_name, o.start_date, o.end_date,
               c.coach_name AS proposed_coach_name,
               pc.coach_name AS prev_coach_name
          FROM coach_assignment_drafts d
          JOIN orders   o  ON o.id  = d.order_id
          JOIN members  m  ON m.id  = o.member_id
          LEFT JOIN coaches c  ON c.id  = d.proposed_coach_id
          LEFT JOIN coaches pc ON pc.id = d.prev_coach_id
         WHERE d.batch_id = ?
         ORDER BY d.source, m.name
    ");
    $stmt->execute([$run['id']]);
    $drafts = $stmt->fetchAll();

    $run['capacity_snapshot'] = $run['capacity_snapshot']
        ? json_decode($run['capacity_snapshot'], true) : [];

    foreach ($drafts as &$d) {
        $d['proposed_coach_id'] = $d['proposed_coach_id'] !== null ? (int)$d['proposed_coach_id'] : null;
        $d['prev_coach_id']     = $d['prev_coach_id']     !== null ? (int)$d['prev_coach_id']     : null;
    }
    unset($d);

    jsonSuccess(['current' => ['run' => $run, 'drafts' => $drafts]]);
}
```

- [ ] **Step 3: `_actionRuns` 구현**

Append:

```php
/**
 * GET ?action=runs
 * 과거 batch 메타 리스트 (confirmed / cancelled), 최근 20건.
 */
function _actionRuns(PDO $db): void {
    $rows = $db->query("
        SELECT id, base_month, status, started_at, confirmed_at, cancelled_at,
               total_orders, matched_count, unmatched_count
          FROM coach_assignment_runs
         WHERE status IN ('confirmed','cancelled')
         ORDER BY started_at DESC
         LIMIT 20
    ")->fetchAll();
    jsonSuccess(['runs' => $rows]);
}
```

- [ ] **Step 4: `_actionPreview` 구현**

Append:

```php
/**
 * GET ?action=preview
 * 현재 매칭 대상이 될 매칭대기 order 수 (active draft 제외).
 */
function _actionPreview(PDO $db): void {
    $cnt = (int)$db->query("
        SELECT COUNT(*) FROM orders o
         WHERE o.status='매칭대기'
           AND NOT EXISTS (
                 SELECT 1 FROM coach_assignment_drafts d
                          JOIN coach_assignment_runs r ON r.id=d.batch_id
                  WHERE d.order_id=o.id AND r.status='draft'
           )
    ")->fetchColumn();
    jsonSuccess(['unmatched_orders' => $cnt]);
}
```

- [ ] **Step 5: `_actionBaseMonths` 구현**

Append:

```php
/**
 * GET ?action=base_months
 * coach_retention_runs에 있는 base_month 목록 (start 시 드롭다운용). 최근 12개월.
 */
function _actionBaseMonths(PDO $db): void {
    $rows = $db->query("
        SELECT base_month
          FROM coach_retention_runs
         ORDER BY base_month DESC
         LIMIT 12
    ")->fetchAll(PDO::FETCH_COLUMN);
    jsonSuccess(['base_months' => $rows]);
}
```

- [ ] **Step 6: 나머지 4개 함수 stub (404 반환)**

Append (다음 Task에서 구현):

```php
function _actionStart(PDO $db, array $admin): void { jsonError('미구현', 501); }
function _actionUpdateDraft(PDO $db, array $admin): void { jsonError('미구현', 501); }
function _actionConfirm(PDO $db, array $admin): void { jsonError('미구현', 501); }
function _actionCancel(PDO $db, array $admin): void { jsonError('미구현', 501); }
```

- [ ] **Step 7: `php -l` 검증**

Run:
```bash
php -l /var/www/html/_______site_SORITUNECOM_PT/public_html/api/matching.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 8: CLI 스모크 — preview / base_months / current(empty)**

Create `/tmp/smoke_get.php`:

```php
<?php
$_SESSION = [];
session_start();
$_SESSION['pt_user'] = ['id'=>1, 'role'=>'admin'];

foreach (['preview','base_months','current','runs'] as $action) {
    $_GET = ['action'=>$action];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    require '/var/www/html/_______site_SORITUNECOM_PT/public_html/api/matching.php';
    $body = ob_get_clean();
    echo "=== $action ===\n$body\n";
}
```

Run:
```bash
php /tmp/smoke_get.php
```
Expected:
- `preview` → `{"ok":true, "data":{"unmatched_orders": N}}` (N은 환경 의존)
- `base_months` → `{"ok":true, "data":{"base_months":["2026-04",...]}}`
- `current` → `{"ok":true, "data":{"current": null}}` (active draft 없으면)
- `runs` → `{"ok":true, "data":{"runs": []}}` (없으면 빈 배열)

- [ ] **Step 9: 스모크 잔해 정리**

Run: `rm -f /tmp/smoke_get.php`

- [ ] **Step 10: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/matching.php
git commit -m "$(cat <<'EOF'
feat(matching): API 골격 + GET 엔드포인트 (current/runs/preview/base_months)

action 라우팅 dispatcher + 4개 GET 액션 구현.
나머지 4개(start/update_draft/confirm/cancel)는 후속 Task에서 stub→실 구현.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: API `start` — batch 생성 + 매칭 실행

**Files:**
- Modify: `public_html/api/matching.php` (replace `_actionStart` stub)

- [ ] **Step 1: `_actionStart` 본구현**

Replace stub with:

```php
/**
 * POST ?action=start
 * body: { base_month: "YYYY-MM" }
 *
 * 1. active draft가 이미 있으면 409 conflict
 * 2. 매칭대기 order 0건이면 400
 * 3. base_month의 coach_retention_scores를 capacity_snapshot으로 잡음
 * 4. coach_assignment_runs INSERT (status='draft')
 * 5. matching_engine.runMatchingForBatch() 호출 (drafts INSERT + runs 통계 업데이트)
 * 6. 결과 반환 (action=current 형태)
 */
function _actionStart(PDO $db, array $admin): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);

    $input = getJsonInput();
    $baseMonth = $input['base_month'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}$/', $baseMonth)) {
        jsonError('base_month 형식이 잘못되었습니다 (YYYY-MM)');
    }

    // 1. active draft 검사
    $existing = $db->query("SELECT id FROM coach_assignment_runs WHERE status='draft' LIMIT 1")->fetchColumn();
    if ($existing) {
        jsonError('이미 진행 중인 draft batch가 있습니다 (#' . $existing . '). 검토를 먼저 끝내거나 폐기해주세요.', 409);
    }

    // 2. 매칭대기 order 검사
    $unmatchedCount = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='매칭대기'")->fetchColumn();
    if ($unmatchedCount === 0) {
        jsonError('매칭대기 상태의 주문이 없습니다.', 400);
    }

    // 3. capacity_snapshot 잡기 (active 코치 only, final_allocation>0)
    $stmt = $db->prepare("
        SELECT s.coach_id, c.coach_name, s.final_allocation
          FROM coach_retention_scores s
          JOIN coaches c ON c.id = s.coach_id
         WHERE s.base_month = ?
           AND c.status     = 'active'
           AND s.final_allocation > 0
         ORDER BY c.coach_name
    ");
    $stmt->execute([$baseMonth]);
    $capacity = $stmt->fetchAll();
    foreach ($capacity as &$row) {
        $row['coach_id']         = (int)$row['coach_id'];
        $row['final_allocation'] = (int)$row['final_allocation'];
    }
    unset($row);

    // capacity 비어있어도 진행 (모두 unmatched가 될 수 있음). 운영 경고는 UI에서.

    // 4. runs INSERT
    $db->beginTransaction();
    try {
        $ins = $db->prepare("
            INSERT INTO coach_assignment_runs
              (base_month, status, started_by, capacity_snapshot)
            VALUES (?, 'draft', ?, ?)
        ");
        $ins->execute([
            $baseMonth,
            (int)$admin['id'],
            json_encode(array_values($capacity), JSON_UNESCAPED_UNICODE),
        ]);
        $batchId = (int)$db->lastInsertId();

        // 5. 매칭 엔진 실행
        $stats = runMatchingForBatch($db, $batchId, $baseMonth, $capacity);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jsonError('매칭 실행 실패: ' . $e->getMessage(), 500);
    }

    // 6. current 반환 (편의 — 클라이언트가 즉시 화면 그릴 수 있게)
    $_GET['action'] = 'current';
    _actionCurrent($db);
}
```

- [ ] **Step 2: `php -l` 검증**

Run:
```bash
php -l /var/www/html/_______site_SORITUNECOM_PT/public_html/api/matching.php
```
Expected: 에러 없음.

- [ ] **Step 3: CLI 스모크 — capacity 0인 경우 (모두 unmatched)**

Create `/tmp/smoke_start_empty.php`:

```php
<?php
session_start();
$_SESSION['pt_user'] = ['id'=>1, 'role'=>'admin'];

// capacity 0인 base_month 시도. coach_retention_scores에 final_allocation>0인 active 코치 없는 월을 찾자.
// 우선 매칭대기 1건 확보를 위해 stub order 생성:
require_once '/var/www/html/_______site_SORITUNECOM_PT/public_html/includes/db.php';
$db = getDB();

// 임시 매칭대기 order 1건이 있는지 확인
$cnt = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='매칭대기'")->fetchColumn();
echo "매칭대기 orders: $cnt\n";
if ($cnt === 0) {
    echo "스모크용 매칭대기 order 없음. Task 13 통합 검증으로 이월.\n";
    exit;
}

$_GET = ['action'=>'start'];
$_SERVER['REQUEST_METHOD'] = 'POST';
// php://input fake
$json = json_encode(['base_month' => '2099-12']);  // capacity 비어있을 base_month
file_put_contents('php://memory', $json);
$mock = tmpfile(); fwrite($mock, $json); rewind($mock);
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'PhpInputFake');
class PhpInputFake {
    public $context;
    private $pos = 0;
    private static $body;
    public static function setBody($b){ self::$body = $b; }
    public function stream_open(){ $this->pos=0; return true; }
    public function stream_read($c){
        $r = substr(self::$body, $this->pos, $c);
        $this->pos += strlen($r);
        return $r;
    }
    public function stream_eof(){ return $this->pos >= strlen(self::$body); }
    public function stream_stat(){ return []; }
}
PhpInputFake::setBody($json);

ob_start();
require '/var/www/html/_______site_SORITUNECOM_PT/public_html/api/matching.php';
echo ob_get_clean();
```

⚠️ php://input mocking은 까다롭다. 위 스크립트는 retention plan Task 7~9에서 검증된 패턴. 환경에 따라 동작 차이 있을 수 있어, **실패 시 브라우저로 검증**(Task 13).

Run:
```bash
php /tmp/smoke_start_empty.php
```
Expected: `{"ok":true, "data":{"current":{"run":{...}, "drafts":[...]}}}`. drafts는 모두 source='unmatched' (capacity 0이므로).

또는 매칭대기 order 0건이면 "매칭대기 상태의 주문이 없습니다" 에러.

- [ ] **Step 4: DB 검증 — runs + drafts 생성 확인**

Run:
```bash
mysql -uroot -p"$(grep '^DB_PASS=' /var/www/html/_______site_SORITUNECOM_PT/.db_credentials | cut -d= -f2- | tr -d '"')" -e "
SELECT id, base_month, status, total_orders, prev_coach_count, new_pool_count, matched_count, unmatched_count
  FROM SORITUNECOM_PT.coach_assignment_runs
 ORDER BY id DESC LIMIT 3;
SELECT batch_id, COUNT(*) AS cnt, GROUP_CONCAT(DISTINCT source) AS sources
  FROM SORITUNECOM_PT.coach_assignment_drafts
 GROUP BY batch_id ORDER BY batch_id DESC LIMIT 3;
"
```
Expected: 가장 최근 batch가 status='draft', drafts 개수 = unmatched_count + prev_coach_count + new_pool_count(matched 부분), sources에 'unmatched' 포함.

- [ ] **Step 5: 정리 — 스모크용 draft batch 삭제**

다음 task 검증을 깨끗하게 시작하기 위해 방금 만든 batch 폐기:

```bash
mysql -uroot -p"$(grep '^DB_PASS=' /var/www/html/_______site_SORITUNECOM_PT/.db_credentials | cut -d= -f2- | tr -d '"')" -e "
DELETE FROM SORITUNECOM_PT.coach_assignment_runs WHERE status='draft';
"
```
(drafts는 FK CASCADE로 함께 삭제)

- [ ] **Step 6: 스모크 잔해 정리**

Run: `rm -f /tmp/smoke_start_empty.php`

- [ ] **Step 7: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/matching.php
git commit -m "$(cat <<'EOF'
feat(matching): API start — batch 생성 + 매칭 실행

active draft 1개 제약 + 매칭대기 0건 가드. coach_retention_scores를
capacity_snapshot에 잡고 matching_engine.runMatchingForBatch 호출.
응답은 current 형태로 즉시 반환해 클라이언트가 화면 곧바로 그릴 수 있게.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: API `update_draft` + `cancel`

**Files:**
- Modify: `public_html/api/matching.php` (replace stubs)

- [ ] **Step 1: `_actionUpdateDraft` 구현**

Replace stub:

```php
/**
 * POST ?action=update_draft
 * body: { draft_id: N, proposed_coach_id: N|null }
 *
 * 어드민이 행별 드롭다운으로 코치를 바꾸면 호출.
 * - proposed_coach_id != null: source='manual_override', reason='수동 조정 (이전: {old_source})'
 * - proposed_coach_id == null: source='unmatched', reason='수동 비움'
 */
function _actionUpdateDraft(PDO $db, array $admin): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);

    $input = getJsonInput();
    $draftId = (int)($input['draft_id'] ?? 0);
    $newCoachId = array_key_exists('proposed_coach_id', $input)
        ? ($input['proposed_coach_id'] !== null ? (int)$input['proposed_coach_id'] : null)
        : null;

    if ($draftId <= 0) jsonError('draft_id가 필요합니다');

    $stmt = $db->prepare("
        SELECT d.id, d.source, d.proposed_coach_id, r.status AS run_status
          FROM coach_assignment_drafts d
          JOIN coach_assignment_runs r ON r.id = d.batch_id
         WHERE d.id = ?
    ");
    $stmt->execute([$draftId]);
    $row = $stmt->fetch();
    if (!$row) jsonError('draft를 찾을 수 없습니다', 404);
    if ($row['run_status'] !== 'draft') {
        jsonError('이 batch는 더 이상 편집할 수 없습니다 (status=' . $row['run_status'] . ')', 409);
    }

    if ($newCoachId !== null) {
        // 코치 존재/active 검사
        $coach = $db->prepare("SELECT id, status FROM coaches WHERE id = ?");
        $coach->execute([$newCoachId]);
        $c = $coach->fetch();
        if (!$c) jsonError('코치를 찾을 수 없습니다', 404);
        if ($c['status'] !== 'active') jsonError('inactive 코치에는 매칭할 수 없습니다', 400);

        $oldSource = $row['source'];
        $upd = $db->prepare("
            UPDATE coach_assignment_drafts
               SET proposed_coach_id = ?,
                   source            = 'manual_override',
                   reason            = ?
             WHERE id = ?
        ");
        $upd->execute([
            $newCoachId,
            "수동 조정 (이전: {$oldSource})",
            $draftId,
        ]);
    } else {
        $upd = $db->prepare("
            UPDATE coach_assignment_drafts
               SET proposed_coach_id = NULL,
                   source            = 'unmatched',
                   reason            = '수동 비움'
             WHERE id = ?
        ");
        $upd->execute([$draftId]);
    }

    // 갱신된 row 반환
    $sel = $db->prepare("
        SELECT d.id, d.order_id, d.proposed_coach_id, d.source,
               d.prev_coach_id, d.prev_end_date, d.reason, d.updated_at,
               c.coach_name AS proposed_coach_name,
               pc.coach_name AS prev_coach_name
          FROM coach_assignment_drafts d
          LEFT JOIN coaches c  ON c.id  = d.proposed_coach_id
          LEFT JOIN coaches pc ON pc.id = d.prev_coach_id
         WHERE d.id = ?
    ");
    $sel->execute([$draftId]);
    $latest = $sel->fetch();
    $latest['proposed_coach_id'] = $latest['proposed_coach_id'] !== null ? (int)$latest['proposed_coach_id'] : null;
    $latest['prev_coach_id']     = $latest['prev_coach_id']     !== null ? (int)$latest['prev_coach_id']     : null;

    jsonSuccess(['row' => $latest]);
}
```

- [ ] **Step 2: `_actionCancel` 구현**

Replace stub:

```php
/**
 * POST ?action=cancel
 * body: { batch_id: N }
 *
 * batch를 통째로 폐기. drafts CASCADE 삭제. orders는 매칭대기 그대로.
 * change_logs 기록은 생략 (drafts는 임시 데이터).
 */
function _actionCancel(PDO $db, array $admin): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);

    $input = getJsonInput();
    $batchId = (int)($input['batch_id'] ?? 0);
    if ($batchId <= 0) jsonError('batch_id가 필요합니다');

    $run = $db->prepare("SELECT id, status FROM coach_assignment_runs WHERE id = ?");
    $run->execute([$batchId]);
    $r = $run->fetch();
    if (!$r) jsonError('batch를 찾을 수 없습니다', 404);
    if ($r['status'] !== 'draft') {
        jsonError('이미 처리된 batch입니다 (status=' . $r['status'] . ')', 409);
    }

    $db->beginTransaction();
    try {
        $upd = $db->prepare("UPDATE coach_assignment_runs SET status='cancelled', cancelled_at=NOW() WHERE id = ?");
        $upd->execute([$batchId]);
        $del = $db->prepare("DELETE FROM coach_assignment_drafts WHERE batch_id = ?");
        $del->execute([$batchId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jsonError('취소 실패: ' . $e->getMessage(), 500);
    }

    jsonSuccess(['ok' => true]);
}
```

- [ ] **Step 3: `php -l` 검증**

Run: `php -l /var/www/html/_______site_SORITUNECOM_PT/public_html/api/matching.php`
Expected: 에러 없음.

- [ ] **Step 4: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/matching.php
git commit -m "$(cat <<'EOF'
feat(matching): API update_draft + cancel

update_draft: 행별 드롭다운 변경 → manual_override / unmatched 전환,
inactive 코치 가드. cancel: batch 통째로 폐기 (drafts CASCADE).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

(브라우저 검증은 Task 13 통합 체크리스트로 이월.)

---

### Task 6: API `confirm` — 트랜잭션 + change_logs + coach_assignments

**Files:**
- Modify: `public_html/api/matching.php` (replace stub)

- [ ] **Step 1: `_actionConfirm` 구현**

Replace stub:

```php
/**
 * POST ?action=confirm
 * body: { batch_id: N }
 *
 * 1. 매칭된 drafts (proposed_coach_id IS NOT NULL)에 대해
 *    - inactive 코치 검사 (있으면 fail)
 *    - orders.coach_id ← proposed_coach_id, status='매칭완료'
 *    - coach_assignments INSERT
 *    - change_logs INSERT
 * 2. runs.status='confirmed', confirmed_at=NOW()
 * 3. drafts DELETE (audit는 change_logs + runs 메타로 충분)
 * 미매칭 drafts (proposed_coach_id IS NULL): orders 그대로, drafts만 정리.
 */
function _actionConfirm(PDO $db, array $admin): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);

    $input = getJsonInput();
    $batchId = (int)($input['batch_id'] ?? 0);
    if ($batchId <= 0) jsonError('batch_id가 필요합니다');

    $run = $db->prepare("SELECT id, status FROM coach_assignment_runs WHERE id = ?");
    $run->execute([$batchId]);
    $r = $run->fetch();
    if (!$r) jsonError('batch를 찾을 수 없습니다', 404);
    if ($r['status'] !== 'draft') {
        jsonError('이미 처리된 batch입니다 (status=' . $r['status'] . ')', 409);
    }

    // inactive 코치 있는지 사전 검사
    $inactiveCheck = $db->prepare("
        SELECT d.id, c.coach_name
          FROM coach_assignment_drafts d
          JOIN coaches c ON c.id = d.proposed_coach_id
         WHERE d.batch_id = ?
           AND c.status   != 'active'
    ");
    $inactiveCheck->execute([$batchId]);
    $bad = $inactiveCheck->fetchAll();
    if (!empty($bad)) {
        $names = array_map(fn($r)=>$r['coach_name'], $bad);
        jsonError('확정 실패: 다음 코치가 inactive 입니다 — ' . implode(', ', $names) . '. 수동으로 다른 코치를 지정해주세요.', 409);
    }

    // 매칭된 drafts 로드
    $matchedStmt = $db->prepare("
        SELECT d.id, d.order_id, d.proposed_coach_id, d.source,
               o.member_id, o.coach_id AS old_coach_id
          FROM coach_assignment_drafts d
          JOIN orders o ON o.id = d.order_id
         WHERE d.batch_id = ?
           AND d.proposed_coach_id IS NOT NULL
    ");
    $matchedStmt->execute([$batchId]);
    $matched = $matchedStmt->fetchAll();

    $db->beginTransaction();
    try {
        $updOrder    = $db->prepare("UPDATE orders SET coach_id = ?, status = '매칭완료' WHERE id = ?");
        $insAssign   = $db->prepare("
            INSERT INTO coach_assignments (member_id, coach_id, order_id, assigned_at, reason)
            VALUES (?, ?, ?, NOW(), ?)
        ");
        $insLog = $db->prepare("
            INSERT INTO change_logs (target_type, target_id, action, old_value, new_value, actor_type, actor_id)
            VALUES ('order', ?, 'coach_assigned', ?, ?, 'admin', ?)
        ");

        foreach ($matched as $m) {
            $updOrder->execute([(int)$m['proposed_coach_id'], (int)$m['order_id']]);

            $reasonLabel = match ($m['source']) {
                'previous_coach'   => 'auto_match:previous_coach',
                'new_pool'         => 'auto_match:new_pool',
                'manual_override'  => 'auto_match:manual_override',
                default            => 'auto_match',
            };
            $insAssign->execute([
                (int)$m['member_id'],
                (int)$m['proposed_coach_id'],
                (int)$m['order_id'],
                $reasonLabel,
            ]);

            $insLog->execute([
                (int)$m['order_id'],
                json_encode(['coach_id' => $m['old_coach_id'] !== null ? (int)$m['old_coach_id'] : null], JSON_UNESCAPED_UNICODE),
                json_encode([
                    'coach_id' => (int)$m['proposed_coach_id'],
                    'source'   => $m['source'],
                    'batch_id' => $batchId,
                ], JSON_UNESCAPED_UNICODE),
                (int)$admin['id'],
            ]);
        }

        // runs 상태 전환
        $db->prepare("UPDATE coach_assignment_runs SET status='confirmed', confirmed_at=NOW() WHERE id = ?")
           ->execute([$batchId]);

        // drafts 삭제 (audit는 change_logs + runs 메타로 충분)
        $db->prepare("DELETE FROM coach_assignment_drafts WHERE batch_id = ?")->execute([$batchId]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jsonError('확정 실패: ' . $e->getMessage(), 500);
    }

    jsonSuccess([
        'ok' => true,
        'matched_count' => count($matched),
    ]);
}
```

- [ ] **Step 2: `php -l` 검증**

Run: `php -l /var/www/html/_______site_SORITUNECOM_PT/public_html/api/matching.php`

- [ ] **Step 3: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/matching.php
git commit -m "$(cat <<'EOF'
feat(matching): API confirm — 트랜잭션 commit + 감사 로그

inactive 코치 사전 검사. orders.coach_id update + status='매칭완료',
coach_assignments INSERT, change_logs target_type='order' INSERT 매 row,
runs.status='confirmed'. 미매칭 drafts는 CASCADE로 정리(orders 그대로).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

(통합 검증은 Task 13.)

---

### Task 7: Admin 사이드바 + `matching.js` 골격

**Files:**
- Modify: `public_html/admin/index.php` (사이드바 + 스크립트 로드)
- Create: `public_html/admin/js/pages/matching.js`

- [ ] **Step 1: 사이드바 링크 추가**

`public_html/admin/index.php` 사이드바에서 "리텐션관리" 다음 줄에 "매칭관리" 추가. retention 링크 패턴을 그대로 따른다 (예: `<a href="#matching">매칭관리</a>`).

검색 후 추가 위치 확인:
```bash
grep -n "리텐션관리\|retention" /var/www/html/_______site_SORITUNECOM_PT/public_html/admin/index.php | head
```

리텐션 link 다음에 한 줄 추가하고, body 끝의 `<script src=".../retention.js">` 다음에:
```html
<script src="js/pages/matching.js"></script>
```

- [ ] **Step 2: `matching.js` 골격 — registerPage + state**

Create `public_html/admin/js/pages/matching.js`:

```javascript
/* PT 매칭 관리 페이지 — '#matching' 라우트
 *
 * 기능:
 *  - active draft 없으면: base_month 드롭다운 + preview 카운트 + "매칭 실행" 버튼
 *  - active draft 있으면: capacity 진행도 카드 + 테이블(드롭다운 인라인 편집) + 확정/폐기
 *
 * API: /api/matching.php?action=...
 * UI helpers: API.get/post, UI.esc/toast, App.registerPage
 */

App.registerPage('matching', {
  state: {
    current: null,          // {run, drafts} 또는 null
    coaches: [],            // 드롭다운용 active 코치 전체
    isMounted: () => !!document.querySelector('#pageContent[data-page="matching"]'),
  },

  async render() {
    const root = document.getElementById('pageContent');
    root.dataset.page = 'matching';
    root.innerHTML = '<div class="loading">로딩 중...</div>';

    try {
      const [coachesRes, currentRes] = await Promise.all([
        API.get('/api/coaches.php?action=list'),
        API.get('/api/matching.php?action=current'),
      ]);
      if (!this.isMounted()) return;
      if (!coachesRes.ok || !currentRes.ok) {
        root.innerHTML = `<div class="empty-state">데이터 로드 실패</div>`;
        return;
      }
      this.state.coaches = (coachesRes.data.coaches || []).filter(c => c.status === 'active');
      this.state.current = currentRes.data.current;
      this.renderBody();
    } catch (e) {
      root.innerHTML = `<div class="empty-state">${UI.esc(e.message || '오류')}</div>`;
    }
  },

  renderBody() {
    const root = document.getElementById('pageContent');
    if (!root) return;
    if (!this.state.current) {
      this.renderEmptyState(root);
    } else {
      this.renderDraftReview(root);
    }
  },

  renderEmptyState(root) { /* Task 8 */ root.innerHTML = '<div>(empty state — Task 8)</div>'; },
  renderDraftReview(root) { /* Task 9~11 */ root.innerHTML = '<div>(draft review — Task 9~11)</div>'; },
});
```

`isMounted()`는 retention.js 패턴과 동일 — async 연속점에서 사용자가 다른 탭으로 이동했을 때 stale-DOM write 방지.

- [ ] **Step 3: 브라우저에서 라우팅 동작 확인**

브라우저:
1. admin 로그인
2. 사이드바 "매칭관리" 클릭 → URL hash가 `#matching` 으로 변경
3. 화면에 `(empty state — Task 8)` 또는 `(draft review — Task 9~11)`가 표시되면 골격 OK

⚠️ 자동 검증 어려움 — 사용자에게 화면 확인 요청.

- [ ] **Step 4: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/admin/index.php public_html/admin/js/pages/matching.js
git commit -m "$(cat <<'EOF'
feat(matching): 사이드바 + matching.js 골격 (registerPage)

state.current/coaches 로드 + renderBody 분기(empty/review).
isMounted 가드 패턴(retention.js와 동일)으로 stale-DOM write 방지.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: 빈 상태 화면 — base_month 드롭다운 + preview + "매칭 실행"

**Files:**
- Modify: `public_html/admin/js/pages/matching.js` (renderEmptyState 본구현)

- [ ] **Step 1: `renderEmptyState` 본구현**

Replace stub with:

```javascript
async renderEmptyState(root) {
  const [previewRes, monthsRes] = await Promise.all([
    API.get('/api/matching.php?action=preview'),
    API.get('/api/matching.php?action=base_months'),
  ]);
  if (!this.isMounted()) return;
  const unmatchedCount = (previewRes.ok && previewRes.data.unmatched_orders) || 0;
  const months = (monthsRes.ok && monthsRes.data.base_months) || [];

  const monthOpts = months.map(m => `<option value="${UI.esc(m)}">${UI.esc(m)}</option>`).join('');
  const canStart = unmatchedCount > 0 && months.length > 0;

  root.innerHTML = `
    <div class="page-header"><h1>매칭 관리</h1></div>
    <div class="match-empty-state">
      <div class="match-empty-stat">
        <div class="value">${unmatchedCount}</div>
        <div class="label">매칭대기 주문</div>
      </div>
      <div class="match-empty-form">
        <label>기준월 (final_allocation 사용):</label>
        <select id="match_baseMonth" ${months.length===0 ? 'disabled' : ''}>
          ${months.length===0 ? '<option>(리텐션 스냅샷 없음)</option>' : monthOpts}
        </select>
        <button id="match_startBtn" class="btn btn-primary" ${canStart ? '' : 'disabled'}>
          매칭 실행
        </button>
        ${unmatchedCount===0 ? '<p class="hint">매칭대기 상태의 주문이 없습니다.</p>' : ''}
        ${months.length===0 ? '<p class="hint">기준월로 사용할 리텐션 스냅샷이 없습니다. 먼저 리텐션 관리에서 계산해주세요.</p>' : ''}
      </div>
    </div>
  `;

  if (canStart) {
    document.getElementById('match_startBtn').addEventListener('click', async (e) => {
      e.target.disabled = true;
      e.target.textContent = '실행 중...';
      const baseMonth = document.getElementById('match_baseMonth').value;
      const res = await API.post('/api/matching.php?action=start', { base_month: baseMonth });
      if (!this.isMounted()) return;
      if (!res.ok) {
        UI.toast(res.message || '매칭 실행 실패');
        e.target.disabled = false;
        e.target.textContent = '매칭 실행';
        return;
      }
      // 응답이 current 형태로 옴
      this.state.current = res.data.current;
      this.renderBody();
    });
  }
},
```

- [ ] **Step 2: 브라우저 확인**

1. 매칭관리 진입 → 빈 상태 화면이 그려지는지
2. unmatched_orders 카드에 숫자 표시
3. base_month 드롭다운 옵션 = `coach_retention_runs`의 base_month 리스트
4. 둘 다 있으면 "매칭 실행" 버튼 활성, 둘 중 하나 없으면 비활성 + hint

⚠️ 사용자 검증.

- [ ] **Step 3: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/admin/js/pages/matching.js
git commit -m "$(cat <<'EOF'
feat(matching): 빈 상태 화면 — preview + base_month 드롭다운 + 매칭 실행 버튼

unmatched_orders=0 또는 base_months=[]면 버튼 비활성 + hint.
실행 직후 응답(current 형태)으로 곧장 검토 화면 전환.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: 검토 테이블 + 코치 드롭다운 (인라인 편집)

**Files:**
- Modify: `public_html/admin/js/pages/matching.js` (renderDraftReview + 드롭다운 핸들러)

- [ ] **Step 1: `renderDraftReview` 본구현 — 헤더 + 테이블 + 드롭다운**

Replace stub:

```javascript
renderDraftReview(root) {
  const cur = this.state.current;
  const run = cur.run;
  const drafts = cur.drafts;

  // (capacity 카드는 Task 10에서, 확정/취소 버튼은 Task 11에서. 우선 테이블 위주.)
  root.innerHTML = `
    <div class="page-header"><h1>매칭 관리 · Batch #${run.id}</h1></div>
    <div class="match-summary-bar">
      <span>기준월: <strong>${UI.esc(run.base_month)}</strong></span>
      <span>총 ${run.total_orders}</span>
      <span class="match-source-prev">이전코치 ${run.prev_coach_count}</span>
      <span class="match-source-pool">신규풀 ${run.new_pool_count}</span>
      <span class="match-source-unmatched">미매칭 ${run.unmatched_count}</span>
      <span id="match_actions"></span>
    </div>
    <div id="match_capacityCards"></div>
    <table class="match-table">
      <thead>
        <tr>
          <th>회원</th><th>상품</th><th>start_date</th>
          <th>source</th><th>이전 코치</th><th>제안 코치</th><th>비고</th>
        </tr>
      </thead>
      <tbody id="match_tbody">
        ${drafts.map(d => this._renderDraftRow(d)).join('')}
      </tbody>
    </table>
  `;
  this._bindDraftDropdowns();
},

_renderDraftRow(d) {
  const sourceClass = `match-source-${d.source}`;
  const proposedSelect = this._coachSelectHTML(d.id, d.proposed_coach_id);
  return `
    <tr data-draft-id="${d.id}" class="${sourceClass}">
      <td>${UI.esc(d.member_name)}</td>
      <td>${UI.esc(d.product_name)}</td>
      <td>${UI.esc(d.start_date)}</td>
      <td><span class="match-source-badge ${sourceClass}">${UI.esc(d.source)}</span></td>
      <td>${UI.esc(d.prev_coach_name || '—')}</td>
      <td>${proposedSelect}</td>
      <td><span class="match-reason">${UI.esc(d.reason || '')}</span></td>
    </tr>
  `;
},

_coachSelectHTML(draftId, currentCoachId) {
  const opts = ['<option value="">— (미매칭) —</option>']
    .concat(this.state.coaches.map(c =>
      `<option value="${c.id}" ${c.id===currentCoachId ? 'selected' : ''}>${UI.esc(c.coach_name)}</option>`
    ));
  return `<select class="match-coach-dropdown" data-draft-id="${draftId}">${opts.join('')}</select>`;
},

_bindDraftDropdowns() {
  document.querySelectorAll('.match-coach-dropdown').forEach(sel => {
    sel.addEventListener('change', async (e) => {
      const draftId = parseInt(sel.dataset.draftId, 10);
      const v = sel.value;
      const newCoachId = v === '' ? null : parseInt(v, 10);
      sel.disabled = true;
      const res = await API.post('/api/matching.php?action=update_draft',
        { draft_id: draftId, proposed_coach_id: newCoachId });
      if (!this.isMounted()) return;
      sel.disabled = false;
      if (!res.ok) { UI.toast(res.message || '저장 실패'); return; }
      this._mergeDraftRow(res.data.row);
    });
  });
},

_mergeDraftRow(row) {
  const idx = this.state.current.drafts.findIndex(d => d.id === row.id);
  if (idx >= 0) {
    this.state.current.drafts[idx] = { ...this.state.current.drafts[idx], ...row };
  }
  // tbody 해당 row만 다시 그림
  const tr = document.querySelector(`tr[data-draft-id="${row.id}"]`);
  if (tr) tr.outerHTML = this._renderDraftRow(this.state.current.drafts[idx]);
  this._bindDraftDropdowns();  // 새 셀렉트 재바인딩
  // 카드/요약은 Task 10에서 같이 갱신
},
```

- [ ] **Step 2: 브라우저 확인**

1. 매칭관리 → 매칭 실행 → 검토 화면 진입
2. 테이블에 모든 drafts가 source별 배지와 함께 표시
3. 드롭다운에서 다른 코치 선택 → 저장 후 같은 row의 source 배지가 'manual_override'로 바뀜, reason "수동 조정 (이전: ...)" 표시
4. 드롭다운에서 "(미매칭)" 선택 → source 'unmatched', proposed 코치명 비어있음

- [ ] **Step 3: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/admin/js/pages/matching.js
git commit -m "$(cat <<'EOF'
feat(matching): 검토 테이블 + 인라인 코치 드롭다운

행별 select 변경 → update_draft API 호출 → mergeRow로 해당 row만
다시 그림 (전체 rerender 회피, retention 패턴 일관). source 배지로
이전코치/신규풀/수동조정/미매칭 색상 구분.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: capacity 진행도 카드 + summary 동기화

**Files:**
- Modify: `public_html/admin/js/pages/matching.js`

- [ ] **Step 1: `_renderCapacityCards` 추가 + `renderDraftReview`에서 호출**

`renderDraftReview` 마지막 줄(`this._bindDraftDropdowns();`) 직전에 한 줄 추가:

```javascript
this._renderCapacityCards();
```

그리고 메서드 추가:

```javascript
_renderCapacityCards() {
  const host = document.getElementById('match_capacityCards');
  if (!host) return;
  const snap = this.state.current.run.capacity_snapshot || [];

  // 코치별 used 계산: drafts 중 source IN (new_pool, manual_override) + 매칭된 (proposed_coach_id 일치)
  const used = {};
  this.state.current.drafts.forEach(d => {
    if (!d.proposed_coach_id) return;
    if (d.source === 'previous_coach') return; // 이전 코치는 capacity 카드에 카운트 안 함 (별개 풀)
    used[d.proposed_coach_id] = (used[d.proposed_coach_id] || 0) + 1;
  });

  if (snap.length === 0) {
    host.innerHTML = `<div class="match-capacity-empty">⚠️ 기준월 ${UI.esc(this.state.current.run.base_month)}에 final_allocation > 0 인 active 코치가 없습니다. 신규는 모두 미매칭 처리됩니다.</div>`;
    return;
  }

  host.innerHTML = `
    <div class="match-capacity-grid">
      ${snap.map(c => {
        const u = used[c.coach_id] || 0;
        const over = u > c.final_allocation;
        return `
          <div class="match-capacity-card ${over ? 'over' : ''}">
            <div class="name">${UI.esc(c.coach_name)}</div>
            <div class="value">${u} / ${c.final_allocation}</div>
          </div>
        `;
      }).join('')}
    </div>
  `;
},
```

- [ ] **Step 2: `_mergeDraftRow`에서 capacity 카드 + summary 갱신**

`_mergeDraftRow` 끝에 추가:

```javascript
this._renderCapacityCards();
this._updateSummaryBar();
```

`_updateSummaryBar` 메서드 추가:

```javascript
_updateSummaryBar() {
  // drafts 기준으로 카운트 재계산 (운영자가 source를 바꿀 때마다 sticky 바 갱신)
  const drafts = this.state.current.drafts;
  const counts = { previous_coach: 0, new_pool: 0, manual_override: 0, unmatched: 0 };
  drafts.forEach(d => { counts[d.source] = (counts[d.source] || 0) + 1; });
  const bar = document.querySelector('.match-summary-bar');
  if (!bar) return;
  // run의 prev_coach_count/new_pool_count는 batch 생성 시점 스냅샷이라 그대로 두고,
  // 현재 상태는 별도 표시.
  // (단순화: summary-bar는 run 시점 값을 유지, 카드만 동적으로 갱신)
  // → 아무것도 안 함. capacity card가 동적 정보를 담당.
},
```

(설계 단순화: summary bar는 batch 생성 시점 값 고정. 동적 정보는 capacity card 한 곳에만 — 정보 분산 회피.)

- [ ] **Step 3: 브라우저 확인**

1. 검토 화면에서 코치별 capacity 카드 그리드가 보임
2. used 카운트 = 신규풀/수동조정 source의 매칭 수 (이전 코치는 카운트 안 됨)
3. used > final_allocation 코치는 카드가 빨간색 (`over` class)
4. 드롭다운으로 한 행을 다른 코치로 옮기면 카드 used 숫자가 즉시 갱신

- [ ] **Step 4: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/admin/js/pages/matching.js
git commit -m "$(cat <<'EOF'
feat(matching): capacity 진행도 카드 + 동적 갱신

신규풀/manual_override만 카운트(이전 코치 별개 풀이라 capacity와 무관).
final_allocation 초과 시 카드 빨간 강조. 드롭다운 변경 후 즉시 재렌더.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: 확정 / 취소 버튼

**Files:**
- Modify: `public_html/admin/js/pages/matching.js`

- [ ] **Step 1: `renderDraftReview`에 액션 버튼 삽입 + 핸들러**

`renderDraftReview` 안의 `<span id="match_actions"></span>` 자리에 `_bindDraftDropdowns()` 호출 직전에 다음 코드 추가:

```javascript
document.getElementById('match_actions').innerHTML = `
  <button id="match_confirmBtn" class="btn btn-primary">확정</button>
  <button id="match_cancelBtn"  class="btn btn-outline">이 batch 폐기</button>
`;
this._bindActionButtons();
```

새 메서드 추가:

```javascript
_bindActionButtons() {
  document.getElementById('match_confirmBtn').addEventListener('click', async (e) => {
    if (!confirm(`Batch #${this.state.current.run.id} 매칭 결과를 확정합니다. 계속할까요?`)) return;
    e.target.disabled = true;
    e.target.textContent = '확정 중...';
    const res = await API.post('/api/matching.php?action=confirm',
      { batch_id: this.state.current.run.id });
    if (!this.isMounted()) return;
    if (!res.ok) {
      UI.toast(res.message || '확정 실패');
      e.target.disabled = false;
      e.target.textContent = '확정';
      return;
    }
    UI.toast(`${res.data.matched_count}건 매칭 확정 완료`);
    this.state.current = null;
    this.renderBody();   // 빈 상태로 돌아감
  });
  document.getElementById('match_cancelBtn').addEventListener('click', async (e) => {
    if (!confirm(`Batch #${this.state.current.run.id}를 통째로 폐기합니다. 되돌릴 수 없습니다. 계속할까요?`)) return;
    e.target.disabled = true;
    e.target.textContent = '폐기 중...';
    const res = await API.post('/api/matching.php?action=cancel',
      { batch_id: this.state.current.run.id });
    if (!this.isMounted()) return;
    if (!res.ok) {
      UI.toast(res.message || '폐기 실패');
      e.target.disabled = false;
      e.target.textContent = '이 batch 폐기';
      return;
    }
    UI.toast('Batch 폐기 완료');
    this.state.current = null;
    this.renderBody();
  });
},
```

- [ ] **Step 2: 브라우저 확인**

1. "확정" 클릭 → confirm OK → 빈 상태 화면으로 복귀, 토스트
2. "이 batch 폐기" 클릭 → confirm OK → 빈 상태 화면으로 복귀, 토스트
3. 두 버튼 모두 confirm Cancel 시 아무 일도 일어나지 않음

- [ ] **Step 3: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/admin/js/pages/matching.js
git commit -m "$(cat <<'EOF'
feat(matching): 확정/폐기 버튼 + 핸들러

confirm 다이얼로그 → API 호출 → 성공 시 state.current=null + renderBody()로
빈 상태 복귀. inactive 코치 등 confirm 실패는 토스트로 표시.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: CSS 스타일

**Files:**
- Modify: `public_html/assets/css/style.css` (append)

- [ ] **Step 1: style.css 끝에 매칭 블록 추가**

Append:

```css
/* ===== Matching Management ===== */

.match-empty-state {
  display: flex; gap: 32px; align-items: center;
  padding: 24px; background: var(--card-bg, #1a1a1a); border-radius: 8px;
}
.match-empty-stat .value { font-size: 48px; font-weight: 700; color: var(--accent, #FF5E00); }
.match-empty-stat .label { color: var(--text-secondary, #999); }
.match-empty-form { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.match-empty-form .hint { color: var(--text-secondary, #999); margin-top: 8px; flex-basis: 100%; }

.match-summary-bar {
  display: flex; gap: 16px; align-items: center; flex-wrap: wrap;
  padding: 12px 16px; background: var(--card-bg, #1a1a1a); border-radius: 6px;
  margin-bottom: 16px;
  position: sticky; top: 0; z-index: 10;
}
.match-summary-bar > span { color: var(--text-secondary, #999); }
.match-summary-bar > span strong { color: var(--text-primary, #fff); }
.match-summary-bar #match_actions { margin-left: auto; display: flex; gap: 8px; }

.match-source-previous_coach   { color: #4caf50; }
.match-source-new_pool         { color: #2196f3; }
.match-source-manual_override  { color: #ffc107; }
.match-source-unmatched        { color: #f44336; }

.match-source-badge {
  display: inline-block; padding: 2px 8px; border-radius: 12px;
  font-size: 12px; font-weight: 600;
  background: rgba(255,255,255,0.05);
}

.match-table { width: 100%; border-collapse: collapse; }
.match-table th, .match-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #2a2a2a; }
.match-table tr.match-source-unmatched td { background: rgba(244, 67, 54, 0.05); }
.match-coach-dropdown { background: #222; color: #fff; border: 1px solid #444; padding: 4px 8px; border-radius: 4px; }
.match-reason { color: var(--text-secondary, #999); font-size: 12px; }

.match-capacity-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 8px; margin-bottom: 16px;
}
.match-capacity-card {
  background: var(--card-bg, #1a1a1a); padding: 12px; border-radius: 6px;
  border-left: 3px solid #2196f3;
}
.match-capacity-card.over { border-left-color: #f44336; background: rgba(244, 67, 54, 0.08); }
.match-capacity-card .name  { color: var(--text-secondary, #999); font-size: 12px; }
.match-capacity-card .value { font-size: 18px; font-weight: 600; color: var(--text-primary, #fff); }

.match-capacity-empty {
  padding: 12px 16px; background: rgba(244, 67, 54, 0.1);
  border: 1px solid #f44336; border-radius: 6px; margin-bottom: 16px;
  color: #ff8a80;
}
```

- [ ] **Step 2: 브라우저 확인**

1. 빈 상태 화면 — 큰 숫자 카드 + 폼 가로 정렬
2. 검토 화면 — 상단 sticky 요약바, 색상 구분된 source 배지, capacity 카드 그리드
3. 미매칭 행 → 빨간색 배경
4. capacity 초과 카드 → 빨간 border-left + 배경

- [ ] **Step 3: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/assets/css/style.css
git commit -m "$(cat <<'EOF'
style(matching): 매칭 UI CSS — 빈 상태 / 요약바 / 테이블 / 카드 / source 배지

source별 색상(이전코치 초록 · 신규풀 파랑 · 수동조정 노랑 · 미매칭 빨강).
capacity 초과 카드는 빨간 border-left + 배경. 미매칭 row는 빨간 배경 강조.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 13: 통합 수동 검증 체크리스트

**Files:** 없음 (어드민이 브라우저+DB로 검증 후 결과 보고)

본 batch 검증 시 영향 받는 데이터: `orders`, `coach_assignments`, `change_logs`, `coach_assignment_runs`, `coach_assignment_drafts`. 검증 전 DB 스냅샷 권장.

- [ ] **Step 1: 빈 상태 검증**

매칭대기 0건 시: 매칭관리 진입 → 빈 상태 카드에 0 표시, "매칭 실행" 버튼 비활성, hint "매칭대기 상태의 주문이 없습니다".

- [ ] **Step 2: 매칭 대상 준비 + start**

테스트용 매칭대기 order 5~10건이 있는 상태에서 (없으면 admin이 import_orders로 생성):
- 매칭관리 진입 → unmatched_orders 카드가 정확히 N
- base_month 드롭다운에 `coach_retention_runs`의 월 리스트
- "매칭 실행" 클릭 → 검토 화면 진입

DB 검증:
```bash
mysql -uroot -p"$(grep '^DB_PASS=' /var/www/html/_______site_SORITUNECOM_PT/.db_credentials | cut -d= -f2- | tr -d '"')" -e "
SELECT id, status, total_orders, prev_coach_count, new_pool_count, matched_count, unmatched_count, capacity_snapshot
  FROM SORITUNECOM_PT.coach_assignment_runs ORDER BY id DESC LIMIT 1\G
SELECT batch_id, source, COUNT(*) AS cnt FROM SORITUNECOM_PT.coach_assignment_drafts
 GROUP BY batch_id, source ORDER BY batch_id DESC, source;
"
```
Expected: status='draft', total_orders == drafts 합계, capacity_snapshot에 active 코치들의 final_allocation 들어있음.

- [ ] **Step 3: 이전 코치 룰 검증 (4가지 분기)**

drafts에서 다음을 SQL로 확인:
- `source='previous_coach'` 행이 있는지: 사람으로 한 두 case 골라 prev_coach_id가 정말로 직전 정상 order의 coach_id와 일치하는지 확인
- 같은 member에 직전 정상 order 없는 신규 회원 → `source='new_pool'`, `prev_coach_id=NULL`, reason="이전 PT 이력 없음"
- 직전 코치 inactive로 변경한 회원이 있다면 → `source='new_pool'`, reason "이전 코치 inactive"
- gap 365일 이상인 회원 → `source='new_pool'`, reason "이전 PT 종료 후 N일 경과"

- [ ] **Step 4: 신규 풀 분배 검증**

capacity_snapshot 합 vs `source IN ('new_pool','unmatched')` row 수 비교:
- 합 == 풀: 모두 매칭, `source='unmatched'` 0건
- 합 > 풀: 일부 코치 정원 미달 — capacity 카드에 used < final_allocation 표시
- 합 < 풀: 일부 row `source='unmatched'` + capacity 카드 used 합 == 신규 capacity 합

- [ ] **Step 5: 수동 수정**

검토 화면에서 한 행의 드롭다운을 다른 코치로 변경:
- 화면: source 배지가 'manual_override'로, reason "수동 조정 (이전: ...)"
- DB: drafts 해당 row의 source/proposed_coach_id/reason/updated_at 갱신
- capacity 카드 used 즉시 변동

미매칭 행에 코치 지정 → source 'manual_override' 정상 처리.

기지정 행을 "(미매칭)"으로 비움 → source 'unmatched', proposed_coach_id NULL.

- [ ] **Step 6: Capacity snapshot 격리 검증**

별도 탭/창에서 리텐션 관리 진입 → 매칭 batch와 같은 base_month의 한 코치 final_allocation을 +5 변경 → 매칭 화면 capacity 카드는 **변하지 않아야** 함 (snapshot 기준).

- [ ] **Step 7: inactive 코치 confirm 시점 검사**

drafts 중 한 코치를 별도 SQL로 inactive로 변경:
```bash
mysql -uroot -p"..." -e "UPDATE SORITUNECOM_PT.coaches SET status='inactive' WHERE id=<X>;"
```

매칭 화면에서 "확정" → 409 + 토스트 "확정 실패: 다음 코치가 inactive 입니다 — ...". 코치 다시 active로 원복:
```bash
mysql ... "UPDATE coaches SET status='active' WHERE id=<X>;"
```

- [ ] **Step 8: 확정 (Confirm)**

"확정" 클릭 → confirm OK → 토스트 + 빈 상태로 복귀

DB 검증:
```bash
mysql -uroot -p"..." -e "
SELECT status, confirmed_at, matched_count FROM coach_assignment_runs ORDER BY id DESC LIMIT 1;
SELECT COUNT(*) AS drafts_remaining FROM coach_assignment_drafts;  -- 0이어야
SELECT id, coach_id, status FROM orders WHERE status='매칭완료' ORDER BY updated_at DESC LIMIT 10;
SELECT member_id, coach_id, order_id, reason FROM coach_assignments WHERE assigned_at > NOW() - INTERVAL 5 MINUTE ORDER BY assigned_at DESC LIMIT 10;
SELECT target_id, action, JSON_EXTRACT(new_value, '$.source') AS source, JSON_EXTRACT(new_value, '$.batch_id') AS batch
  FROM change_logs WHERE target_type='order' AND action='coach_assigned' ORDER BY id DESC LIMIT 10;
"
```
Expected:
- runs status='confirmed', confirmed_at 채워짐, matched_count 일치
- drafts_remaining = 0
- orders matched ones의 coach_id/status='매칭완료' 정확
- coach_assignments 새 row들의 reason='auto_match:previous_coach|new_pool|manual_override'
- change_logs target_type='order', action='coach_assigned', new_value에 source + batch_id 포함

미매칭 신규 orders: status='매칭대기' 그대로 유지되어야 함.

- [ ] **Step 9: 취소 (Cancel)**

새 batch 시작 → "이 batch 폐기" 클릭 → 토스트 + 빈 상태로 복귀

DB:
```bash
mysql -uroot -p"..." -e "
SELECT status, cancelled_at FROM coach_assignment_runs ORDER BY id DESC LIMIT 1;
SELECT COUNT(*) FROM coach_assignment_drafts WHERE batch_id=<id>;  -- 0
SELECT COUNT(*) FROM orders WHERE status='매칭대기';  -- 폐기 직전과 동일해야
"
```

- [ ] **Step 10: active draft 1개 제약**

하나의 draft가 떠있는 상태에서 별도 탭/curl로 `?action=start` 시도 → 409 + 메시지 "이미 진행 중인 draft batch가 있습니다 (#N)".

- [ ] **Step 11: 결과 보고 + 메모리 업데이트**

위 9개 시나리오 모두 통과면 사용자가 메모리에 PT 매칭 완료 기록. 일부 실패 시 해당 단계의 발견 사항 정리 후 fix commit.

---

## Self-Review

작성 후 spec과 대조:

- §3 결정사항 표 Q1~Q10 모두 어떤 task에 매핑됨:
  - Q1 draft → confirm: Task 6 + 8
  - Q2 이전 코치 식별: Task 2 `_classifyOrder`
  - Q3 1년 기준: Task 2 `gap_days >= 365`
  - Q4 통합 처리: Task 2 (1년/inactive 모두 new_pool)
  - Q5 별개 풀: Task 2 + Task 10 (이전코치 capacity 카운트 제외)
  - Q6 staging + batch 메타: Task 1 + 4
  - Q7 한 번에 1 active draft: Task 4 (`_actionStart` 검사)
  - Q8 분배 알고리즘 + 미매칭 처리: Task 2 `_distributeNewPool`
  - Q9 행별 드롭다운: Task 9
  - Q10 capacity_snapshot: Task 4 (snapshot 잡기) + Task 10 (카드 표시)
- §11 Edge cases:
  - 매칭대기 0건 → Task 4 가드 + Task 8 비활성화 ✓
  - inactive 코치 confirm 시점 검사 → Task 6 ✓
  - confirm 도중 fatal → Task 6 트랜잭션 ✓
  - capacity 0 → Task 10 빈 상태 경고 ✓
  - draft 동시 편집(last-write-wins) → Task 5 (낙관적 락 미적용) ✓
- 플레이스홀더: 없음 (`(empty state — Task 8)` 같은 stub은 다음 task에서 명시적 교체 명령)
- 타입 일관성: `_classifyOrder` 반환 형식이 `_distributeNewPool` 입력과 일치 ✓; `coach_id`/`coach_name`/`final_allocation` 컬럼명 일관 ✓

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-04-28-pt-matching.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — fresh subagent per task + two-stage review. 13 task 모두 spec compliance + code quality 게이트 통과 후 main에 누적 커밋. retention 작업과 동일 패턴.

**2. Inline Execution** — 본 세션에서 executing-plans로 batch 실행 + checkpoint. 토큰 비용↑.

**Which approach?**
