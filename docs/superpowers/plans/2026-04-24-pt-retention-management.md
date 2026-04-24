# PT 리텐션 관리 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `coach.soritune.com`의 리텐션 계산 기능을 `pt.soritune.com` admin의 "리텐션관리" 탭으로 이식하고, 수동 조정 UI를 추가한다. coach 사이트의 코치 희망 신청 화면에서 본인 등급 표시를 즉시 제거한다.

**Architecture:** PT가 리텐션 결과의 주인. 모수(coach_member_mapping·criteria·희망신청)는 같은 MySQL 인스턴스의 `SORITUNECOM_COACH` 스키마에서 직접 읽음 (cross-DB SELECT). 코치 매핑은 영문명(PT `coach_name` ↔ COACH `name`) 정확 일치. 자동 배분은 coach 사이트 로직을 그대로 이식하되 기준월을 한 달 시프트하여 측정 완료된 최신 3구간만 집계. 수동 조정은 `updated_at` 낙관적 락으로 경합 보호.

**Tech Stack:** PHP 8+ / MariaDB / vanilla JS SPA (Pretendard · Spotify 다크 테마 · Soritune Orange `#FF5E00`)

**Spec:** `docs/superpowers/specs/2026-04-24-pt-retention-management-design.md`

**Design System:** `CLAUDE.md` (Spotify 다크 테마)

