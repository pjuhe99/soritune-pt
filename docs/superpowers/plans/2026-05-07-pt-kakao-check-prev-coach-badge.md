# PT 카톡방 입장 체크 — 기존/신규 배지 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `pt.soritune.com` 카톡방 입장 체크 페이지(어드민/코치)의 이름 컬럼에 `[기존]` (회색) / `[신규]` (주황) 배지를 prefix로 표시. 회원의 마지막 정상 order(같은 product, status NOT IN '환불','중단') 의 coach_id 가 현재 coach_id 와 같으면 `[기존]`, 그 외(이전 없음/다른 코치)는 `[신규]`.

**Architecture:** 서버 `kakaoCheckList()` SELECT에 correlated subquery 한 줄 추가하여 응답 row에 `prev_coach_id` 추가. 어드민/코치 JS `_row()` 에서 `prev_coach_id === coach_id` 비교로 배지 결정. CSS는 공통 `style.css` 끝에 2종 append. DB 스키마 변경 없음.

**Tech Stack:** PHP 8.x + MariaDB 10.5 + 바닐라 JS (Admin/Coach SPA). 테스트는 자체 PHP harness (`tests/run_tests.php`).

**Spec:** `docs/superpowers/specs/2026-05-07-pt-kakao-check-prev-coach-badge-design.md`

---

## File Structure

**Modified:**
- `public_html/api/kakao_check.php` — `kakaoCheckList()` SELECT에 `prev_coach_id` correlated subquery 1줄 추가
- `public_html/admin/js/pages/kakao-check.js` — `_row()` 이름 셀 prefix
- `public_html/coach/js/pages/kakao-check.js` — `_row()` 이름 셀 prefix
- `public_html/assets/css/style.css` — `.badge-returning` + `.badge-new` 끝에 append
- `tests/kakao_check_test.php` — 5 신규 케이스 끝에 append

**Not changed:**
- DB 스키마 (계산형 — 마이그 없음)
- 라우터 / `kakaoCheckToggleFlag()` / 알림톡 어댑터
- `cohort_month` 의미

---

## Task 1: 서버 — `kakaoCheckList()` SELECT 에 `prev_coach_id` 추가 (TDD)

**Files:**
- Modify: `public_html/api/kakao_check.php` (`kakaoCheckList()` 의 `$oSql` SELECT 컬럼)
- Modify: `tests/kakao_check_test.php` (5 신규 섹션 끝에 append)

- [ ] **Step 1: 실패 테스트 작성 — 같은 코치 같은 product**

`tests/kakao_check_test.php` 끝에 추가:

```php
t_section('list — prev_coach_id: 같은 코치 같은 product → 본인 coach_id');

if ($activeCoach === 0) {
    echo "  SKIP\n";
} else {
    $db->beginTransaction();
    // 같은 회원의 직전 cohort 정상 order (같은 코치, 같은 product)
    $prev = t_make_order($db, [
        'coach_id' => $activeCoach,
        'status' => '종료',
        'start_date' => '2026-03-10',
        'end_date'   => '2026-04-09',
        'product_name' => '소리튜닝 음성PT',
    ]);
    // 직전 order 의 member_id 를 새 order 와 동일하게 맞춤
    $memberId = (int)$db->query("SELECT member_id FROM orders WHERE id={$prev}")->fetchColumn();
    $cur = t_make_order($db, [
        'coach_id' => $activeCoach,
        'status' => '진행중',
        'start_date' => '2026-04-15',
        'end_date'   => '2026-07-14',
        'product_name' => '소리튜닝 음성PT',
    ]);
    $db->prepare("UPDATE orders SET member_id=? WHERE id=?")->execute([$memberId, $cur]);

    $r = kakaoCheckList($db, [
        'cohort' => '2026-04', 'coach_id' => $activeCoach,
        'include_processed' => true, 'product' => null,
    ]);
    $found = null;
    foreach ($r['orders'] as $row) {
        if ((int)$row['order_id'] === $cur) { $found = $row; break; }
    }
    t_assert_true($found !== null, '현재 order 결과에 포함');
    t_assert_eq($activeCoach, (int)$found['prev_coach_id'], 'prev_coach_id = activeCoach');

    $db->rollBack();
}
```