**DB Credentials:** `.db_credentials` in project root (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`)

---

## File Map

### Phase 1 — coach 사이트 hotfix (별도 시스템, git 없음)

| File | Change |
|------|--------|
| `/var/www/html/_______site_SORITUNECOM_COACH/public_html/coach/request.php` | 신청 이력 테이블의 "등급" 컬럼 표시 제거 (DB 저장은 유지) |

### Phase 2 — PT 레포

**Shared**
| File | 책임 |
|------|------|
| `migrations/20260424_add_coach_retention.sql` (create) | 테이블·ENUM 확장 마이그레이션 |
| `migrations/20260424_grant_coach_readonly.sql` (create) | PT MySQL 유저에게 `SORITUNECOM_COACH` 리드온리 GRANT |
| `schema.sql` (modify) | 신규 테이블·ENUM 확장 반영 (미래 신규 설치용) |

**Backend**
| File | 책임 |
|------|------|
| `public_html/includes/coach_mapping.php` (create) | PT coach_name ↔ COACH coach_id 양방향 매핑 로딩 + unmapped 리스트 수집 |
| `public_html/includes/retention_calc.php` (create) | 리텐션 계산 로직 (월 시프트 포함). UPSERT까지 담당 |
| `public_html/api/retention.php` (create) | action 라우팅 API (snapshots / view / calculate / update_allocation / reset_allocation / delete_snapshot) |

**Frontend**
| File | 책임 |
|------|------|
| `public_html/admin/js/pages/retention.js` (create) | 리텐션 관리 탭 SPA 페이지 |
| `public_html/admin/index.php` (modify) | 사이드바 링크 + 스크립트 로드 |
| `public_html/assets/css/style.css` (modify) | 리텐션 UI 스타일 (sticky 요약, 등급 badge, 잔여 색상, 충돌 토스트) |

**Docs**
| File | 책임 |
|------|------|
| `docs/superpowers/plans/2026-04-24-pt-retention-management.md` (this file) | 본 계획서 |

---

## Tasks

### Task 1: [Phase 1] coach 사이트 request.php 등급 숨김 (hotfix)

**Files:**
- Modify: `/var/www/html/_______site_SORITUNECOM_COACH/public_html/coach/request.php`

참고: coach 사이트는 별도 git 저장소가 없다. 직접 파일 수정 후 즉시 prod 반영된다. PT 레포의 git 플로우와 무관.

- [ ] **Step 1: 수정 전 현재 상태 확인**

Run:
```bash
sed -n '125,145p' /var/www/html/_______site_SORITUNECOM_COACH/public_html/coach/request.php
```
Expected: 128번 줄에 `<th>등급</th>`, 134–137번 줄에 `$badgeMap`/`$bc` PHP, 141–142번 줄에 `<td><span class="badge …">` 셀이 보여야 함.

- [ ] **Step 2: 신청 이력 표의 "등급" 컬럼 제거**

Edit `public_html/coach/request.php`:

**제거 대상 1** — 테이블 헤더 (line 128):
```php
<th>등급</th>
```

**제거 대상 2** — 행 안에서 등급 badge 변수 초기화 (line 134–137):
```php
<?php foreach ($history as $h):
    $badgeMap = ['A+'=>'badge-ap','A'=>'badge-a','B'=>'badge-b','C'=>'badge-c','D'=>'badge-d'];
    $bc = $badgeMap[$h['grade_at_request'] ?? ''] ?? 'badge-d';
?>
```
→ 다음으로 교체:
```php
<?php foreach ($history as $h): ?>
```

**제거 대상 3** — `<td>` 셀 (line 141–142):
```php
<td><span class="badge <?= $bc ?>"><?= e($h['grade_at_request'] ?? '-') ?></span></td>
```
→ 통째로 삭제.

DB 저장 로직(33~36, 42번 줄의 `grade_at_request` INSERT)은 **유지** — admin 페이지가 계속 참조한다.

- [ ] **Step 3: PHP 문법 체크**

Run:
```bash
php -l /var/www/html/_______site_SORITUNECOM_COACH/public_html/coach/request.php
```
Expected: `No syntax errors detected`

- [ ] **Step 4: 수동 검증 (코치 플로우 & admin 플로우 동시 확인)**

1. 브라우저에서 `https://coach.soritune.com/coach/request.php` 접속 → 코치 드롭다운에서 아무 코치나 선택 → **신청 이력 표에 "등급" 컬럼이 보이지 않아야 함**
2. `https://coach.soritune.com/admin/assignment.php` 접속 → **"등급" 컬럼이 여전히 보여야 함** (admin 페이지는 건드리지 않았으므로)
3. 테이블의 다른 컬럼(기간, 희망 인원, 상태, 확정 인원, 신청일)은 정상 표시돼야 함

- [ ] **Step 5: 배포 완료 보고**

coach 사이트는 git이 없으므로 파일 수정 = 즉시 운영 반영. Task 1 종료.

---

### Task 2: [Phase 2] PT DB 마이그레이션 파일 작성

**Files:**
- Create: `migrations/20260424_add_coach_retention.sql`
- Modify: `schema.sql`

- [ ] **Step 1: `migrations/` 디렉토리 생성 확인**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
ls migrations/ 2>/dev/null || mkdir migrations
```
Expected: `migrations/` 디렉토리 존재.

- [ ] **Step 2: 마이그레이션 파일 작성**

Create `migrations/20260424_add_coach_retention.sql`:

```sql
-- 2026-04-24: Coach retention management (PT 리텐션 관리)
-- Adds coach_retention_scores, coach_retention_runs tables.
-- Extends change_logs.target_type ENUM with 'retention_allocation'.
--
-- Apply:
--   mysql -u SORITUNECOM_PT -p SORITUNECOM_PT < migrations/20260424_add_coach_retention.sql
--
-- Rollback: manual (DROP TABLE + restore ENUM), no automated rollback script.

SET NAMES utf8mb4;

-- coach_retention_scores: one row per (coach, base_month). Snapshot of retention + grade + allocation.
CREATE TABLE IF NOT EXISTS `coach_retention_scores` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `coach_id` INT DEFAULT NULL,
  `coach_name_snapshot` VARCHAR(100) NOT NULL,
  `base_month` VARCHAR(7) NOT NULL,
  `grade` VARCHAR(5) DEFAULT NULL,
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
  `monthly_detail` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
                            ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY `uq_coach_month` (`coach_id`, `base_month`),
  FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL,
  INDEX `idx_base_month` (`base_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- coach_retention_runs: one row per base_month. Records the calculation inputs + unmapped coaches.
CREATE TABLE IF NOT EXISTS `coach_retention_runs` (
  `base_month` VARCHAR(7) PRIMARY KEY,
  `total_new` INT NOT NULL DEFAULT 0,
  `unmapped_coaches` LONGTEXT DEFAULT NULL,
  `calculated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `calculated_by` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extend change_logs.target_type ENUM.
ALTER TABLE `change_logs`
  MODIFY COLUMN `target_type`
    ENUM('member','order','coach_assignment','merge','retention_allocation') NOT NULL;
```

- [ ] **Step 3: `schema.sql`에도 반영 (미래 신규 설치용)**

Edit `schema.sql`:

**수정 1** — 상단 `DROP TABLE` 블록에 두 테이블 추가 (기존 drop 순서 유지, foreign key 고려해 references 테이블보다 먼저):

`DROP TABLE IF EXISTS 'admins';` 바로 위에 추가:
```sql
DROP TABLE IF EXISTS `coach_retention_runs`;
DROP TABLE IF EXISTS `coach_retention_scores`;
```

**수정 2** — `change_logs` CREATE TABLE의 `target_type` 줄을 찾아 ENUM 값 확장:

이전:
```sql
  `target_type` ENUM('member','order','coach_assignment','merge') NOT NULL,
```
이후:
```sql
  `target_type` ENUM('member','order','coach_assignment','merge','retention_allocation') NOT NULL,
```

**수정 3** — `migration_logs` CREATE 바로 위에 두 테이블 CREATE 문 추가 (마이그레이션 파일 Step 2와 동일한 DDL 블록. `IF NOT EXISTS` 대신 기본 형태로):

```sql
-- 12. coach_retention_scores
CREATE TABLE `coach_retention_scores` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `coach_id` INT DEFAULT NULL,
  `coach_name_snapshot` VARCHAR(100) NOT NULL,
  `base_month` VARCHAR(7) NOT NULL,
  `grade` VARCHAR(5) DEFAULT NULL,
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
  `monthly_detail` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
                            ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY `uq_coach_month` (`coach_id`, `base_month`),
  FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL,
  INDEX `idx_base_month` (`base_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. coach_retention_runs
CREATE TABLE `coach_retention_runs` (
  `base_month` VARCHAR(7) PRIMARY KEY,
  `total_new` INT NOT NULL DEFAULT 0,
  `unmapped_coaches` LONGTEXT DEFAULT NULL,
  `calculated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `calculated_by` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: 마이그레이션 SQL 문법 체크 (dry-run)**

Run:
```bash
source /var/www/html/_______site_SORITUNECOM_PT/.db_credentials
echo "START TRANSACTION; SOURCE migrations/20260424_add_coach_retention.sql; ROLLBACK;" | \
  mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>&1
```
Expected: 오류 메시지 없음. (ALTER TABLE은 implicit commit이 걸릴 수 있어 일부 DDL은 rollback되지 않지만, 문법 오류는 잡힌다.)

주의: 위 명령이 부분적으로 적용될 수 있으므로 실패 시 현재 상태를 반드시 `SHOW TABLES LIKE 'coach_retention%'`로 확인. 테이블이 생겼으면 DROP 후 다시 진행.

- [ ] **Step 5: 실제 마이그레이션 적용**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
source .db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < migrations/20260424_add_coach_retention.sql
```
Expected: 오류 없이 종료.

검증:
```bash
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  SHOW CREATE TABLE coach_retention_scores\G
  SHOW CREATE TABLE coach_retention_runs\G
  SHOW COLUMNS FROM change_logs LIKE 'target_type';
"
```
Expected: 두 테이블 DDL이 출력되고, `target_type` ENUM에 `'retention_allocation'` 포함.

- [ ] **Step 6: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add migrations/20260424_add_coach_retention.sql schema.sql
git commit -m "$(cat <<'EOF'
feat(db): add coach retention tables + migration

coach_retention_scores (snapshot per coach × base_month) with optimistic
lock column (updated_at DATETIME(3)) and coach_name_snapshot for deleted
coaches. coach_retention_runs (per base_month calc inputs + unmapped
coaches JSON). Extends change_logs.target_type ENUM with
'retention_allocation' for audit logging.
EOF
)"
```

---

### Task 3: [Phase 2] COACH DB 리드온리 GRANT

**Files:**
- Create: `migrations/20260424_grant_coach_readonly.sql`

- [ ] **Step 1: GRANT SQL 파일 작성**

Create `migrations/20260424_grant_coach_readonly.sql`:

```sql
-- 2026-04-24: Grant PT MySQL user read-only access to SORITUNECOM_COACH tables
-- needed by the retention management feature.
--
-- Apply (requires MySQL admin privileges, NOT the PT app user):
--   mysql -u root -p < migrations/20260424_grant_coach_readonly.sql

GRANT SELECT ON `SORITUNECOM_COACH`.`coaches`
  TO 'SORITUNECOM_PT'@'localhost';
GRANT SELECT ON `SORITUNECOM_COACH`.`coach_member_mapping`
  TO 'SORITUNECOM_PT'@'localhost';
GRANT SELECT ON `SORITUNECOM_COACH`.`retention_score_criteria`
  TO 'SORITUNECOM_PT'@'localhost';
GRANT SELECT ON `SORITUNECOM_COACH`.`grade_criteria`
  TO 'SORITUNECOM_PT'@'localhost';
GRANT SELECT ON `SORITUNECOM_COACH`.`coach_assignment_requests`
  TO 'SORITUNECOM_PT'@'localhost';

FLUSH PRIVILEGES;
```

- [ ] **Step 2: GRANT 적용 (MySQL admin 권한 필요)**

MySQL root 계정으로 실행 (사용자가 root 비밀번호를 알고 있어야 함):

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
mysql -u root -p < migrations/20260424_grant_coach_readonly.sql
```
Expected: 에러 없이 완료.

- [ ] **Step 3: PT 유저로 COACH DB 읽기 검증**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
source .db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" -e "
  SELECT COUNT(*) AS mappings FROM SORITUNECOM_COACH.coach_member_mapping;
  SELECT COUNT(*) AS criteria FROM SORITUNECOM_COACH.retention_score_criteria;
  SELECT COUNT(*) AS grades FROM SORITUNECOM_COACH.grade_criteria;
  SELECT COUNT(*) AS requests FROM SORITUNECOM_COACH.coach_assignment_requests;
  SELECT COUNT(*) AS coaches FROM SORITUNECOM_COACH.coaches;
"
```
Expected: 5개 카운트 모두 숫자로 조회됨 (특히 `mappings`, `coaches`는 수백~수천). Access denied가 나면 GRANT 실패.

- [ ] **Step 4: 쓰기 차단 검증**

Run:
```bash
source /var/www/html/_______site_SORITUNECOM_PT/.db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" -e "
  DELETE FROM SORITUNECOM_COACH.coach_member_mapping WHERE id = -999;
" 2>&1
```
Expected: `ERROR 1142 (42000): DELETE command denied to user 'SORITUNECOM_PT'@'localhost' for table 'coach_member_mapping'`. 즉 쓰기 권한 없음 확인.

- [ ] **Step 5: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add migrations/20260424_grant_coach_readonly.sql
git commit -m "chore(db): grant PT user read-only SELECT on COACH DB tables for retention"
```

---

### Task 4: [Phase 2] coach_mapping 헬퍼 작성

**Files:**
- Create: `public_html/includes/coach_mapping.php`

- [ ] **Step 1: 헬퍼 작성**

Create `public_html/includes/coach_mapping.php`:

```php
<?php
/**
 * Coach mapping between PT coaches and SORITUNECOM_COACH.coaches.
 *
 * Rule: PT `coach_name` (영문명) matches COACH `name` exactly (case-sensitive).
 * - pt_only  = PT에만 존재하는 영문명 (coach DB에는 같은 name이 없음)
 * - coach_site_only = COACH에만 존재하는 영문명 (PT에는 같은 coach_name이 없음)
 *
 * Returns a structured map usable by retention calculation.
 */

declare(strict_types=1);

/**
 * Load coach mapping once.
 *
 * @return array{
 *   pt_by_name: array<string, array{id:int, coach_name:string}>,
 *   coach_by_name: array<string, array{id:int, name:string}>,
 *   pt_to_coach: array<int, int>,        // PT coach.id  → COACH coach.id
 *   coach_to_pt: array<int, int>,        // COACH coach.id → PT coach.id
 *   pt_only: string[],                   // 영문명 목록 (PT에만 있음)
 *   coach_site_only: string[],           // 영문명 목록 (COACH에만 있음)
 * }
 */
function loadCoachMapping(PDO $db): array
{
    // PT coaches (status='active' 만)
    $ptStmt = $db->query(
        "SELECT id, coach_name FROM coaches WHERE status = 'active'"
    );
    $ptRows = $ptStmt->fetchAll();

    // COACH coaches (is_active=1 만) via cross-DB
    $coachStmt = $db->query(
        "SELECT id, name FROM SORITUNECOM_COACH.coaches WHERE is_active = 1"
    );
    $coachRows = $coachStmt->fetchAll();

    $ptByName    = [];
    $coachByName = [];

    foreach ($ptRows as $r) {
        $ptByName[$r['coach_name']] = [
            'id'         => (int)$r['id'],
            'coach_name' => $r['coach_name'],
        ];
    }
    foreach ($coachRows as $r) {
        $coachByName[$r['name']] = [
            'id'   => (int)$r['id'],
            'name' => $r['name'],
        ];
    }

    $ptToCoach   = [];
    $coachToPt   = [];
    $ptOnly      = [];
    $coachOnly   = [];

    foreach ($ptByName as $name => $pt) {
        if (isset($coachByName[$name])) {
            $ptToCoach[$pt['id']] = $coachByName[$name]['id'];
        } else {
            $ptOnly[] = $name;
        }
    }
    foreach ($coachByName as $name => $c) {
        if (isset($ptByName[$name])) {
            $coachToPt[$c['id']] = $ptByName[$name]['id'];
        } else {
            $coachOnly[] = $name;
        }
    }

    return [
        'pt_by_name'      => $ptByName,
        'coach_by_name'   => $coachByName,
        'pt_to_coach'     => $ptToCoach,
        'coach_to_pt'     => $coachToPt,
        'pt_only'         => $ptOnly,
        'coach_site_only' => $coachOnly,
    ];
}
```

- [ ] **Step 2: PHP 문법 체크**

Run:
```bash
php -l /var/www/html/_______site_SORITUNECOM_PT/public_html/includes/coach_mapping.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: CLI 스모크 테스트**

`/tmp/smoke_coach_mapping.php`를 임시로 만들어 실행:

```bash
cat > /tmp/smoke_coach_mapping.php <<'PHP'
<?php
require_once '/var/www/html/_______site_SORITUNECOM_PT/public_html/includes/db.php';
require_once '/var/www/html/_______site_SORITUNECOM_PT/public_html/includes/coach_mapping.php';
$map = loadCoachMapping(getDB());
echo "PT total: " . count($map['pt_by_name']) . "\n";
echo "COACH total: " . count($map['coach_by_name']) . "\n";
echo "Matched (PT→COACH): " . count($map['pt_to_coach']) . "\n";
echo "pt_only count: " . count($map['pt_only']) . "\n";
echo "coach_site_only count: " . count($map['coach_site_only']) . "\n";
echo "pt_only: " . json_encode($map['pt_only'], JSON_UNESCAPED_UNICODE) . "\n";
echo "coach_site_only: " . json_encode($map['coach_site_only'], JSON_UNESCAPED_UNICODE) . "\n";
PHP
php /tmp/smoke_coach_mapping.php
rm /tmp/smoke_coach_mapping.php
```
Expected: 모든 카운트 출력. PT와 COACH 쪽 코치 수가 출력되고, `Matched + pt_only`가 PT total, `Matched + coach_site_only`가 COACH total과 일치.

- [ ] **Step 4: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/includes/coach_mapping.php
git commit -m "feat(retention): coach mapping helper (PT coach_name ↔ COACH name)"
```

---

### Task 5: [Phase 2] 리텐션 계산 로직 이식 (월 시프트 반영)

**Files:**
- Create: `public_html/includes/retention_calc.php`

`coach.soritune.com`의 `public_html/api/retention_calc.php`를 기반으로 이식. 세 가지 변경: (1) 월 시프트 `[base-1, base-2, base-3]`, (2) cross-DB 참조, (3) PT 저장 대상 + `coach_name_snapshot`.

- [ ] **Step 1: 파일 작성**

Create `public_html/includes/retention_calc.php`:

```php
<?php
/**
 * 리텐션 계산 로직
 *
 * calculateRetention(PDO $db, string $baseMonth, int $totalNew, int $adminId): array
 *
 * - Reads coach_member_mapping / retention_score_criteria / grade_criteria /
 *   coach_assignment_requests from SORITUNECOM_COACH (read-only cross-DB).
 * - Writes snapshot rows to PT coach_retention_scores and coach_retention_runs.
 *
 * 월 시프트: base_month=YYYY-MM 이면 current 후보는 [base-1, base-2, base-3].
 * 각 current에 대해 prev=current-1, prevPrev=current-2.
 * 측정 구간 = prev→current 세 번 (배정이 끝난 월만 반영).
 */

declare(strict_types=1);

require_once __DIR__ . '/coach_mapping.php';

/**
 * @return array{
 *   rows: array<int, array>,
 *   unmapped_coaches: array{pt_only: string[], coach_site_only: string[]},
 *   summary: array{total_new:int, sum_auto:int, sum_final:int, unallocated:int},
 * }
 */
function calculateRetention(PDO $db, string $baseMonth, int $totalNew, int $adminId): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $baseMonth)) {
        throw new InvalidArgumentException('base_month must be YYYY-MM');
    }

    // 1. Build month list (month-shifted by 1: [base-1, base-2, base-3])
    $months = [];
    for ($i = 1; $i <= 3; $i++) {
        $months[] = date('Y-m', strtotime("{$baseMonth}-01 -{$i} months"));
    }

    // 2. Load coach mapping + coach-site criteria
    $map = loadCoachMapping($db);
    $retCriteria   = _retCriteriaLoad($db);
    $gradeCriteria = _gradeCriteriaLoad($db);

    // 3. Compute per-coach retention snapshot (PT coaches only that are mapped)
    $rows = [];
    foreach ($map['pt_by_name'] as $name => $pt) {
        $ptId      = $pt['id'];
        $coachId   = $map['pt_to_coach'][$ptId] ?? null;

        if ($coachId === null) {
            // PT-only coach: create zeroed row
            $rows[] = _emptyRow($ptId, $name);
            continue;
        }

        $rows[] = _computeOneCoach($db, $ptId, $name, $coachId, $months, $retCriteria);
    }

    // 4. Sort by total_score desc, assign rank_num (동점 동등수)
    usort($rows, fn($a, $b) => $b['total_score'] <=> $a['total_score']);
    $rank = 1;
    $total = count($rows);
    for ($i = 0; $i < $total; $i++) {
        if ($i > 0 && $rows[$i]['total_score'] < $rows[$i - 1]['total_score']) {
            $rank = $i + 1;
        }
        $rows[$i]['rank_num'] = $rank;
    }

    // 5. Assign grades by proportion (from grade_criteria.ratio)
    $gradeAssignments = [];
    $assignedSoFar = 0;
    foreach ($gradeCriteria as $gc) {
        $count = (int)round($total * (float)$gc['ratio']);
        if ($gc === end($gradeCriteria)) {
            $count = $total - $assignedSoFar;
        }
        $gradeAssignments[] = [
            'grade'       => $gc['grade'],
            'count'       => $count,
            'hope_ratio'  => $gc['hope_assignment_ratio'],
            'remain_ratio'=> $gc['remaining_assignment_ratio'],
        ];
        $assignedSoFar += $count;
    }

    // 6. Allocate new members (상위권 고정, 하위권 가중치 분배)
    $idx = 0;
    $upperAlloc = 0;
    $lowerIndices = [];

    foreach ($gradeAssignments as $ga) {
        for ($j = 0; $j < $ga['count'] && $idx < $total; $j++, $idx++) {
            $rows[$idx]['grade'] = $ga['grade'];

            $coachId = $map['pt_to_coach'][$rows[$idx]['coach_id']] ?? null;
            $reqCount = $coachId !== null
                ? _getLatestRequest($db, $coachId, $baseMonth)
                : 0;
            $rows[$idx]['requested_count'] = $reqCount;

            if ($ga['hope_ratio'] !== null) {
                // 상위권: round(희망 × hope_ratio)
                $alloc = (int)round($reqCount * (float)$ga['hope_ratio']);
                $rows[$idx]['auto_allocation'] = $alloc;
                $upperAlloc += $alloc;
            } else {
                // 하위권: 가중치만 계산, 실제 배정은 2단계
                $weight = $reqCount * (float)($ga['remain_ratio'] ?? 0);
                $rows[$idx]['_weight'] = $weight;
                $rows[$idx]['auto_allocation'] = 0;
                $lowerIndices[] = $idx;
            }
        }
    }

    $remaining = max(0, $totalNew - $upperAlloc);
    $totalWeight = 0;
    foreach ($lowerIndices as $li) {
        $totalWeight += $rows[$li]['_weight'];
    }

    if ($totalWeight > 0 && $remaining > 0) {
        $allocated = 0;
        $lastIdx = end($lowerIndices);
        foreach ($lowerIndices as $li) {
            if ($li === $lastIdx) {
                $alloc = $remaining - $allocated;
            } else {
                $alloc = (int)round(($rows[$li]['_weight'] / $totalWeight) * $remaining);
            }
            $rows[$li]['auto_allocation'] = $alloc;
            $allocated += $alloc;
        }
    } elseif ($remaining > 0 && count($lowerIndices) > 0) {
        $each = (int)floor($remaining / count($lowerIndices));
        $leftover = $remaining - ($each * count($lowerIndices));
        foreach ($lowerIndices as $i => $li) {
            $rows[$li]['auto_allocation'] = $each + ($i < $leftover ? 1 : 0);
        }
    }

    foreach ($rows as &$r) {
        unset($r['_weight']);
    }
    unset($r);

    // 7. UPSERT into coach_retention_scores + coach_retention_runs
    $db->beginTransaction();
    try {
        $upsert = $db->prepare("
            INSERT INTO coach_retention_scores
              (coach_id, coach_name_snapshot, base_month, grade, rank_num,
               total_score, new_retention_3m, existing_retention_3m,
               assigned_members, requested_count, auto_allocation, final_allocation,
               monthly_detail)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              coach_name_snapshot = VALUES(coach_name_snapshot),
              grade = VALUES(grade),
              rank_num = VALUES(rank_num),
              total_score = VALUES(total_score),
              new_retention_3m = VALUES(new_retention_3m),
              existing_retention_3m = VALUES(existing_retention_3m),
              assigned_members = VALUES(assigned_members),
              requested_count = VALUES(requested_count),
              auto_allocation = VALUES(auto_allocation),
              monthly_detail = VALUES(monthly_detail)
        ");

        foreach ($rows as $r) {
            $upsert->execute([
                $r['coach_id'],
                $r['coach_name_snapshot'],
                $baseMonth,
                $r['grade'] ?? 'D',
                $r['rank_num'],
                $r['total_score'],
                $r['new_retention_3m'],
                $r['existing_retention_3m'],
                $r['assigned_members'],
                $r['requested_count'],
                $r['auto_allocation'],
                $r['auto_allocation'], // final_allocation 초기값
                json_encode($r['monthly_detail'], JSON_UNESCAPED_UNICODE),
            ]);
        }

        // runs upsert
        $db->prepare("
            INSERT INTO coach_retention_runs
              (base_month, total_new, unmapped_coaches, calculated_at, calculated_by)
            VALUES (?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
              total_new = VALUES(total_new),
              unmapped_coaches = VALUES(unmapped_coaches),
              calculated_at = NOW(),
              calculated_by = VALUES(calculated_by)
        ")->execute([
            $baseMonth,
            $totalNew,
            json_encode([
                'pt_only'         => $map['pt_only'],
                'coach_site_only' => $map['coach_site_only'],
            ], JSON_UNESCAPED_UNICODE),
            $adminId,
        ]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // 8. Build final summary using stored values (includes preserved final_allocation for existing months)
    $viewRows = _fetchSnapshotRows($db, $baseMonth);
    $sumAuto  = array_sum(array_column($viewRows, 'auto_allocation'));
    $sumFinal = array_sum(array_column($viewRows, 'final_allocation'));

    return [
        'rows' => $viewRows,
        'unmapped_coaches' => [
            'pt_only'         => $map['pt_only'],
            'coach_site_only' => $map['coach_site_only'],
        ],
        'summary' => [
            'total_new'   => $totalNew,
            'sum_auto'    => (int)$sumAuto,
            'sum_final'   => (int)$sumFinal,
            'unallocated' => (int)$totalNew - (int)$sumFinal,
        ],
    ];
}

/**
 * Fetch a snapshot with display fields.
 * @return array<int, array<string, mixed>>
 */
function _fetchSnapshotRows(PDO $db, string $baseMonth): array
{
    $stmt = $db->prepare("
        SELECT id, coach_id, coach_name_snapshot, base_month, grade, rank_num,
               total_score, new_retention_3m, existing_retention_3m,
               assigned_members, requested_count, auto_allocation, final_allocation,
               adjusted_by, adjusted_at, monthly_detail, updated_at
          FROM coach_retention_scores
         WHERE base_month = ?
         ORDER BY rank_num ASC, total_score DESC
    ");
    $stmt->execute([$baseMonth]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['monthly_detail'] = $r['monthly_detail'] ? json_decode($r['monthly_detail'], true) : [];
        $r['coach_id']        = $r['coach_id'] !== null ? (int)$r['coach_id'] : null;
        $r['id']              = (int)$r['id'];
        $r['rank_num']        = (int)$r['rank_num'];
        $r['assigned_members']= (int)$r['assigned_members'];
        $r['requested_count'] = (int)$r['requested_count'];
        $r['auto_allocation'] = (int)$r['auto_allocation'];
        $r['final_allocation']= (int)$r['final_allocation'];
    }
    unset($r);

    return $rows;
}

/** Empty row for PT-only coaches. */
function _emptyRow(int $ptId, string $name): array
{
    return [
        'coach_id'              => $ptId,
        'coach_name_snapshot'   => $name,
        'total_score'           => 0.0,
        'new_retention_3m'      => 0.0,
        'existing_retention_3m' => 0.0,
        'assigned_members'      => 0,
        'requested_count'       => 0,
        'auto_allocation'       => 0,
        'monthly_detail'        => [],
    ];
}

/**
 * Compute retention + score for a single (mapped) coach.
 */
function _computeOneCoach(
    PDO $db,
    int $ptId,
    string $ptName,
    int $coachSiteId,
    array $months,
    array $retCriteria
): array {
    // 담당 회원 수: 최근 mapping 기준 DISTINCT member_id
    $assignedStmt = $db->prepare(
        "SELECT COUNT(DISTINCT member_id)
         FROM SORITUNECOM_COACH.coach_member_mapping
         WHERE coach_id = ?"
    );
    $assignedStmt->execute([$coachSiteId]);
    $assignedCount = (int)$assignedStmt->fetchColumn();

    $monthlyDetail = [];
    $newSum = 0; $existSum = 0;
    $newCount = 0; $existCount = 0;

    foreach ($months as $currentMonth) {
        $prevMonth     = date('Y-m', strtotime("{$currentMonth}-01 -1 month"));
        $prevPrevMonth = date('Y-m', strtotime("{$prevMonth}-01 -1 month"));

        // 전월 배정 회원 목록
        $prevStmt = $db->prepare("
            SELECT DISTINCT member_id
              FROM SORITUNECOM_COACH.coach_member_mapping
             WHERE coach_id = ? AND period = ?
        ");
        $prevStmt->execute([$coachSiteId, $prevMonth]);
        $prevMembers = $prevStmt->fetchAll();

        $newTotal = 0; $newRepurchase = 0;
        $existTotal = 0; $existRepurchase = 0;

        $existCheck = $db->prepare("
            SELECT COUNT(*)
              FROM SORITUNECOM_COACH.coach_member_mapping
             WHERE coach_id = ? AND member_id = ? AND period = ?
        ");
        $retainCheck = $db->prepare("
            SELECT COUNT(*)
              FROM SORITUNECOM_COACH.coach_member_mapping
             WHERE coach_id = ? AND member_id = ? AND period = ?
        ");

        foreach ($prevMembers as $m) {
            $memberId = $m['member_id'];

            $existCheck->execute([$coachSiteId, $memberId, $prevPrevMonth]);
            $isNew = (int)$existCheck->fetchColumn() === 0;

            $retainCheck->execute([$coachSiteId, $memberId, $currentMonth]);
            $hasRetention = (int)$retainCheck->fetchColumn() > 0;

            if ($isNew) {
                $newTotal++;
                if ($hasRetention) $newRepurchase++;
            } else {
                $existTotal++;
                if ($hasRetention) $existRepurchase++;
            }
        }

        $newRate   = $newTotal   > 0 ? $newRepurchase   / $newTotal   : 0;
        $existRate = $existTotal > 0 ? $existRepurchase / $existTotal : 0;

        $monthlyDetail[] = [
            'month'                => $currentMonth,
            'prev_month'           => $prevMonth,
            'new_total'            => $newTotal,
            'new_repurchase'       => $newRepurchase,
            'new_retention_rate'   => round($newRate, 10),
            'exist_total'          => $existTotal,
            'exist_repurchase'     => $existRepurchase,
            'exist_retention_rate' => round($existRate, 10),
        ];

        if ($newTotal   > 0) { $newSum   += $newRate;   $newCount++; }
        if ($existTotal > 0) { $existSum += $existRate; $existCount++; }
    }

    $avgNew   = $newCount   > 0 ? $newSum   / $newCount   : 0;
    $avgExist = $existCount > 0 ? $existSum / $existCount : 0;

    $newScore   = _retentionToScore($avgNew,   $retCriteria, 'new');
    $existScore = _retentionToScore($avgExist, $retCriteria, 'existing');

    return [
        'coach_id'              => $ptId,
        'coach_name_snapshot'   => $ptName,
        'total_score'           => $newScore + $existScore,
        'new_retention_3m'      => $avgNew,
        'existing_retention_3m' => $avgExist,
        'assigned_members'      => $assignedCount,
        'requested_count'       => 0,  // filled later in grade assignment phase
        'auto_allocation'       => 0,  // filled later
        'monthly_detail'        => $monthlyDetail,
    ];
}

function _retCriteriaLoad(PDO $db): array
{
    return $db->query("
        SELECT retention_rate_min, new_member_score, existing_member_score
          FROM SORITUNECOM_COACH.retention_score_criteria
         ORDER BY retention_rate_min ASC
    ")->fetchAll();
}

function _gradeCriteriaLoad(PDO $db): array
{
    return $db->query("
        SELECT grade, grade_order, ratio, hope_assignment_ratio, remaining_assignment_ratio
          FROM SORITUNECOM_COACH.grade_criteria
         ORDER BY grade_order ASC
    ")->fetchAll();
}

function _retentionToScore(float $rate, array $criteria, string $type = 'new'): float
{
    $col = $type === 'new' ? 'new_member_score' : 'existing_member_score';
    $score = 0.0;
    foreach ($criteria as $row) {
        if ($rate >= (float)$row['retention_rate_min']) {
            $score = (float)$row[$col];
        }
    }
    return $score;
}

function _getLatestRequest(PDO $db, int $coachSiteId, string $period): int
{
    $stmt = $db->prepare("
        SELECT requested_count
          FROM SORITUNECOM_COACH.coach_assignment_requests
         WHERE coach_id = ? AND period = ?
         ORDER BY request_date DESC
         LIMIT 1
    ");
    $stmt->execute([$coachSiteId, $period]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (int)$v : 0;
}
```

- [ ] **Step 2: PHP 문법 체크**

Run:
```bash
php -l /var/www/html/_______site_SORITUNECOM_PT/public_html/includes/retention_calc.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: 월 시프트 정합성 CLI 스모크**

coach DB에 `2026-04` 월 데이터가 있다고 가정. PT에서 `base_month=2026-05`로 계산해 측정 구간이 `[2026-03→2026-04, 2026-02→2026-03, 2026-01→2026-02]`인지 확인.

```bash
cat > /tmp/smoke_retention_shift.php <<'PHP'
<?php
require_once '/var/www/html/_______site_SORITUNECOM_PT/public_html/includes/db.php';
require_once '/var/www/html/_______site_SORITUNECOM_PT/public_html/includes/retention_calc.php';

// Dry-run: base_month=2026-05에 대한 months 배열만 재현
$baseMonth = '2026-05';
$months = [];
for ($i = 1; $i <= 3; $i++) {
    $months[] = date('Y-m', strtotime("$baseMonth-01 -$i months"));
}
foreach ($months as $m) {
    $prev = date('Y-m', strtotime("$m-01 -1 month"));
    echo "current={$m}, prev={$prev} (측정 구간: {$prev} → {$m})\n";
}
PHP
php /tmp/smoke_retention_shift.php
rm /tmp/smoke_retention_shift.php
```
Expected 출력:
```
current=2026-04, prev=2026-03 (측정 구간: 2026-03 → 2026-04)
current=2026-03, prev=2026-02 (측정 구간: 2026-02 → 2026-03)
current=2026-02, prev=2026-01 (측정 구간: 2026-01 → 2026-02)
```

- [ ] **Step 4: 전체 계산 CLI 스모크 (실데이터 기반, 미저장 dry-check)**

아래는 **실제로 PT DB에 쓰기를 수행**한다. 사용자 승인 하에만 실행.

```bash
cat > /tmp/smoke_retention_calc.php <<'PHP'
<?php
require_once '/var/www/html/_______site_SORITUNECOM_PT/public_html/includes/db.php';
require_once '/var/www/html/_______site_SORITUNECOM_PT/public_html/includes/retention_calc.php';

$db = getDB();
$result = calculateRetention($db, '2026-05', 60, 1);

echo "rows: " . count($result['rows']) . "\n";
echo "pt_only: " . count($result['unmapped_coaches']['pt_only']) . "\n";
echo "coach_site_only: " . count($result['unmapped_coaches']['coach_site_only']) . "\n";
echo "summary: " . json_encode($result['summary']) . "\n";
echo "\nTop 3 rows:\n";
foreach (array_slice($result['rows'], 0, 3) as $r) {
    printf("  #%d %s [%s] score=%.1f final=%d\n",
        $r['rank_num'], $r['coach_name_snapshot'], $r['grade'] ?? '-',
        $r['total_score'], $r['final_allocation']);
}
PHP
php /tmp/smoke_retention_calc.php
rm /tmp/smoke_retention_calc.php
```
Expected: `rows`, `summary` 정상 출력. 상위 3명 점수·등급 표시. 오류 없음.

검증 후 스냅샷 정리(테스트 잔해 제거):
```bash
source /var/www/html/_______site_SORITUNECOM_PT/.db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  DELETE FROM coach_retention_scores WHERE base_month = '2026-05';
  DELETE FROM coach_retention_runs WHERE base_month = '2026-05';
"
```

- [ ] **Step 5: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/includes/retention_calc.php
git commit -m "$(cat <<'EOF'
feat(retention): calculation logic with month-shift + cross-DB reads

Port of coach-site retention_calc.php with three changes: base_month
measures [base-1, base-2, base-3] only (배정 완료 월만), reads
coach_member_mapping/criteria/assignment_requests from SORITUNECOM_COACH,
writes snapshots to PT coach_retention_scores with coach_name_snapshot
for deleted-coach display fallback.
EOF
)"
```

---

### Task 6: [Phase 2] API — snapshots / view 엔드포인트

**Files:**
- Create: `public_html/api/retention.php`

이번 태스크는 **읽기 전용** 액션 두 개만 구현한다. 나머지는 Task 7–9에서 추가.

- [ ] **Step 1: API 파일 작성 (snapshots, view만)**

Create `public_html/api/retention.php`:

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coach_mapping.php';
require_once __DIR__ . '/../includes/retention_calc.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$db    = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'snapshots':
        // base_month 리스트 + total_coaches(집계) + total_new + calculated_at
        $stmt = $db->query("
            SELECT r.base_month,
                   r.total_new,
                   r.calculated_at AS last_calculated_at,
                   COALESCE(s.total_coaches, 0) AS total_coaches
              FROM coach_retention_runs r
              LEFT JOIN (
                SELECT base_month, COUNT(*) AS total_coaches
                  FROM coach_retention_scores
                 GROUP BY base_month
              ) s ON s.base_month = r.base_month
             ORDER BY r.base_month DESC
        ");
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['total_new']     = (int)$r['total_new'];
            $r['total_coaches'] = (int)$r['total_coaches'];
        }
        unset($r);
        jsonSuccess(['snapshots' => $rows]);

    case 'view':
        $baseMonth = $_GET['base_month'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $baseMonth)) {
            jsonError('base_month 형식이 잘못되었습니다 (YYYY-MM)');
        }

        $runStmt = $db->prepare("
            SELECT base_month, total_new, unmapped_coaches, calculated_at
              FROM coach_retention_runs
             WHERE base_month = ?
        ");
        $runStmt->execute([$baseMonth]);
        $run = $runStmt->fetch();
        if (!$run) {
            jsonError('해당 기준월 스냅샷이 없습니다', 404);
        }

        $rows = _fetchSnapshotRows($db, $baseMonth);
        $unmapped = $run['unmapped_coaches']
            ? json_decode($run['unmapped_coaches'], true)
            : ['pt_only' => [], 'coach_site_only' => []];

        $sumAuto  = array_sum(array_column($rows, 'auto_allocation'));
        $sumFinal = array_sum(array_column($rows, 'final_allocation'));

        jsonSuccess([
            'base_month'       => $baseMonth,
            'total_new'        => (int)$run['total_new'],
            'rows'             => $rows,
            'unmapped_coaches' => $unmapped,
            'summary'          => [
                'total_new'   => (int)$run['total_new'],
                'sum_auto'    => (int)$sumAuto,
                'sum_final'   => (int)$sumFinal,
                'unallocated' => (int)$run['total_new'] - (int)$sumFinal,
            ],
        ]);

    // TODO in subsequent tasks:
    //   calculate, update_allocation, reset_allocation, delete_snapshot

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 2: 문법 체크**

Run:
```bash
php -l /var/www/html/_______site_SORITUNECOM_PT/public_html/api/retention.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: snapshots API curl 테스트 (빈 상태)**

사전 조건: Task 5 Step 4에서 남겼던 테스트 스냅샷이 정리되어 비어 있어야 함.

admin 세션 쿠키는 브라우저 로그인 후 DevTools에서 복사한 `PHPSESSID` 사용. 아래는 curl 예시 (환경에 맞게 교체):
```bash
curl -s -b "PHPSESSID=<your-session-id>" \
  "https://pt.soritune.com/api/retention.php?action=snapshots" | python3 -m json.tool
```
Expected: `{"ok":true, "data":{"snapshots":[]}}` 또는 기존 스냅샷 리스트.

- [ ] **Step 4: view API curl 테스트 (스냅샷 없음 케이스)**

```bash
curl -s -b "PHPSESSID=<your-session-id>" \
  "https://pt.soritune.com/api/retention.php?action=view&base_month=2099-12" | python3 -m json.tool
```
Expected: `{"ok":false, "message":"해당 기준월 스냅샷이 없습니다"}` (HTTP 404).

- [ ] **Step 5: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/retention.php
git commit -m "feat(api): retention.php snapshots/view read-only actions"
```

---

### Task 7: [Phase 2] API — calculate 액션

**Files:**
- Modify: `public_html/api/retention.php`

- [ ] **Step 1: calculate 케이스 추가**

Edit `public_html/api/retention.php`. `switch ($action)` 블록 안 `view:` 케이스 뒤, `// TODO` 주석 앞에 다음 case를 추가:

```php
    case 'calculate':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('POST only', 405);
        }
        $input = getJsonInput();
        $baseMonth = $input['base_month'] ?? '';
        $totalNew  = (int)($input['total_new'] ?? 0);

        if (!preg_match('/^\d{4}-\d{2}$/', $baseMonth)) {
            jsonError('base_month 형식이 잘못되었습니다 (YYYY-MM)');
        }
        if ($totalNew < 0 || $totalNew > 10000) {
            jsonError('전체 신규 인원은 0 ~ 10000 사이여야 합니다');
        }

        try {
            $result = calculateRetention($db, $baseMonth, $totalNew, (int)$admin['id']);
        } catch (PDOException $e) {
            jsonError('coach 사이트 DB 접근 실패: ' . $e->getMessage(), 500);
        } catch (Throwable $e) {
            jsonError('계산 오류: ' . $e->getMessage(), 500);
        }

        jsonSuccess(array_merge(['base_month' => $baseMonth], $result));
```

또한 상단 `// TODO` 주석을 업데이트 (남은 항목만):
```php
    // TODO in subsequent tasks:
    //   update_allocation, reset_allocation, delete_snapshot
```

- [ ] **Step 2: 문법 체크**

Run:
```bash
php -l /var/www/html/_______site_SORITUNECOM_PT/public_html/api/retention.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: calculate curl 테스트 (실제 계산 + 저장)**

```bash
curl -s -b "PHPSESSID=<your-session-id>" \
  -H "Content-Type: application/json" \
  -X POST \
  -d '{"base_month":"2026-05","total_new":60}' \
  "https://pt.soritune.com/api/retention.php?action=calculate" | python3 -m json.tool
```
Expected: `ok:true`, `data.rows` 배열에 코치 N명(예: 28~30명), `data.summary.total_new=60`, `data.unmapped_coaches`에 `pt_only`·`coach_site_only` 배열. 각 row에 `updated_at` 포함.

- [ ] **Step 4: snapshots에 새 항목 반영 확인**

```bash
curl -s -b "PHPSESSID=<your-session-id>" \
  "https://pt.soritune.com/api/retention.php?action=snapshots" | python3 -m json.tool
```
Expected: 방금 만든 `base_month=2026-05` 항목이 목록 최상단에 `total_new=60`, `total_coaches=<N>`로 보임.

- [ ] **Step 5: 재계산 → final_allocation 보존 검증**

DB에서 임의 한 행의 `final_allocation`을 auto와 다른 값으로 바꾼다:
```bash
source /var/www/html/_______site_SORITUNECOM_PT/.db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  UPDATE coach_retention_scores
     SET final_allocation = final_allocation + 99,
         adjusted_by = 1, adjusted_at = NOW()
   WHERE base_month = '2026-05'
   ORDER BY rank_num ASC LIMIT 1;
  SELECT id, rank_num, auto_allocation, final_allocation
    FROM coach_retention_scores
   WHERE base_month = '2026-05'
   ORDER BY rank_num ASC LIMIT 3;
"
```
최상단 코치의 `final_allocation = auto_allocation + 99`임을 확인.

이어서 같은 base_month로 calculate 재실행:
```bash
curl -s -b "PHPSESSID=<your-session-id>" \
  -H "Content-Type: application/json" \
  -X POST -d '{"base_month":"2026-05","total_new":60}' \
  "https://pt.soritune.com/api/retention.php?action=calculate" > /dev/null
```

다시 확인:
```bash
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  SELECT id, rank_num, auto_allocation, final_allocation
    FROM coach_retention_scores
   WHERE base_month = '2026-05'
   ORDER BY rank_num ASC LIMIT 3;
"
```
Expected: 최상단 행의 `final_allocation`이 여전히 `auto_allocation + 99` (보존됨). `auto_allocation`은 덮어쓰기.

- [ ] **Step 6: 테스트 잔해 정리**

```bash
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  DELETE FROM coach_retention_scores WHERE base_month = '2026-05';
  DELETE FROM coach_retention_runs WHERE base_month = '2026-05';
"
```

- [ ] **Step 7: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/retention.php
git commit -m "feat(api): retention calculate action (UPSERT + final_allocation 보존)"
```

---

### Task 8: [Phase 2] API — update_allocation (낙관적 락)

**Files:**
- Modify: `public_html/api/retention.php`

- [ ] **Step 1: update_allocation 케이스 추가**

Edit `public_html/api/retention.php`. `calculate:` 케이스 뒤에 추가:

```php
    case 'update_allocation':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('POST only', 405);
        }
        $input = getJsonInput();
        $id                  = (int)($input['id'] ?? 0);
        $finalAllocation     = (int)($input['final_allocation'] ?? -1);
        $expectedUpdatedAt   = $input['expected_updated_at'] ?? '';

        if ($id <= 0) jsonError('id가 필요합니다');
        if ($finalAllocation < 0 || $finalAllocation > 9999) {
            jsonError('final_allocation은 0 ~ 9999 사이여야 합니다');
        }
        if ($expectedUpdatedAt === '') {
            jsonError('expected_updated_at이 필요합니다');
        }

        // Load current row for old_value + compare
        $current = $db->prepare("
            SELECT id, base_month, auto_allocation, final_allocation, updated_at
              FROM coach_retention_scores
             WHERE id = ?
        ");
        $current->execute([$id]);
        $row = $current->fetch();
        if (!$row) jsonError('행을 찾을 수 없습니다', 404);

        // Optimistic lock check (§9.5)
        if ($row['updated_at'] !== $expectedUpdatedAt) {
            // Conflict: return current server state
            $latest = _fetchRowById($db, $id);
            jsonSuccess([
                'ok'   => false,
                'code' => 'conflict',
                'row'  => $latest,
            ]);
        }

        $db->beginTransaction();
        try {
            $upd = $db->prepare("
                UPDATE coach_retention_scores
                   SET final_allocation = ?,
                       adjusted_by = ?,
                       adjusted_at = NOW()
                 WHERE id = ? AND updated_at = ?
            ");
            $upd->execute([
                $finalAllocation, (int)$admin['id'], $id, $expectedUpdatedAt
            ]);
            $affected = $upd->rowCount();

            if ($affected === 0) {
                $db->rollBack();
                $latest = _fetchRowById($db, $id);
                jsonSuccess([
                    'ok'   => false,
                    'code' => 'conflict',
                    'row'  => $latest,
                ]);
            }

            logChange(
                $db, 'retention_allocation', $id, 'final_allocation_update',
                ['final_allocation' => (int)$row['final_allocation']],
                ['final_allocation' => $finalAllocation],
                'admin', (int)$admin['id']
            );

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            jsonError('저장 실패: ' . $e->getMessage(), 500);
        }

        $latest = _fetchRowById($db, $id);

        // summary recomputed on server for authoritative value
        $sumStmt = $db->prepare("
            SELECT SUM(auto_allocation) AS sa, SUM(final_allocation) AS sf
              FROM coach_retention_scores WHERE base_month = ?
        ");
        $sumStmt->execute([$row['base_month']]);
        $sums = $sumStmt->fetch();

        $runStmt = $db->prepare("
            SELECT total_new FROM coach_retention_runs WHERE base_month = ?
        ");
        $runStmt->execute([$row['base_month']]);
        $totalNew = (int)($runStmt->fetchColumn() ?: 0);

        jsonSuccess([
            'ok'  => true,
            'row' => $latest,
            'summary' => [
                'total_new'   => $totalNew,
                'sum_auto'    => (int)$sums['sa'],
                'sum_final'   => (int)$sums['sf'],
                'unallocated' => $totalNew - (int)$sums['sf'],
            ],
        ]);
```

그리고 `_fetchRowById` 헬퍼를 `retention.php` 파일 맨 아래 (switch 블록 밖)에 추가:
```php
function _fetchRowById(PDO $db, int $id): ?array
{
    $s = $db->prepare("
        SELECT id, coach_id, coach_name_snapshot, base_month, grade, rank_num,
               total_score, new_retention_3m, existing_retention_3m,
               assigned_members, requested_count, auto_allocation, final_allocation,
               adjusted_by, adjusted_at, monthly_detail, updated_at
          FROM coach_retention_scores
         WHERE id = ?
    ");
    $s->execute([$id]);
    $r = $s->fetch();
    if (!$r) return null;
    $r['monthly_detail'] = $r['monthly_detail'] ? json_decode($r['monthly_detail'], true) : [];
    foreach (['id','rank_num','assigned_members','requested_count','auto_allocation','final_allocation'] as $k) {
        $r[$k] = (int)$r[$k];
    }
    if ($r['coach_id'] !== null) $r['coach_id'] = (int)$r['coach_id'];
    return $r;
}
```

상단 `// TODO`도 갱신:
```php
    // TODO in subsequent tasks:
    //   reset_allocation, delete_snapshot
```

- [ ] **Step 2: 문법 체크**

Run:
```bash
php -l /var/www/html/_______site_SORITUNECOM_PT/public_html/api/retention.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: 정상 저장 curl 테스트**

먼저 calculate로 스냅샷 하나 만들고, row 하나 골라서 업데이트.

```bash
SID="<your-session-id>"
curl -s -b "PHPSESSID=$SID" -H "Content-Type: application/json" \
  -X POST -d '{"base_month":"2026-05","total_new":60}' \
  "https://pt.soritune.com/api/retention.php?action=calculate" > /tmp/calc.json

# 첫 번째 row의 id, updated_at, auto_allocation
ID=$(python3 -c "import json;d=json.load(open('/tmp/calc.json'));print(d['data']['rows'][0]['id'])")
UPD=$(python3 -c "import json;d=json.load(open('/tmp/calc.json'));print(d['data']['rows'][0]['updated_at'])")
AUTO=$(python3 -c "import json;d=json.load(open('/tmp/calc.json'));print(d['data']['rows'][0]['auto_allocation'])")

echo "ID=$ID, updated_at=$UPD, auto=$AUTO"

# 정상 업데이트 (auto + 5)
curl -s -b "PHPSESSID=$SID" -H "Content-Type: application/json" \
  -X POST \
  -d "{\"id\":$ID,\"final_allocation\":$((AUTO + 5)),\"expected_updated_at\":\"$UPD\"}" \
  "https://pt.soritune.com/api/retention.php?action=update_allocation" | python3 -m json.tool
```
Expected: `{"ok":true,"message":"","data":{"ok":true,"row":{...,"final_allocation":<AUTO+5>,"updated_at":"<새 타임스탬프>"},"summary":{...}}}`

- [ ] **Step 4: 충돌 케이스 curl 테스트 (stale expected_updated_at)**

같은 ID에 **예전 updated_at**을 보내본다:
```bash
curl -s -b "PHPSESSID=$SID" -H "Content-Type: application/json" \
  -X POST \
  -d "{\"id\":$ID,\"final_allocation\":999,\"expected_updated_at\":\"$UPD\"}" \
  "https://pt.soritune.com/api/retention.php?action=update_allocation" | python3 -m json.tool
```
Expected: `{"ok":true,"message":"","data":{"ok":false,"code":"conflict","row":{...최신값...}}}`. **실제 DB 값은 변경되지 않아야 함** (rowCount=0 + rollBack).

- [ ] **Step 5: change_logs 기록 확인**

```bash
source /var/www/html/_______site_SORITUNECOM_PT/.db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  SELECT id, target_type, target_id, action, old_value, new_value, actor_type, actor_id
    FROM change_logs
   WHERE target_type = 'retention_allocation'
   ORDER BY id DESC LIMIT 3;
"
```
Expected: 방금 성공한 update에 대해 `retention_allocation` 로그 1건이 남아 있음.

- [ ] **Step 6: 테스트 잔해 정리**

```bash
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  DELETE FROM change_logs WHERE target_type = 'retention_allocation' AND created_at >= CURDATE();
  DELETE FROM coach_retention_scores WHERE base_month = '2026-05';
  DELETE FROM coach_retention_runs WHERE base_month = '2026-05';
"
```

- [ ] **Step 7: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/retention.php
git commit -m "$(cat <<'EOF'
feat(api): retention update_allocation with optimistic lock

Uses coach_retention_scores.updated_at (DATETIME(3)) as revision token.
Returns {ok:false, code:'conflict', row} when client's
expected_updated_at does not match server value — client can then
refresh the row. Logs successful changes to change_logs.
EOF
)"
```

---

### Task 9: [Phase 2] API — reset_allocation, delete_snapshot

**Files:**
- Modify: `public_html/api/retention.php`

- [ ] **Step 1: reset_allocation + delete_snapshot 케이스 추가**

`update_allocation:` 뒤에 추가:

```php
    case 'reset_allocation':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('POST only', 405);
        }
        $input = getJsonInput();
        $baseMonth = $input['base_month'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $baseMonth)) {
            jsonError('base_month 형식이 잘못되었습니다 (YYYY-MM)');
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                UPDATE coach_retention_scores
                   SET final_allocation = auto_allocation,
                       adjusted_by = ?,
                       adjusted_at = NOW()
                 WHERE base_month = ?
                   AND final_allocation <> auto_allocation
            ");
            $stmt->execute([(int)$admin['id'], $baseMonth]);
            $affected = $stmt->rowCount();

            logChange(
                $db, 'retention_allocation', 0, 'reset_all',
                null, ['base_month' => $baseMonth, 'reset_rows' => $affected],
                'admin', (int)$admin['id']
            );
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            jsonError('리셋 실패: ' . $e->getMessage(), 500);
        }

        jsonSuccess(['ok' => true, 'updated_rows' => $affected]);

    case 'delete_snapshot':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('POST only', 405);
        }
        $input = getJsonInput();
        $baseMonth = $input['base_month'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $baseMonth)) {
            jsonError('base_month 형식이 잘못되었습니다 (YYYY-MM)');
        }

        $db->beginTransaction();
        try {
            $d1 = $db->prepare("DELETE FROM coach_retention_scores WHERE base_month = ?");
            $d1->execute([$baseMonth]);
            $deletedScores = $d1->rowCount();

            $d2 = $db->prepare("DELETE FROM coach_retention_runs WHERE base_month = ?");
            $d2->execute([$baseMonth]);
            $deletedRuns = $d2->rowCount();

            logChange(
                $db, 'retention_allocation', 0, 'snapshot_deleted',
                null, ['base_month' => $baseMonth,
                       'deleted_scores' => $deletedScores,
                       'deleted_runs' => $deletedRuns],
                'admin', (int)$admin['id']
            );

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            jsonError('삭제 실패: ' . $e->getMessage(), 500);
        }

        jsonSuccess([
            'ok' => true,
            'deleted_scores' => $deletedScores,
            'deleted_runs'   => $deletedRuns,
        ]);
```

상단 `// TODO` 주석 줄 제거 (모든 액션 구현됨).

- [ ] **Step 2: 문법 체크**

Run:
```bash
php -l /var/www/html/_______site_SORITUNECOM_PT/public_html/api/retention.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: reset_allocation 검증**

먼저 calculate + 개별 update_allocation 한 건으로 final을 움직인 상태 만들기 (Task 8 Step 3 참고). 그 뒤:

```bash
curl -s -b "PHPSESSID=$SID" -H "Content-Type: application/json" \
  -X POST -d '{"base_month":"2026-05"}' \
  "https://pt.soritune.com/api/retention.php?action=reset_allocation" | python3 -m json.tool
```
Expected: `{"ok":true,"data":{"ok":true,"updated_rows":<>=1}}`

DB 확인:
```bash
source /var/www/html/_______site_SORITUNECOM_PT/.db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  SELECT COUNT(*) AS mismatched
    FROM coach_retention_scores
   WHERE base_month = '2026-05' AND auto_allocation <> final_allocation;
"
```
Expected: `mismatched=0` (모두 auto로 리셋됨).

- [ ] **Step 4: delete_snapshot 검증**

```bash
curl -s -b "PHPSESSID=$SID" -H "Content-Type: application/json" \
  -X POST -d '{"base_month":"2026-05"}' \
  "https://pt.soritune.com/api/retention.php?action=delete_snapshot" | python3 -m json.tool
```
Expected: `{"ok":true,"data":{"ok":true,"deleted_scores":<>=1,"deleted_runs":1}}`

DB 확인:
```bash
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  SELECT COUNT(*) AS scores FROM coach_retention_scores WHERE base_month='2026-05';
  SELECT COUNT(*) AS runs   FROM coach_retention_runs   WHERE base_month='2026-05';
"
```
Expected: 둘 다 0.

- [ ] **Step 5: 로그 잔해 정리**

```bash
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  DELETE FROM change_logs WHERE target_type = 'retention_allocation' AND created_at >= CURDATE();
"
```

- [ ] **Step 6: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/retention.php
git commit -m "feat(api): retention reset_allocation + delete_snapshot (transactional)"
```

---

### Task 10: [Phase 2] Admin 사이드바 + retention.js 골격

**Files:**
- Modify: `public_html/admin/index.php`
- Create: `public_html/admin/js/pages/retention.js`

UI를 단계적으로 쌓는다. 이 태스크는 **사이드바 링크 + 페이지 골격 + 계산 실행 폼**까지.

- [ ] **Step 1: 사이드바 링크 + 스크립트 로드**

Edit `public_html/admin/index.php`:

**수정 1** — `<nav class="sidebar-nav">` 블록 안에 리텐션 링크 추가 (기존 마지막 `<a>` 뒤):

이전:
```php
      <a href="#import" data-page="import">데이터관리</a>
    </nav>
```
이후:
```php
      <a href="#import" data-page="import">데이터관리</a>
      <a href="#retention" data-page="retention">리텐션관리</a>
    </nav>
```

**수정 2** — 스크립트 로드 목록에 retention.js 추가:

이전:
```php
<script src="/admin/js/pages/import.js"></script>
<script>App.init();</script>
```
이후:
```php
<script src="/admin/js/pages/import.js"></script>
<script src="/admin/js/pages/retention.js"></script>
<script>App.init();</script>
```

- [ ] **Step 2: retention.js 골격 작성**

Create `public_html/admin/js/pages/retention.js`:

```javascript
/**
 * Retention Management Page
 *
 * Structure: 계산 실행 카드 → 스냅샷 탭 → 요약 패널 (sticky) → 결과 테이블 → 매핑 안 된 코치 배너
 */
App.registerPage('retention', {
  state: {
    baseMonth: null,           // 현재 로드된 기준월
    totalNew: 0,
    rows: [],                  // coach_retention_scores 행 배열
    unmapped: { pt_only: [], coach_site_only: [] },
    summary: { total_new: 0, sum_auto: 0, sum_final: 0, unallocated: 0 },
    pendingSaves: new Map(),   // id → timeout handle (debounce)
  },

  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header">
        <h1 class="page-title">리텐션관리</h1>
      </div>
      <div id="retentionApp">
        ${this.renderCalcCard()}
        <div id="retentionBody"><div class="loading">로딩 중...</div></div>
      </div>
    `;
    this.bindCalcForm();
    await this.loadSnapshots();
  },

  renderCalcCard() {
    const defaultMonth = new Date().toISOString().slice(0, 7);
    return `
      <div class="card" style="padding:16px;margin-bottom:16px">
        <form id="retentionCalcForm" class="ret-calc-form">
          <div class="form-group">
            <label class="form-label">기준월</label>
            <input type="month" id="ret_baseMonth" class="form-input" value="${defaultMonth}" required>
          </div>
          <div class="form-group">
            <label class="form-label">전체 신규 인원</label>
            <input type="number" id="ret_totalNew" class="form-input" value="0" min="0" max="10000" required>
          </div>
          <div class="form-group" style="align-self:flex-end">
            <button type="submit" class="btn btn-primary">계산 실행</button>
          </div>
        </form>
        <div id="ret_shiftHint" class="ret-shift-hint"></div>
      </div>
    `;
  },

  bindCalcForm() {
    const updateHint = () => {
      const v = document.getElementById('ret_baseMonth').value;
      document.getElementById('ret_shiftHint').innerHTML = this.shiftHintText(v);
    };
    document.getElementById('ret_baseMonth').addEventListener('change', updateHint);
    updateHint();

    document.getElementById('retentionCalcForm').addEventListener('submit', async e => {
      e.preventDefault();
      const baseMonth = document.getElementById('ret_baseMonth').value;
      const totalNew  = parseInt(document.getElementById('ret_totalNew').value, 10) || 0;
      if (!/^\d{4}-\d{2}$/.test(baseMonth)) { alert('기준월을 올바르게 선택하세요'); return; }

      const body = document.getElementById('retentionBody');
      body.innerHTML = '<div class="loading">계산 중...</div>';

      const res = await API.post('/api/retention.php?action=calculate', { base_month: baseMonth, total_new: totalNew });
      if (!res.ok) { alert(res.message || '계산 실패'); return; }

      this.loadFromResponse(res.data);
      await this.loadSnapshots();
      this.renderBody();
    });
  },

  shiftHintText(baseMonthStr) {
    // baseMonthStr: "YYYY-MM"
    if (!/^\d{4}-\d{2}$/.test(baseMonthStr)) return '';
    const [y, m] = baseMonthStr.split('-').map(Number);
    const shift = (months) => {
      const d = new Date(y, m - 1 - months, 1);
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    };
    const m1 = shift(1), m2 = shift(2), m3 = shift(3);
    const m1p = shift(2), m2p = shift(3), m3p = shift(4);
    return `측정 구간: <strong>${m3p}→${m3}</strong>, <strong>${m2p}→${m2}</strong>, <strong>${m1p}→${m1}</strong> (배정월 <strong>${m1}</strong>까지 반영)`;
  },

  loadFromResponse(data) {
    this.state.baseMonth = data.base_month;
    this.state.totalNew  = data.total_new ?? data.summary?.total_new ?? 0;
    this.state.rows      = data.rows || [];
    this.state.unmapped  = data.unmapped_coaches || { pt_only: [], coach_site_only: [] };
    this.state.summary   = data.summary || { total_new: 0, sum_auto: 0, sum_final: 0, unallocated: 0 };
  },

  async loadSnapshots() {
    const res = await API.get('/api/retention.php?action=snapshots');
    if (!res.ok) return;
    this.snapshots = res.data.snapshots || [];
    // Table 실제 렌더링은 다음 태스크에서
  },

  renderBody() {
    // 이 태스크에서는 간단히 "계산 완료" 표시만
    document.getElementById('retentionBody').innerHTML =
      `<div class="card" style="padding:16px">
        <strong>${this.state.baseMonth}</strong> 계산 완료 — ${this.state.rows.length}명, 총 ${this.state.summary.total_new}명 신규
      </div>`;
  },
});
```

- [ ] **Step 3: 페이지 라우팅 스모크**

브라우저에서 `https://pt.soritune.com/admin/#retention` 접속 → 로그인 후 좌측 사이드바 "리텐션관리" 클릭.

Expected:
- 사이드바의 "리텐션관리"가 active 표시
- 우측에 페이지 타이틀 "리텐션관리"
- 기준월 picker + 전체 신규 인원 input + "계산 실행" 버튼
- 기준월 아래에 "측정 구간: YYYY-MM→YYYY-MM, … (배정월 YYYY-MM까지 반영)" 힌트 텍스트

기준월을 바꿀 때마다 힌트 텍스트도 갱신되어야 함.

- [ ] **Step 4: 계산 실행 스모크**

기준월=`2026-05`, 전체 신규 인원=`60` 입력 후 "계산 실행" 클릭.
Expected: 하단에 "2026-05 계산 완료 — N명, 총 60명 신규" 메시지 표시. 에러 없음.

- [ ] **Step 5: 테스트 잔해 정리 (Task 11 들어가기 전)**

```bash
source /var/www/html/_______site_SORITUNECOM_PT/.db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  DELETE FROM coach_retention_scores WHERE base_month = '2026-05';
  DELETE FROM coach_retention_runs   WHERE base_month = '2026-05';
  DELETE FROM change_logs WHERE target_type = 'retention_allocation' AND created_at >= CURDATE();
"
```

- [ ] **Step 6: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/admin/index.php public_html/admin/js/pages/retention.js
git commit -m "feat(admin): retention management sidebar + scaffolding (calc form)"
```

---

### Task 11: [Phase 2] 스냅샷 탭 + 요약 패널 + 결과 테이블 렌더링

**Files:**
- Modify: `public_html/admin/js/pages/retention.js`

페이지 진입 시 최신 스냅샷 자동 로드, 스냅샷 탭 버튼, 요약 패널, 결과 테이블, 상세 토글까지 렌더링. **이 단계까지는 최종 셀이 편집 불가 (readonly)**. 편집은 Task 12에서.

- [ ] **Step 1: renderBody / renderSnapshotTabs / renderSummary / renderTable 구현**

Edit `public_html/admin/js/pages/retention.js`. 기존 `renderBody`를 교체하고 관련 메서드를 추가:

```javascript
  renderBody() {
    const html = `
      ${this.renderSnapshotTabs()}
      ${this.renderUnmappedBanner()}
      ${this.renderSummary()}
      ${this.renderTable()}
    `;
    document.getElementById('retentionBody').innerHTML = html;
    this.bindSnapshotTabs();
    this.bindRowDetails();
  },

  renderSnapshotTabs() {
    const snaps = this.snapshots || [];
    if (snaps.length === 0) return '';
    const buttons = snaps.map(s => {
      const active = s.base_month === this.state.baseMonth ? 'btn-primary' : 'btn-outline';
      return `<button class="btn btn-small ${active} ret-snap-tab" data-month="${s.base_month}">${s.base_month}</button>`;
    }).join(' ');
    return `
      <div class="card" style="padding:12px;margin-bottom:12px">
        <div class="ret-snap-row">
          <div class="ret-snap-label">저장된 스냅샷:</div>
          <div class="ret-snap-tabs">${buttons}</div>
        </div>
      </div>
    `;
  },

  renderUnmappedBanner() {
    const u = this.state.unmapped || {};
    const ptOnly = (u.pt_only || []).length;
    const coachOnly = (u.coach_site_only || []);
    if (coachOnly.length === 0 && ptOnly === 0) return '';
    const names = coachOnly.join(', ');
    return `
      <div class="ret-unmapped-banner">
        ⚠️ coach 사이트와 이름(영문)이 일치하지 않아 리텐션에서 제외된 코치:
        <strong>${UI.esc(names) || '-'}</strong>
        ${ptOnly > 0 ? ` / PT에만 존재하는 코치 ${ptOnly}명은 리텐션 0%로 표시됩니다.` : ''}
      </div>
    `;
  },

  renderSummary() {
    const s = this.state.summary;
    const unalloc = s.unallocated;
    const colorClass = unalloc > 0 ? 'ret-unalloc-pos' : (unalloc < 0 ? 'ret-unalloc-neg' : 'ret-unalloc-zero');
    return `
      <div class="ret-summary-sticky">
        <div class="ret-summary-card">
          <div class="label">전체 신규</div>
          <div class="value">${s.total_new}명</div>
        </div>
        <div class="ret-summary-card">
          <div class="label">자동 배정 합</div>
          <div class="value">${s.sum_auto}명</div>
        </div>
        <div class="ret-summary-card">
          <div class="label">현재 합</div>
          <div class="value">${s.sum_final}명</div>
        </div>
        <div class="ret-summary-card ${colorClass}">
          <div class="label">잔여</div>
          <div class="value">${unalloc}명</div>
        </div>
        <div class="ret-summary-actions">
          <button class="btn btn-small btn-outline" id="ret_resetBtn">자동값으로 리셋</button>
          <button class="btn btn-small btn-danger" id="ret_deleteBtn">스냅샷 삭제</button>
        </div>
      </div>
    `;
  },

  renderTable() {
    if (this.state.rows.length === 0) {
      return `<div class="empty-state">아직 계산된 결과가 없습니다. 기준월과 전체 신규 인원을 입력하고 계산하세요.</div>`;
    }
    const rows = this.state.rows.map(r => this.renderTableRow(r)).join('');
    return `
      <div class="data-table-wrapper">
        <table class="data-table ret-table">
          <thead>
            <tr>
              <th>등수</th>
              <th>코치</th>
              <th>등급</th>
              <th class="text-right">총점</th>
              <th class="text-right">3M 신규</th>
              <th class="text-right">3M 기존</th>
              <th class="text-right">담당</th>
              <th class="text-right">희망</th>
              <th class="text-right">자동</th>
              <th class="text-right">최종</th>
              <th></th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    `;
  },

  renderTableRow(r) {
    const gradeBadge = this.gradeBadge(r.grade);
    const coachName = r.coach_id
      ? UI.esc(r.coach_name_snapshot)
      : `<span class="ret-deleted">${UI.esc(r.coach_name_snapshot)} (삭제됨)</span>`;
    return `
      <tr data-row-id="${r.id}">
        <td class="text-center">${r.rank_num ?? '-'}</td>
        <td><strong>${coachName}</strong></td>
        <td>${gradeBadge}</td>
        <td class="text-right">${(+r.total_score).toFixed(1)}</td>
        <td class="text-right">${(r.new_retention_3m * 100).toFixed(1)}%</td>
        <td class="text-right">${(r.existing_retention_3m * 100).toFixed(1)}%</td>
        <td class="text-right">${r.assigned_members}</td>
        <td class="text-right">${r.requested_count}</td>
        <td class="text-right">${r.auto_allocation}</td>
        <td class="text-right">
          <input type="number" class="ret-final-input" value="${r.final_allocation}" min="0" max="9999" readonly data-id="${r.id}">
          <span class="ret-save-status" data-id="${r.id}"></span>
        </td>
        <td>
          <button class="btn btn-small btn-outline ret-detail-toggle" data-id="${r.id}">▶</button>
        </td>
      </tr>
      <tr class="ret-detail-row" data-for="${r.id}" style="display:none">
        <td colspan="11">${this.renderDetail(r)}</td>
      </tr>
    `;
  },

  renderDetail(r) {
    const detail = r.monthly_detail || [];
    if (detail.length === 0) return '<div style="padding:8px;color:var(--text-secondary)">상세 데이터 없음</div>';
    const rows = detail.map(d => `
      <tr>
        <td>${d.month}</td>
        <td>${d.prev_month}</td>
        <td class="text-right">${d.new_total}</td>
        <td class="text-right">${d.new_repurchase}</td>
        <td class="text-right">${(d.new_retention_rate * 100).toFixed(1)}%</td>
        <td class="text-right">${d.exist_total}</td>
        <td class="text-right">${d.exist_repurchase}</td>
        <td class="text-right">${(d.exist_retention_rate * 100).toFixed(1)}%</td>
      </tr>`).join('');
    return `
      <table class="ret-detail-table">
        <thead>
          <tr>
            <th>측정월</th><th>모수 월</th>
            <th>신규 총</th><th>신규 유지</th><th>신규 리텐션</th>
            <th>기존 총</th><th>기존 유지</th><th>기존 리텐션</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    `;
  },

  gradeBadge(grade) {
    if (!grade) return '<span class="badge">-</span>';
    const map = { 'A+': 'badge-ap', 'A': 'badge-a', 'B': 'badge-b', 'C': 'badge-c', 'D': 'badge-d' };
    const cls = map[grade] || 'badge';
    return `<span class="badge ${cls}">${grade}</span>`;
  },

  bindSnapshotTabs() {
    document.querySelectorAll('.ret-snap-tab').forEach(btn => {
      btn.addEventListener('click', async () => {
        const month = btn.dataset.month;
        await this.loadSnapshot(month);
      });
    });
  },

  bindRowDetails() {
    document.querySelectorAll('.ret-detail-toggle').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const detail = document.querySelector(`.ret-detail-row[data-for="${id}"]`);
        if (!detail) return;
        const open = detail.style.display !== 'none';
        detail.style.display = open ? 'none' : '';
        btn.textContent = open ? '▶' : '▼';
      });
    });
  },

  async loadSnapshot(baseMonth) {
    const body = document.getElementById('retentionBody');
    body.innerHTML = '<div class="loading">로딩 중...</div>';
    const res = await API.get(`/api/retention.php?action=view&base_month=${encodeURIComponent(baseMonth)}`);
    if (!res.ok) { body.innerHTML = `<div class="empty-state">${res.message}</div>`; return; }
    this.loadFromResponse(res.data);
    this.renderBody();
    document.getElementById('ret_baseMonth').value = baseMonth;
    document.getElementById('ret_totalNew').value  = this.state.totalNew;
    document.getElementById('ret_shiftHint').innerHTML = this.shiftHintText(baseMonth);
  },
```

그리고 기존 `async loadSnapshots()`를 개선하여, 스냅샷이 있으면 최신 것 자동 로드:

```javascript
  async loadSnapshots() {
    const res = await API.get('/api/retention.php?action=snapshots');
    if (!res.ok) return;
    this.snapshots = res.data.snapshots || [];
    if (this.state.baseMonth) {
      this.renderBody();
    } else if (this.snapshots.length > 0) {
      await this.loadSnapshot(this.snapshots[0].base_month);
    } else {
      document.getElementById('retentionBody').innerHTML =
        '<div class="empty-state">아직 계산된 스냅샷이 없습니다. 기준월과 전체 신규 인원을 입력하고 계산하세요.</div>';
    }
  },
```

- [ ] **Step 2: 브라우저 검증**

1. `#retention` 접근 → 스냅샷이 없으면 빈 상태 메시지 표시
2. 기준월=`2026-05`, 전체 신규 인원=`60`으로 계산 실행
3. 계산 후: 스냅샷 탭 버튼 1개 (2026-05), 요약 패널 4개 카드, 결과 테이블 N행 표시
4. 테이블 각 행의 "등수, 코치, 등급, 총점, 3M 신규, 3M 기존, 담당, 희망, 자동, 최종" 데이터 채워짐
5. "▶" 버튼 클릭 → 월별 상세 테이블 펼침 / "▼"가 되고 다시 클릭 시 접힘
6. 테스트 잔해 정리 (Task 10 Step 5 참고)

- [ ] **Step 3: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/admin/js/pages/retention.js
git commit -m "feat(admin): retention snapshot tabs + summary + result table + detail toggle"
```

---

### Task 12: [Phase 2] 최종 배정 인라인 편집 + debounce + 낙관적 락 충돌 처리

**Files:**
- Modify: `public_html/admin/js/pages/retention.js`

- [ ] **Step 1: 최종 배정 input 활성화 + 편집 핸들러**

Edit `public_html/admin/js/pages/retention.js`. `renderTableRow` 내의 `<input type="number" ... readonly>`에서 `readonly` 제거.

이전:
```html
<input type="number" class="ret-final-input" value="${r.final_allocation}" min="0" max="9999" readonly data-id="${r.id}">
```
이후:
```html
<input type="number" class="ret-final-input" value="${r.final_allocation}" min="0" max="9999" data-id="${r.id}" data-updated="${r.updated_at}">
```

`bindRowDetails` 메서드를 찾아서 그 뒤에 바인딩 메서드를 추가:

```javascript
  bindFinalInputs() {
    document.querySelectorAll('.ret-final-input').forEach(input => {
      input.addEventListener('input', () => {
        this.recomputeSummary();
        this.scheduleSave(input);
      });
    });
  },

  recomputeSummary() {
    // Local sum from current inputs (before server confirms)
    let sumFinal = 0;
    document.querySelectorAll('.ret-final-input').forEach(i => {
      sumFinal += parseInt(i.value, 10) || 0;
    });
    this.state.summary.sum_final = sumFinal;
    this.state.summary.unallocated = this.state.summary.total_new - sumFinal;

    // Update DOM summary (the "현재 합" and "잔여" cards)
    const cards = document.querySelectorAll('.ret-summary-card');
    if (cards.length >= 4) {
      cards[2].querySelector('.value').textContent = `${sumFinal}명`;
      const unalloc = this.state.summary.unallocated;
      const fourth = cards[3];
      fourth.querySelector('.value').textContent = `${unalloc}명`;
      fourth.classList.remove('ret-unalloc-pos','ret-unalloc-neg','ret-unalloc-zero');
      fourth.classList.add(unalloc > 0 ? 'ret-unalloc-pos' : (unalloc < 0 ? 'ret-unalloc-neg' : 'ret-unalloc-zero'));
    }
  },

  scheduleSave(input) {
    const id = parseInt(input.dataset.id, 10);
    const status = document.querySelector(`.ret-save-status[data-id="${id}"]`);
    if (status) status.textContent = '…';

    const prev = this.state.pendingSaves.get(id);
    if (prev) clearTimeout(prev);
    const handle = setTimeout(() => this.saveAllocation(input), 600);
    this.state.pendingSaves.set(id, handle);
  },

  async saveAllocation(input) {
    const id = parseInt(input.dataset.id, 10);
    const status = document.querySelector(`.ret-save-status[data-id="${id}"]`);
    const value = parseInt(input.value, 10) || 0;
    const expected = input.dataset.updated;

    this.state.pendingSaves.delete(id);

    const res = await API.post('/api/retention.php?action=update_allocation', {
      id, final_allocation: value, expected_updated_at: expected,
    });

    if (!res.ok) {
      if (status) status.innerHTML = '<span class="ret-save-err">!</span>';
      UI.toast('저장 실패: ' + (res.message || ''));
      return;
    }
    const data = res.data;
    if (data.ok === false && data.code === 'conflict') {
      // Refresh row to server value
      this.mergeRow(data.row);
      UI.toast('다른 작업으로 갱신되었습니다. 최신값으로 로드했습니다.');
      if (status) status.innerHTML = '<span class="ret-save-err">×</span>';
      return;
    }
    // success
    this.mergeRow(data.row);
    if (data.summary) {
      this.state.summary = data.summary;
      this.refreshSummaryCards();
    }
    if (status) status.innerHTML = '<span class="ret-save-ok">✓</span>';
  },

  mergeRow(row) {
    const idx = this.state.rows.findIndex(r => r.id === row.id);
    if (idx >= 0) this.state.rows[idx] = { ...this.state.rows[idx], ...row };
    // Update only the input value + data-updated attribute; don't rerender whole table
    const input = document.querySelector(`.ret-final-input[data-id="${row.id}"]`);
    if (input) {
      input.value = row.final_allocation;
      input.dataset.updated = row.updated_at;
    }
  },

  refreshSummaryCards() {
    const s = this.state.summary;
    const cards = document.querySelectorAll('.ret-summary-card');
    if (cards.length < 4) return;
    cards[0].querySelector('.value').textContent = `${s.total_new}명`;
    cards[1].querySelector('.value').textContent = `${s.sum_auto}명`;
    cards[2].querySelector('.value').textContent = `${s.sum_final}명`;
    cards[3].querySelector('.value').textContent = `${s.unallocated}명`;
    cards[3].classList.remove('ret-unalloc-pos','ret-unalloc-neg','ret-unalloc-zero');
    const u = s.unallocated;
    cards[3].classList.add(u > 0 ? 'ret-unalloc-pos' : (u < 0 ? 'ret-unalloc-neg' : 'ret-unalloc-zero'));
  },
```

`renderBody`에서 `bindRowDetails` 호출 뒤에 `bindFinalInputs` 호출 추가:

```javascript
  renderBody() {
    const html = `
      ${this.renderSnapshotTabs()}
      ${this.renderUnmappedBanner()}
      ${this.renderSummary()}
      ${this.renderTable()}
    `;
    document.getElementById('retentionBody').innerHTML = html;
    this.bindSnapshotTabs();
    this.bindRowDetails();
    this.bindFinalInputs();
  },
```

또 페이지 이탈 시 debounce 강제 flush:

```javascript
  async flushPendingSaves() {
    for (const [id, handle] of this.state.pendingSaves.entries()) {
      clearTimeout(handle);
      const input = document.querySelector(`.ret-final-input[data-id="${id}"]`);
      if (input) await this.saveAllocation(input);
    }
  },
```

라우터에서 페이지 이탈은 SPA `hashchange` 이벤트가 맡는다. `beforeunload`에도 연결:

```javascript
  bindCalcForm() {
    // 기존 바인딩 ...
    window.addEventListener('beforeunload', () => {
      // 브라우저 종료 시 best-effort (async 보장 안 됨. 네비게이션 내 이탈은 SPA가 처리)
      for (const [id, h] of this.state.pendingSaves.entries()) clearTimeout(h);
    });
  },
```

- [ ] **Step 2: 브라우저 검증 — 기본 편집**

1. 기준월 `2026-05`, 전체 신규 인원 `60`으로 계산
2. 아무 코치의 "최종" 셀에서 숫자를 3씩 올려본다 (화살표 up 버튼 또는 타이핑)
3. 즉시 상단 "현재 합"과 "잔여"가 갱신됨을 확인
4. 600ms 후 셀 옆 상태 아이콘이 `…` → `✓`로 변함
5. DB 검증:
   ```bash
   source /var/www/html/_______site_SORITUNECOM_PT/.db_credentials
   mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
     SELECT id, rank_num, auto_allocation, final_allocation, adjusted_by, adjusted_at, updated_at
       FROM coach_retention_scores
      WHERE base_month = '2026-05'
      ORDER BY adjusted_at DESC LIMIT 3;
   "
   ```
   `final_allocation`이 업데이트, `adjusted_by`·`adjusted_at` 기록됨, `updated_at`이 갱신됨.

- [ ] **Step 3: 브라우저 검증 — 충돌 시나리오 (수동)**

1. 계산 후 결과 페이지에서 임의 코치 행 A의 `final_allocation`을 브라우저에서 +5 편집 (저장 중 '…' 상태)
2. 다른 탭이나 curl로 동일 row의 updated_at을 바꾼다:
   ```bash
   mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
     UPDATE coach_retention_scores SET final_allocation = final_allocation
      WHERE base_month = '2026-05' ORDER BY rank_num ASC LIMIT 1;
   "
   ```
   (MySQL `ON UPDATE CURRENT_TIMESTAMP(3)`로 토큰 bump됨 — no-op UPDATE라도 rowCount=1)
3. 600ms 후 브라우저에서: 상태 아이콘이 `×`로, 토스트 "다른 작업으로 갱신되었습니다…" 나타남. 입력 값이 서버의 최신 값으로 복원됨.

- [ ] **Step 4: 테스트 잔해 정리**

```bash
source /var/www/html/_______site_SORITUNECOM_PT/.db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  DELETE FROM coach_retention_scores WHERE base_month = '2026-05';
  DELETE FROM coach_retention_runs   WHERE base_month = '2026-05';
  DELETE FROM change_logs WHERE target_type = 'retention_allocation' AND created_at >= CURDATE();
"
```

- [ ] **Step 5: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/admin/js/pages/retention.js
git commit -m "$(cat <<'EOF'
feat(admin): retention allocation editing with debounced save + conflict handling

Inline numeric input per row, 600ms debounce before POST
update_allocation, expected_updated_at carried in data-updated. On
{code:'conflict'} response the row is refreshed to server value with a
toast. Local re-sum updates "현재 합/잔여" immediately.
EOF
)"
```

---

### Task 13: [Phase 2] reset / delete 버튼 연결 + 수동 flush

**Files:**
- Modify: `public_html/admin/js/pages/retention.js`

- [ ] **Step 1: reset·delete 버튼 핸들러**

Edit `public_html/admin/js/pages/retention.js`. `bindSnapshotTabs` 메서드 뒤에 추가:

```javascript
  bindSummaryActions() {
    const resetBtn = document.getElementById('ret_resetBtn');
    if (resetBtn) {
      resetBtn.addEventListener('click', async () => {
        if (!this.state.baseMonth) return;
        if (!confirm(`${this.state.baseMonth}의 모든 최종 배정을 자동값으로 되돌릴까요?`)) return;
        await this.flushPendingSaves();
        const res = await API.post('/api/retention.php?action=reset_allocation', { base_month: this.state.baseMonth });
        if (!res.ok) { alert(res.message || '리셋 실패'); return; }
        await this.loadSnapshot(this.state.baseMonth);
        UI.toast(`${res.data.updated_rows}개 행이 자동값으로 복원되었습니다.`);
      });
    }
    const delBtn = document.getElementById('ret_deleteBtn');
    if (delBtn) {
      delBtn.addEventListener('click', async () => {
        if (!this.state.baseMonth) return;
        if (!confirm(`${this.state.baseMonth} 스냅샷을 완전히 삭제할까요? (되돌릴 수 없습니다)`)) return;
        await this.flushPendingSaves();
        const res = await API.post('/api/retention.php?action=delete_snapshot', { base_month: this.state.baseMonth });
        if (!res.ok) { alert(res.message || '삭제 실패'); return; }
        UI.toast(`삭제 완료: ${res.data.deleted_scores}행 + 스냅샷 메타 ${res.data.deleted_runs}건`);
        this.state.baseMonth = null;
        this.state.rows = [];
        await this.loadSnapshots();
      });
    }
  },
```

`renderBody`에서 `bindFinalInputs` 호출 뒤에 `bindSummaryActions()` 호출을 추가:
```javascript
    this.bindRowDetails();
    this.bindFinalInputs();
    this.bindSummaryActions();
```

- [ ] **Step 2: 브라우저 검증 — reset**

1. 기준월 `2026-05`, 전체 신규 인원 `60`으로 계산
2. 몇 행의 final을 임의로 조정 (저장 ✓까지 대기)
3. "자동값으로 리셋" 클릭 → 확인 다이얼로그 → 확인
4. 테이블이 리로드되면서 모든 final = auto로 복원됨
5. "현재 합"이 "자동 배정 합"과 같아지고, "잔여"가 `total_new − 자동 합`으로 표시

- [ ] **Step 3: 브라우저 검증 — delete**

1. 같은 상태에서 "스냅샷 삭제" 클릭 → 확인 → 삭제
2. 테이블이 비워지고 "아직 계산된 스냅샷이 없습니다" 빈 상태로 돌아감
3. DB 검증:
   ```bash
   mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
     SELECT COUNT(*) FROM coach_retention_scores WHERE base_month='2026-05';
     SELECT COUNT(*) FROM coach_retention_runs   WHERE base_month='2026-05';
   "
   ```
   Expected: 둘 다 0.

- [ ] **Step 4: 로그 정리**

```bash
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  DELETE FROM change_logs WHERE target_type = 'retention_allocation' AND created_at >= CURDATE();
"
```

- [ ] **Step 5: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/admin/js/pages/retention.js
git commit -m "feat(admin): retention reset/delete buttons with confirm + pending-save flush"
```

---

### Task 14: [Phase 2] CSS 추가 (요약 sticky / 등급 badge / 잔여 색상)

**Files:**
- Modify: `public_html/assets/css/style.css`

- [ ] **Step 1: 스타일 추가**

Edit `public_html/assets/css/style.css`. 파일 맨 끝에 아래 블록을 추가:

```css
/* =========================================================
   Retention Management Page
   ========================================================= */

.ret-calc-form {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: flex-end;
}
.ret-shift-hint {
  margin-top: 8px;
  color: var(--text-secondary);
  font-size: 12px;
}

.ret-snap-row { display: flex; gap: 10px; align-items: center; }
.ret-snap-label { font-size: 13px; color: var(--text-secondary); }
.ret-snap-tabs  { display: flex; gap: 6px; flex-wrap: wrap; }

.ret-unmapped-banner {
  background: #3b2a00;
  color: #ffa42b;
  border-left: 3px solid #ffa42b;
  padding: 10px 14px;
  margin-bottom: 12px;
  border-radius: 4px;
  font-size: 13px;
}

.ret-summary-sticky {
  position: sticky;
  top: 0;
  z-index: 10;
  background: #121212;
  padding: 12px 0;
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 12px;
  border-bottom: 1px solid #272727;
}
.ret-summary-card {
  background: #181818;
  border-radius: 6px;
  padding: 10px 14px;
  min-width: 100px;
}
.ret-summary-card .label { color: var(--text-secondary); font-size: 11px; }
.ret-summary-card .value { font-size: 18px; font-weight: 700; margin-top: 2px; }
.ret-summary-card.ret-unalloc-pos  .value { color: #FF5E00; }
.ret-summary-card.ret-unalloc-neg  .value { color: #f3727f; }
.ret-summary-card.ret-unalloc-zero .value { color: var(--text-secondary); }
.ret-summary-actions { margin-left: auto; display: flex; gap: 6px; }

.ret-table .ret-final-input {
  width: 64px;
  text-align: right;
  padding: 4px 6px;
  border-radius: 4px;
  border: 1px solid #4d4d4d;
  background: #1f1f1f;
  color: #fff;
}
.ret-save-status { display: inline-block; width: 14px; margin-left: 4px; }
.ret-save-ok  { color: #7bc47f; }
.ret-save-err { color: #f3727f; font-weight: 700; }

.ret-detail-row td { background: #1a1a1a; padding: 12px 16px; }
.ret-detail-table { width: auto; font-size: 13px; }
.ret-detail-table th { color: var(--text-secondary); font-weight: 600; padding: 4px 10px; }
.ret-detail-table td { padding: 4px 10px; }

.ret-deleted { color: var(--text-secondary); font-style: italic; }

/* Grade badges */
.badge-ap { background: #FF5E00; color: #000; }
.badge-a  { background: #ffa42b; color: #000; }
.badge-b  { background: #539df5; color: #000; }
.badge-c  { background: #7c7c7c; color: #000; }
.badge-d  { background: #4d4d4d; color: #fff; }
```

- [ ] **Step 2: 브라우저 스타일 검증**

1. 리텐션 페이지 열기 → 계산 실행
2. 요약 패널이 상단에 sticky로 고정되어 스크롤해도 보임
3. "잔여"가 양수(주황), 0(회색), 음수(빨강)로 색상 변화 (전체 신규 인원을 작게/크게 주며 테스트)
4. 등급 뱃지 A+(주황), A(노랑), B(파랑), C(회색), D(진회색)
5. 상세 펼침 영역이 약간 어두운 배경 (`#1a1a1a`)
6. 최종 셀 input이 어두운 배경에 오렌지 포커스 없이 자연스럽게 표시

- [ ] **Step 3: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/assets/css/style.css
git commit -m "style(retention): sticky summary + grade badges + 잔여 색상 + detail row"
```

---

### Task 15: [Phase 2] 통합 수동 검증 체크리스트

이 태스크는 새로운 코드를 쓰지 않는다. 전체 흐름을 spec §11 체크리스트대로 재검증한다.

**Files:** 없음 (검증만)

- [ ] **Step 1: spec §11 체크리스트 확인**

`docs/superpowers/specs/2026-04-24-pt-retention-management-design.md`의 §11을 다시 펼쳐놓고 항목별로 실행한다.

- [ ] **Step 2: 11.1 GRANT 검증**

Task 3 Step 3을 다시 실행해 cross-DB SELECT가 성공하는지. (이미 프로덕션 상태)

- [ ] **Step 3: 11.2 월 시프트 정합성**

- coach 사이트 admin에서 과거 스냅샷 아무거나(예: `2026-04`) 펼쳐 각 코치의 상세 월 테이블을 연다
- coach 스냅샷 `2026-04`의 `monthly_detail`이 어떤 월 쌍을 보이는지 확인 (현재 coach 로직은 `[04→04 없음, 03→04, 02→03]`처럼 base month를 포함)
- PT에서 `base_month=2026-05`로 계산, 같은 코치의 상세를 펼쳐 `[03→04, 02→03, 01→02]` 세 쌍이 보이는지 확인
- coach `2026-04` 스냅샷에서 보이는 실제 리텐션 숫자 중 `03→04` 쌍과 PT `2026-05`의 `03→04` 쌍이 **같은 코치에서 동일한 수치**인지 한 명 스팟체크

- [ ] **Step 4: 11.3 자동 배분 동일성**

- 동일한 `grade_criteria`·희망 신청 상태에서 PT와 coach 사이트 auto 배정이 일치하는지 (월 시프트 차이는 감안)
- 상위권 합이 total_new를 초과하는 기준월에서 "잔여" 표시가 음수로 빨강 뜨는지 (§11.6)

- [ ] **Step 5: 11.4 코치 매핑 시나리오**

임시로 PT 코치 한 명의 `coach_name`을 찾아 오타로 바꾸고 재계산 → coach_site_only 경고 배너에 그 이름 노출되는지. 원복 후 재계산 → 배너에서 사라지는지.

```bash
# 시작 전 원래 값 저장
source /var/www/html/_______site_SORITUNECOM_PT/.db_credentials
ORIG=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "
  SELECT coach_name FROM coaches WHERE status='active' ORDER BY id LIMIT 1;")
echo "ORIG=$ORIG"
# 오타로 변경
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  UPDATE coaches SET coach_name = CONCAT(coach_name,'_typo')
   WHERE coach_name='$ORIG';"
# 브라우저에서 재계산 → coach_site_only에 ORIG 이름 노출 확인
# 원복
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  UPDATE coaches SET coach_name='$ORIG'
   WHERE coach_name='${ORIG}_typo';"
```

- [ ] **Step 6: 11.5 수동 조정 흐름**

- 재계산 → 초기 final=auto인지
- 개별 셀 편집 → 잔여 즉시 갱신
- 새로고침 → 값 유지
- 같은 base_month 재계산 → auto는 바뀌어도 final은 보존 (Task 7 Step 5 시나리오)
- "자동값으로 리셋" → 모두 auto로
- change_logs 확인:
  ```bash
  mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
    SELECT target_type, action, COUNT(*) AS n
      FROM change_logs
     WHERE target_type='retention_allocation' AND created_at >= CURDATE()
     GROUP BY target_type, action;"
  ```

- [ ] **Step 7: 11.5a 동시성 시나리오**

- Reset 경합: 셀 편집 중 대기 상태에서 "자동값으로 리셋" 클릭 → stale 요청이 conflict로 거절되고 UI에 `×` 상태 + 토스트
- Calculate 경합: 셀 편집 중 대기 상태에서 같은 base_month 재계산 → stale 요청 conflict
- 탭 2개: 탭1에서 A=5, 탭2에서 A=7로 편집 → 나중 도착이 conflict로 거절 + UI가 승자 값으로 동기화
- 삭제 코치 스냅샷: 임시로 코치 하나를 PT에서 삭제한 뒤 과거 스냅샷 조회 → "이름 (삭제됨)" 표시

- [ ] **Step 8: 11.6 잔여 극단값**

- total_new = 0 → 자동 0, 잔여 0
- total_new < 자동 합 → 잔여 음수 빨강
- 매핑 실패 코치만 있는 상태 → 빈 테이블 + 경고 배너

- [ ] **Step 9: 11.7 1단계 hotfix 재검증**

Task 1 Step 4와 동일 — 코치 로그인 시 "등급" 컬럼 없음 / admin assignment 페이지에선 존재.

- [ ] **Step 10: 잔해 정리 (최종)**

```bash
source /var/www/html/_______site_SORITUNECOM_PT/.db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  DELETE FROM coach_retention_scores WHERE base_month IN ('2026-05');
  DELETE FROM coach_retention_runs   WHERE base_month IN ('2026-05');
  DELETE FROM change_logs WHERE target_type='retention_allocation' AND created_at >= CURDATE();
"
```

- [ ] **Step 11: 결과 기록**

수동 검증 체크리스트 결과를 사용자에게 보고. 통과 항목 표시, 실패가 있으면 원인·재현 조건·제안 수정안 제시. 통과면 구현 종료.

---

## Self-Review

### Spec 커버리지

| Spec 섹션 | 구현 태스크 |
|---|---|
| §2.1 1단계 hotfix | Task 1 |
| §4 아키텍처 (cross-DB) | Task 3 (GRANT), Task 4 (mapping), Task 5 (query) |
| §5.1 coach_retention_scores 스키마 | Task 2 |
| §5.2 coach_retention_runs 스키마 | Task 2 |
| §5.3 change_logs ENUM 확장 | Task 2 |
| §5.4 재계산 시 필드별 정책 | Task 5 (UPSERT ON DUPLICATE KEY UPDATE) |
| §6.1 월 시프트 | Task 5 Step 1 (for $i=1;$i≤3;) |
| §6.2 cross-DB + 코치 매핑 | Task 4 + Task 5 |
| §6.3 저장 대상 | Task 5 |
| §6.5 UI 월 시프트 표기 | Task 10 (shiftHintText) |
| §7 snapshots | Task 6 |
| §7 view | Task 6 |
| §7 calculate | Task 7 |
| §7 update_allocation | Task 8 |
| §7 reset_allocation | Task 9 |
| §7 delete_snapshot | Task 9 |
| §8.1 라우팅 | Task 10 |
| §8.2 화면 구성 | Task 10, 11, 14 |
| §8.3 상호작용 + 충돌 처리 | Task 12, 13 |
| §9.1 GRANT | Task 3 |
| §9.2 cross-DB 실패 처리 | Task 7 Step 1 (PDOException catch) |
| §9.3 코치 매핑 실패 | Task 4, 11, 15 |
| §9.4 감사 로그 | Task 8 (logChange), Task 9 (reset/delete도) |
| §9.5 낙관적 락 프로토콜 | Task 2 (updated_at 컬럼) + Task 8 (API) + Task 12 (client) |
| §10 coach 사이트 hotfix | Task 1 |
| §11 수동 검증 | Task 15 |
| §12 배포 순서 | Task 2 → 3 → 4 → … → 14 순서가 곧 배포 순서 |

### Placeholder 스캔

- "TBD"/"TODO" 없음
- 일반론적 "에러 처리 추가" 없음 — 모든 에러 처리는 구체 구문으로 명시
- "similar to Task N" 없음 — 각 태스크는 자체 완결 코드
- 모든 스텝에 실제 실행 가능한 명령/코드 존재

### Type/시그니처 일관성

- `loadCoachMapping(PDO $db): array` 반환 키(`pt_by_name`, `pt_to_coach`, `pt_only`, `coach_site_only`)가 Task 4 (정의)와 Task 5 (사용)에서 동일
- `calculateRetention(PDO, string, int, int)` 시그니처가 Task 5 (정의)와 Task 7 (호출) 동일
- `_fetchSnapshotRows(PDO, string): array` Task 5에 정의 + Task 6에서 사용
- `_fetchRowById(PDO, int): ?array` Task 8에 정의되고 같은 파일 내에서만 사용
- `App.pages.retention.state` 필드명이 Task 10에서 선언된 것과 Task 11/12/13에서 참조하는 것 일치 (`baseMonth`, `rows`, `unmapped`, `summary`, `pendingSaves`)
- `UI.toast`, `UI.esc`, `API.get/post`는 기존 `admin/js/app.js`에 이미 존재

---

## Execution Handoff

**Plan complete and saved to** `docs/superpowers/plans/2026-04-24-pt-retention-management.md`.

**Two execution options:**

**1. Subagent-Driven (recommended)** — 각 태스크마다 fresh 서브에이전트를 보내 구현시키고 태스크 사이에 리뷰, 빠른 반복

**2. Inline Execution** — 현재 세션에서 executing-plans skill로 직접 수행, 체크포인트에서 사용자 리뷰

**Which approach?**