- [ ] **Step 2: 실패 테스트 추가 — 다른 코치**

```php
t_section('list — prev_coach_id: 직전이 다른 코치');

if ($activeCoach === 0 || $otherCoach === 0) {
    echo "  SKIP\n";
} else {
    $db->beginTransaction();
    $prev = t_make_order($db, [
        'coach_id' => $otherCoach,
        'status' => '종료',
        'start_date' => '2026-03-10',
        'end_date'   => '2026-04-09',
        'product_name' => '소리튜닝 음성PT',
    ]);
    $memberId = (int)$db->query("SELECT member_id FROM orders WHERE id={$prev}")->fetchColumn();
    $cur = t_make_order($db, [
        'coach_id' => $activeCoach,
        'status' => '진행중',
        'start_date' => '2026-04-15',
        'end_date'   => '2026-07-14',
        'product_name' => '소리튜닝 음성PT',
    ]);
    $db->prepare("UPDATE orders SET member_id=? WHERE id=?")->execute([$memberId, $cur]);

    $r = kakaoCheckList($db, [
        'cohort' => '2026-04', 'coach_id' => $activeCoach,
        'include_processed' => true, 'product' => null,
    ]);
    $found = null;
    foreach ($r['orders'] as $row) {
        if ((int)$row['order_id'] === $cur) { $found = $row; break; }
    }
    t_assert_true($found !== null, '결과에 포함');
    t_assert_eq($otherCoach, (int)$found['prev_coach_id'], 'prev_coach_id = otherCoach');
    t_assert_true((int)$found['prev_coach_id'] !== (int)$found['coach_id'], 'prev != cur');

    $db->rollBack();
}
```

- [ ] **Step 3: 실패 테스트 추가 — 다른 product 는 무시**

```php
t_section('list — prev_coach_id: 다른 product 직전 order 는 무시');

if ($activeCoach === 0) {
    echo "  SKIP\n";
} else {
    $db->beginTransaction();
    // 직전 order 가 화상PT (다른 product)
    $prev = t_make_order($db, [
        'coach_id' => $activeCoach,
        'status' => '종료',
        'start_date' => '2026-03-10',
        'end_date'   => '2026-04-09',
        'product_name' => '소리튜닝 화상PT',
    ]);
    $memberId = (int)$db->query("SELECT member_id FROM orders WHERE id={$prev}")->fetchColumn();
    $cur = t_make_order($db, [
        'coach_id' => $activeCoach,
        'status' => '진행중',
        'start_date' => '2026-04-15',
        'end_date'   => '2026-07-14',
        'product_name' => '소리튜닝 음성PT',
    ]);
    $db->prepare("UPDATE orders SET member_id=? WHERE id=?")->execute([$memberId, $cur]);

    $r = kakaoCheckList($db, [
        'cohort' => '2026-04', 'coach_id' => $activeCoach,
        'include_processed' => true, 'product' => null,
    ]);
    $found = null;
    foreach ($r['orders'] as $row) {
        if ((int)$row['order_id'] === $cur) { $found = $row; break; }
    }
    t_assert_true($found !== null, '결과에 포함');
    t_assert_true($found['prev_coach_id'] === null, '다른 product → prev_coach_id NULL');

    $db->rollBack();
}
```

- [ ] **Step 4: 실패 테스트 추가 — 환불/중단은 무시**

```php
t_section('list — prev_coach_id: 환불/중단 order 는 무시');

if ($activeCoach === 0) {
    echo "  SKIP\n";
} else {
    $db->beginTransaction();
    $prev = t_make_order($db, [
        'coach_id' => $activeCoach,
        'status' => '환불',
        'start_date' => '2026-03-10',
        'end_date'   => '2026-04-09',
        'product_name' => '소리튜닝 음성PT',
    ]);
    $memberId = (int)$db->query("SELECT member_id FROM orders WHERE id={$prev}")->fetchColumn();
    $cur = t_make_order($db, [
        'coach_id' => $activeCoach,
        'status' => '진행중',
        'start_date' => '2026-04-15',
        'end_date'   => '2026-07-14',
        'product_name' => '소리튜닝 음성PT',
    ]);
    $db->prepare("UPDATE orders SET member_id=? WHERE id=?")->execute([$memberId, $cur]);

    $r = kakaoCheckList($db, [
        'cohort' => '2026-04', 'coach_id' => $activeCoach,
        'include_processed' => true, 'product' => null,
    ]);
    $found = null;
    foreach ($r['orders'] as $row) {
        if ((int)$row['order_id'] === $cur) { $found = $row; break; }
    }
    t_assert_true($found !== null, '결과에 포함');
    t_assert_true($found['prev_coach_id'] === null, '환불 → prev_coach_id NULL');

    $db->rollBack();
}
```

- [ ] **Step 5: 실패 테스트 추가 — 이전 order 없음**

```php
t_section('list — prev_coach_id: 이전 order 없음 → NULL');

if ($activeCoach === 0) {
    echo "  SKIP\n";
} else {
    $db->beginTransaction();
    $cur = t_make_order($db, [
        'coach_id' => $activeCoach,
        'status' => '진행중',
        'start_date' => '2026-04-15',
        'end_date'   => '2026-07-14',
        'product_name' => '소리튜닝 음성PT',
    ]);
    // 새 member 그대로 (t_make_order 가 매번 새 member 생성하는 구조 가정)

    $r = kakaoCheckList($db, [
        'cohort' => '2026-04', 'coach_id' => $activeCoach,
        'include_processed' => true, 'product' => null,
    ]);
    $found = null;
    foreach ($r['orders'] as $row) {
        if ((int)$row['order_id'] === $cur) { $found = $row; break; }
    }
    t_assert_true($found !== null, '결과에 포함');
    t_assert_true($found['prev_coach_id'] === null, '이전 order 없음 → NULL');

    $db->rollBack();
}
```

- [ ] **Step 6: 테스트 실행해 실패 확인**

Run: `php /root/pt-dev/tests/run_tests.php 2>&1 | grep -E "FAIL|prev_coach_id" | head -20`
Expected: 5 신규 섹션 모두 FAIL — `Undefined array key "prev_coach_id"` 또는 NULL 비교 실패.

- [ ] **Step 7: `kakaoCheckList()` SELECT 수정**

`public_html/api/kakao_check.php` 의 `$oSql` 안에서 `o.coach_id,` 다음 줄에 correlated subquery 1줄 추가.

기존 (line 97-98):
```php
          o.coach_id,
          c.coach_name
```

변경:
```php
          o.coach_id,
          (SELECT p.coach_id
             FROM orders p
            WHERE p.member_id = o.member_id
              AND p.id <> o.id
              AND p.product_name = o.product_name
              AND p.status NOT IN ('환불','중단')
              AND COALESCE(p.cohort_month, DATE_FORMAT(p.start_date, '%Y-%m'))
                   < COALESCE(o.cohort_month, DATE_FORMAT(o.start_date, '%Y-%m'))
            ORDER BY COALESCE(p.cohort_month, DATE_FORMAT(p.start_date, '%Y-%m')) DESC, p.id DESC
            LIMIT 1) AS prev_coach_id,
          c.coach_name
```

- [ ] **Step 8: 테스트 실행해 통과 확인**

Run: `php /root/pt-dev/tests/run_tests.php 2>&1 | tail -10`
Expected: 새로 추가한 5 섹션 모두 PASS. 기존 섹션도 그대로 PASS (SELECT는 컬럼 추가만, 행 셋 변화 없음).

- [ ] **Step 9: PHP lint 확인**

Run: `php -l /root/pt-dev/public_html/api/kakao_check.php`
Expected: `No syntax errors detected`.

- [ ] **Step 10: 커밋**

```bash
cd /root/pt-dev
git add public_html/api/kakao_check.php tests/kakao_check_test.php
git commit -m "feat(pt): kakaoCheckList SELECT 에 prev_coach_id correlated subquery 추가

회원의 마지막 정상 order(같은 product, status NOT IN '환불','중단') 의
coach_id 를 응답 행에 포함. UI 에서 [기존]/[신규] 배지 결정용.

Spec: docs/superpowers/specs/2026-05-07-pt-kakao-check-prev-coach-badge-design.md"
```

---

## Task 2: CSS — `.badge-returning` / `.badge-new` 추가

**Files:**
- Modify: `public_html/assets/css/style.css` (끝에 append)

- [ ] **Step 1: CSS 블록 append**

`public_html/assets/css/style.css` 파일 끝에 다음 추가:

```css

/* ─────────── kakao-check 기존/신규 배지 ─────────── */
.badge-returning,
.badge-new {
  display: inline-block;
  padding: 1px 6px;
  margin-right: 4px;
  border-radius: 3px;
  font-size: 11px;
  font-weight: 600;
  vertical-align: middle;
}
.badge-returning {
  background: #eee;
  color: #666;
}
.badge-new {
  background: #f57c00;
  color: #fff;
}
```

- [ ] **Step 2: 커밋**

```bash
cd /root/pt-dev
git add public_html/assets/css/style.css
git commit -m "feat(pt): kakao-check 기존/신규 배지 CSS

.badge-returning (회색) + .badge-new (주황). 어드민/코치 양쪽 공유.

Spec: docs/superpowers/specs/2026-05-07-pt-kakao-check-prev-coach-badge-design.md"
```

---

## Task 3: 어드민 UI — `_row()` 이름 셀 prefix

**Files:**
- Modify: `public_html/admin/js/pages/kakao-check.js` (`_row()` 메서드)

- [ ] **Step 1: `_row()` 의 이름 셀 변경**

`public_html/admin/js/pages/kakao-check.js` 의 `_row(o)` 안에서 변수 선언 블록 (kakaoOn/couponOn/specialOn 정의 다음 줄)에 다음 추가:

```javascript
const prevCoachId = o.prev_coach_id != null ? parseInt(o.prev_coach_id, 10) : null;
const curCoachId = o.coach_id != null ? parseInt(o.coach_id, 10) : null;
const isReturning = prevCoachId !== null && curCoachId !== null && prevCoachId === curCoachId;
const badge = isReturning
  ? '<span class="badge-returning">기존</span>'
  : '<span class="badge-new">신규</span>';
```

같은 메서드 안에서 이름 `<td>` 를 다음으로 교체:

기존:
```javascript
<td>${UI.esc(o.name)}</td>
```

변경:
```javascript
<td>${badge} ${UI.esc(o.name)}</td>
```

- [ ] **Step 2: 브라우저 sanity check (수동, 가벼움)**

Run (DEV 사이트): 브라우저로 `https://dev-pt.soritune.com/admin/#kakao-check` 접속. 한 cohort 선택해 화면 확인:
1. 모든 행에 `[기존]` 또는 `[신규]` 배지가 이름 앞에 표시
2. 신규 배지는 주황, 기존 배지는 회색
3. 콘솔 에러 없음

(자세한 비교 검증은 Task 5 통합에서.)

- [ ] **Step 3: 커밋**

```bash
cd /root/pt-dev
git add public_html/admin/js/pages/kakao-check.js
git commit -m "feat(pt-admin): kakao-check 이름 앞 [기존]/[신규] 배지

prev_coach_id === coach_id 면 [기존], 그 외(NULL/다름) [신규].

Spec: docs/superpowers/specs/2026-05-07-pt-kakao-check-prev-coach-badge-design.md"
```

---

## Task 4: 코치 UI — `_row()` 이름 셀 prefix

**Files:**
- Modify: `public_html/coach/js/pages/kakao-check.js` (`_row()` 메서드)

- [ ] **Step 1: `_row()` 의 이름 셀 변경 (어드민과 동일 패턴)**

`public_html/coach/js/pages/kakao-check.js` 의 `_row(o)` 안에서 변수 선언 블록 (kakaoOn/couponOn/specialOn/dim/note/noteShort/noteHtml 다음)에 다음 추가:

```javascript
const prevCoachId = o.prev_coach_id != null ? parseInt(o.prev_coach_id, 10) : null;
const curCoachId = o.coach_id != null ? parseInt(o.coach_id, 10) : null;
const isReturning = prevCoachId !== null && curCoachId !== null && prevCoachId === curCoachId;
const badge = isReturning
  ? '<span class="badge-returning">기존</span>'
  : '<span class="badge-new">신규</span>';
```

이름 `<td>` 교체:

기존:
```javascript
<td>${UI.esc(o.name)}</td>
```

변경:
```javascript
<td>${badge} ${UI.esc(o.name)}</td>
```

- [ ] **Step 2: 브라우저 sanity check (수동)**

코치 계정으로 `https://dev-pt.soritune.com/coach/#kakao-check` 접속:
1. 본인 학생 행마다 `[기존]` 또는 `[신규]` prefix
2. 코치 본인이 직전 cohort에도 가르쳤던 학생은 `[기존]`, 그 외 `[신규]`

- [ ] **Step 3: 커밋**

```bash
cd /root/pt-dev
git add public_html/coach/js/pages/kakao-check.js
git commit -m "feat(pt-coach): kakao-check 이름 앞 [기존]/[신규] 배지

코치도 어드민과 동일 prefix 룰. 본인이 직전 cohort 에 같은 product 로
가르쳤던 학생은 [기존], 그 외 [신규].

Spec: docs/superpowers/specs/2026-05-07-pt-kakao-check-prev-coach-badge-design.md"
```

---

## Task 5: 통합 회귀 테스트 + dev push 게이트

**Files:** (변경 없음, 검증만)

- [ ] **Step 1: 전체 테스트 재실행**

Run: `php /root/pt-dev/tests/run_tests.php 2>&1 | tail -5`
Expected: 모든 섹션 PASS. 회귀 없음.

- [ ] **Step 2: PHP lint 전체**

```bash
cd /root/pt-dev
php -l public_html/api/kakao_check.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: 어드민 운영 페이지 spot-check (수동)**

`https://dev-pt.soritune.com/admin/#kakao-check` 에서 활성 cohort 의 데이터 분포 확인:
- 신규 배지(주황) 18~21% 정도, 기존 배지(회색) 79~82% 정도면 정상 (spec §1 데이터 분포 참조)
- 한 행을 골라 DB 와 대조:
  ```bash
  mysql --defaults-file=/root/pt-dev/.db_credentials_mycnf 2>/dev/null \
    || (set -a && source /root/pt-dev/.db_credentials && set +a && mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
      SELECT o.id, o.member_id, o.coach_id, o.product_name,
        (SELECT p.coach_id FROM orders p
          WHERE p.member_id=o.member_id AND p.id<>o.id
            AND p.product_name=o.product_name
            AND p.status NOT IN ('환불','중단')
            AND COALESCE(p.cohort_month, DATE_FORMAT(p.start_date,'%Y-%m'))
                 < COALESCE(o.cohort_month, DATE_FORMAT(o.start_date,'%Y-%m'))
          ORDER BY COALESCE(p.cohort_month, DATE_FORMAT(p.start_date,'%Y-%m')) DESC, p.id DESC
          LIMIT 1) AS prev_coach_id
      FROM orders o
      WHERE o.id = <화면에서 본 한 학생의 order_id>
      ")
  ```
  화면 표시(`[기존]` ↔ prev_coach_id == coach_id, `[신규]` ↔ NULL or ≠) 와 일치 확인.

- [ ] **Step 4: 코치 운영 페이지 spot-check (수동)**

코치 한 명으로 로그인 → `/coach/#kakao-check`. 본인 학생 중 직전 cohort에도 본인이 가르쳤던 학생이 `[기존]`인지 확인.

- [ ] **Step 5: dev push**

```bash
cd /root/pt-dev
git status         # working tree clean 확인
git log --oneline origin/dev..HEAD   # push 할 커밋 목록
git push origin dev
```

Expected: 4 commits (Task 1~4) + 본 작업 spec commit 포함하면 5 commits push.

- [ ] **Step 6: ⛔ 사용자에게 dev 확인 요청**

dev push 완료 메시지를 사용자에게 전달. 다음 메시지 템플릿:

> dev 배포 완료 (커밋 N개 push). 다음을 확인해주세요:
> - https://dev-pt.soritune.com/admin/#kakao-check : 이름 앞 [기존]/[신규] 배지 표시
> - https://dev-pt.soritune.com/coach/#kakao-check : 코치 페이지도 동일하게
> - 직전 cohort에 같은 product/같은 코치 학생 → [기존](회색)
> - 그 외(이전 없음, 다른 코치, 다른 product) → [신규](주황)
>
> 운영 반영(main 머지 + pt-prod pull)은 사용자 명시적 요청 시에만 진행합니다.

---

## Task 6 (조건부): 운영 반영

**조건:** 사용자가 "운영 반영해줘" 등 명시적 요청을 한 경우에만 실행. 본 작업은 마이그가 없으므로 코드 push 만으로 끝남.

- [ ] **Step 1: pt-dev 에서 main 머지 + push**

```bash
cd /root/pt-dev
git checkout main
git pull origin main
git merge dev --no-ff --no-edit
git push origin main
git checkout dev
```

Expected: fast-forward 가능했으나 `--no-ff` 머지 커밋으로 dev/main 분리 명확화.

- [ ] **Step 2: pt-prod git pull**

```bash
cd /root/pt-prod
git pull origin main
```

Expected: fast-forward. PHP 직접 서빙이라 빌드/재시작 없음.

- [ ] **Step 3: PROD smoke**

`https://pt.soritune.com/admin/#kakao-check` 접속:
- 표 이름 앞 `[기존]` / `[신규]` 배지 표시
- 한 행 골라 DEV 와 동일 패턴인지 spot-check

`https://pt.soritune.com/coach/#kakao-check` 도 동일.

- [ ] **Step 4: 사용자에게 보고**

PROD 배포 완료 메시지 전달.

---

## Self-Review

**1. Spec coverage:**

| Spec 섹션 | 구현 task |
|-----------|-----------|
| §1 룰 정의 (마지막 정상 order, same product, NOT IN 환불/중단) | Task 1 SELECT |
| §1 다른 코치 → 신규 (2-state) | Task 3/4 JS 비교 (NULL/≠ 모두 신규) |
| §2 표시 위치 (이름 앞 inline 배지) | Task 3/4 |
| §2 배지 마크업 (`.badge-returning` / `.badge-new`) | Task 2 (CSS) + Task 3/4 (마크업) |
| §3.1 서버 SELECT correlated subquery | Task 1 Step 7 |
| §3.2 프론트 prev_coach_id === coach_id | Task 3 Step 1 + Task 4 Step 1 |
| §3.3 CSS append | Task 2 |
| §3.4 테스트 5케이스 | Task 1 Step 1~5 |
| §5 성능 | 변경 없음 (인덱스 기존 활용) |
| §6 권한 | 변경 없음 (기존 coach scope WHERE 유지) |
| §7 UX 케이스 (매칭대기 coach_id NULL → [신규]) | Task 3/4 의 `curCoachId !== null` 조건 |
| §8 비범위 (배지 클릭 / 툴팁 / 알림톡 변경) | 미구현 — 의도대로 |

모든 spec 요구사항이 task 에 매핑됨.

**2. Placeholder scan:**
- TBD/TODO/"적절한" 없음
- 모든 코드 step 에 실제 코드 포함
- 모든 명령어에 정확한 경로 + expected output

**3. Type consistency:**
- `prev_coach_id` (서버 응답 컬럼) ↔ `o.prev_coach_id` (JS) 일관
- `parseInt(o.prev_coach_id, 10)` / `parseInt(o.coach_id, 10)` 비교 — JS 응답이 숫자 또는 문자열로 올 수 있어 안전하게 동등 변환
- `<span class="badge-returning">` / `<span class="badge-new">` ↔ CSS 셀렉터 일치
- `kakaoCheckList()` 옵션 키 `include_processed` — Task 6에서 코치 sibling 사용 패턴과 일치 (이미 메인 코드에 반영된 키)

**4. Ambiguity:**
- "매칭대기 + coach_id NULL" → JS 의 `curCoachId !== null` 으로 항상 `[신규]` 결정 (spec §7 일치)
- "이전 order 가 모두 환불" → SQL WHERE `status NOT IN` 으로 자연 NULL → `[신규]`
- "Cancel / no-op 동작" — 본 task 는 표시만이라 토글 흐름 변경 없음

**5. 실행 순서 위험:**
- Task 1 (SELECT 추가) 후 응답에 신규 컬럼 포함 → 기존 클라이언트(어드민/코치 미배포) 가 무시 → 안전
- Task 2~4 의 순서는 무관하나 위 순서 권장 (CSS 가 마크업보다 먼저 들어가면 첫 commit 부터 시각 정상)
- 마이그 없음 → PROD 배포는 단순 코드 pull
