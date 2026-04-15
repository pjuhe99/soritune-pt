# PT Management System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the admin/coach management system for pt.soritune.com — member charts, coach DB, PT order tracking, member merge, and spreadsheet import.

**Architecture:** PHP API backend (one file per resource in `api/`) + vanilla JS SPA frontend (separate admin and coach apps). No build tools. Shared includes for DB, auth, helpers. All derived values (member status, used_sessions, current coach) are computed at query time, never stored.

**Tech Stack:** PHP 8+ / MariaDB / vanilla JS / Pretendard font / Spotify-inspired dark theme (CLAUDE.md)

**Spec:** `docs/superpowers/specs/2026-04-15-pt-management-system-design.md`

**Design System:** `CLAUDE.md` (Spotify dark theme, Soritune Orange `#FF5E00`, Pretendard, pill buttons)

**DB Credentials:** `.db_credentials` in project root (DB_HOST, DB_NAME, DB_USER, DB_PASS)

---

## File Map

### Shared Includes
| File | Responsibility |
|------|---------------|
| `public_html/includes/db.php` | PDO connection from `.db_credentials`, `getDB()` function |
| `public_html/includes/auth.php` | Session-based auth: `requireAdmin()`, `requireCoach()`, `getCurrentUser()` |
| `public_html/includes/helpers.php` | JSON response helpers, phone normalization, member status calculation SQL builder |

### API Layer (one file per resource)
| File | Responsibility |
|------|---------------|
| `public_html/api/auth.php` | Login/logout for admin and coach |
| `public_html/api/coaches.php` | Coach CRUD (admin only) |
| `public_html/api/members.php` | Member CRUD + chart data (admin: full, coach: restricted) |
| `public_html/api/orders.php` | Order CRUD + session completion (admin: full, coach: status update only) |
| `public_html/api/notes.php` | Member notes CRUD |
| `public_html/api/tests.php` | Test results CRUD (admin only for now) |
| `public_html/api/merge.php` | Merge detection, execute, undo (admin only) |
| `public_html/api/import.php` | Spreadsheet upload and processing (admin only) |
| `public_html/api/logs.php` | Change log read (admin + coach) |

### Admin Frontend
| File | Responsibility |
|------|---------------|
| `public_html/admin/index.php` | Admin SPA shell (sidebar + content area) |
| `public_html/admin/js/app.js` | Router, API client, shared UI components |
| `public_html/admin/js/pages/coaches.js` | Coach list + CRUD modal |
| `public_html/admin/js/pages/members.js` | Member list with search/filter |
| `public_html/admin/js/pages/member-chart.js` | Member chart detail (tabs, PT progress, etc.) |
| `public_html/admin/js/pages/merge.js` | Duplicate detection + merge UI |
| `public_html/admin/js/pages/import.js` | Spreadsheet upload + results |

### Coach Frontend
| File | Responsibility |
|------|---------------|
| `public_html/coach/index.php` | Coach SPA shell |
| `public_html/coach/js/app.js` | Router, API client, shared UI |
| `public_html/coach/js/pages/my-members.js` | My members list |
| `public_html/coach/js/pages/member-chart.js` | Member chart (restricted) |

### Assets
| File | Responsibility |
|------|---------------|
| `public_html/assets/css/style.css` | Global styles following CLAUDE.md design system |

### DB
| File | Responsibility |
|------|---------------|
| `schema.sql` | All CREATE TABLE statements (project root, not public_html) |

---

## Task 1: Project Scaffolding + DB Schema

Create directory structure, DB tables, and shared includes.

**Files:**
- Create: `schema.sql`
- Create: `public_html/includes/db.php`
- Create: `public_html/includes/helpers.php`
- Create: `public_html/assets/css/style.css`
- Create: `public_html/admin/index.php` (placeholder)
- Create: `public_html/coach/index.php` (placeholder)

- [ ] **Step 1: Create directory structure**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
mkdir -p public_html/{admin/js/pages,coach/js/pages,api,includes,assets/css,uploads/imports}
```

- [ ] **Step 2: Create schema.sql**

Create `schema.sql` in project root with all 12 tables. The file reads `.db_credentials` at the top for the DB name.

```sql
-- PT Management System Schema
-- Run: mysql < schema.sql (from project root)

CREATE DATABASE IF NOT EXISTS SORITUNECOM_PT
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE SORITUNECOM_PT;

CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  login_id VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(50) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS coaches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  login_id VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  coach_name VARCHAR(100) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  available TINYINT(1) NOT NULL DEFAULT 1,
  max_capacity INT NOT NULL DEFAULT 0,
  memo TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  phone VARCHAR(20),
  email VARCHAR(255),
  memo TEXT,
  merged_into INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (merged_into) REFERENCES members(id) ON DELETE SET NULL,
  INDEX idx_phone (phone),
  INDEX idx_email (email),
  INDEX idx_merged_into (merged_into)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS member_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  source VARCHAR(50) NOT NULL,
  source_id VARCHAR(100),
  name VARCHAR(100),
  phone VARCHAR(20),
  email VARCHAR(255),
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  INDEX idx_member_id (member_id),
  INDEX idx_source (source, source_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  coach_id INT DEFAULT NULL,
  product_name VARCHAR(200) NOT NULL,
  product_type ENUM('period','count') NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  total_sessions INT DEFAULT NULL,
  amount INT NOT NULL DEFAULT 0,
  status ENUM('매칭대기','매칭완료','진행중','연기','중단','환불','종료') NOT NULL DEFAULT '매칭대기',
  memo TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE SET NULL,
  INDEX idx_member_id (member_id),
  INDEX idx_coach_id (coach_id),
  INDEX idx_status (status),
  INDEX idx_natural_key (member_id, product_name, start_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  session_number INT NOT NULL,
  completed_at DATETIME DEFAULT NULL,
  memo VARCHAR(255),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  UNIQUE KEY uq_order_session (order_id, session_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS coach_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  coach_id INT NOT NULL,
  order_id INT DEFAULT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_at DATETIME DEFAULT NULL,
  reason VARCHAR(255),
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  INDEX idx_member_id (member_id),
  INDEX idx_coach_id (coach_id),
  INDEX idx_active (coach_id, released_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS test_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  test_type ENUM('disc','sensory') NOT NULL,
  result_data JSON,
  tested_at DATE NOT NULL,
  memo TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  INDEX idx_member_id (member_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS member_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  author_type ENUM('admin','coach') NOT NULL,
  author_id INT NOT NULL,
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  INDEX idx_member_id (member_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS merge_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  primary_member_id INT NOT NULL,
  merged_member_id INT NOT NULL,
  absorbed_member_data JSON NOT NULL,
  moved_records JSON NOT NULL,
  admin_id INT NOT NULL,
  merged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unmerged_at DATETIME DEFAULT NULL,
  FOREIGN KEY (admin_id) REFERENCES admins(id),
  INDEX idx_primary (primary_member_id),
  INDEX idx_merged (merged_member_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS change_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  target_type ENUM('member','order','coach_assignment','merge') NOT NULL,
  target_id INT NOT NULL,
  action VARCHAR(50) NOT NULL,
  old_value JSON,
  new_value JSON,
  actor_type ENUM('admin','coach','system') NOT NULL,
  actor_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_target (target_type, target_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS migration_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  batch_id VARCHAR(50) NOT NULL,
  source_type VARCHAR(50) NOT NULL,
  source_row INT,
  target_table VARCHAR(50),
  target_id INT,
  status ENUM('success','skipped','error') NOT NULL,
  message TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_batch (batch_id),
  INDEX idx_status (batch_id, status)
) ENGINE=InnoDB;
```

- [ ] **Step 3: Run schema.sql**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
mysql -u SORITUNECOM_PT -p$(grep DB_PASS .db_credentials | cut -d= -f2) < schema.sql
```

Verify:
```bash
mysql -u SORITUNECOM_PT -p$(grep DB_PASS .db_credentials | cut -d= -f2) SORITUNECOM_PT -e "SHOW TABLES;"
```

Expected: 12 tables listed.

- [ ] **Step 4: Seed initial admin account**

```bash
ADMIN_HASH=$(php -r "echo password_hash('soritune!', PASSWORD_BCRYPT);")
mysql -u SORITUNECOM_PT -p$(grep DB_PASS .db_credentials | cut -d= -f2) SORITUNECOM_PT -e \
  "INSERT INTO admins (login_id, password_hash, name) VALUES ('admin', '$ADMIN_HASH', '관리자');"
```

- [ ] **Step 5: Create db.php**

Create `public_html/includes/db.php`:

```php
<?php
/**
 * DB connection — reads credentials from .db_credentials
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $credFile = __DIR__ . '/../../.db_credentials';
    $creds = [];
    foreach (file($credFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $creds[trim($key)] = trim($val);
        }
    }

    $dsn = "mysql:host={$creds['DB_HOST']};dbname={$creds['DB_NAME']};charset=utf8mb4";
    $pdo = new PDO($dsn, $creds['DB_USER'], $creds['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
```

- [ ] **Step 6: Create helpers.php**

Create `public_html/includes/helpers.php`:

```php
<?php
/**
 * Shared helper functions
 */

function jsonSuccess(array $data = [], string $message = 'OK'): never {
    echo json_encode(['ok' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $httpCode = 400): never {
    http_response_code($httpCode);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
}

/**
 * Normalize Korean phone number: remove hyphens/spaces, ensure 010 prefix.
 */
function normalizePhone(?string $phone): ?string {
    if ($phone === null || $phone === '') return null;
    $phone = preg_replace('/[\s\-\.]/', '', $phone);
    if (preg_match('/^10\d{8}$/', $phone)) $phone = '0' . $phone;
    if (preg_match('/^82(\d{10,11})$/', $phone, $m)) $phone = '0' . $m[1];
    return $phone;
}

/**
 * Build the member display_status subquery.
 * Returns a SQL expression that computes display_status from orders.
 * $memberIdExpr is the SQL expression for member id (e.g. 'm.id').
 */
function memberStatusSQL(string $memberIdExpr = 'm.id'): string {
    return "COALESCE(
        (SELECT CASE o.status
           WHEN '진행중' THEN '진행중'
           WHEN '매칭완료' THEN '진행예정'
           ELSE o.status
         END
         FROM orders o
         WHERE o.member_id = {$memberIdExpr}
         ORDER BY FIELD(o.status, '진행중','매칭완료','매칭대기','연기','중단','환불','종료')
         LIMIT 1),
        '매칭대기'
    )";
}

/**
 * Log a change to change_logs.
 */
function logChange(PDO $db, string $targetType, int $targetId, string $action,
                   mixed $oldValue, mixed $newValue, string $actorType, int $actorId): void {
    $stmt = $db->prepare("INSERT INTO change_logs
        (target_type, target_id, action, old_value, new_value, actor_type, actor_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $targetType, $targetId, $action,
        json_encode($oldValue, JSON_UNESCAPED_UNICODE),
        json_encode($newValue, JSON_UNESCAPED_UNICODE),
        $actorType, $actorId
    ]);
}

/**
 * Recalculate and return member display status for a single member.
 */
function getMemberDisplayStatus(PDO $db, int $memberId): string {
    $sql = "SELECT " . memberStatusSQL('?') . " AS display_status";
    $stmt = $db->prepare(str_replace('m.id', '?', "SELECT " . memberStatusSQL() . " AS ds"));
    // Simpler approach:
    $stmt = $db->prepare("
        SELECT COALESCE(
            (SELECT CASE o.status
               WHEN '진행중' THEN '진행중'
               WHEN '매칭완료' THEN '진행예정'
               ELSE o.status
             END
             FROM orders o
             WHERE o.member_id = ?
             ORDER BY FIELD(o.status, '진행중','매칭완료','매칭대기','연기','중단','환불','종료')
             LIMIT 1),
            '매칭대기'
        ) AS display_status
    ");
    $stmt->execute([$memberId]);
    return $stmt->fetchColumn();
}
```

- [ ] **Step 7: Create minimal style.css**

Create `public_html/assets/css/style.css` with the base design system from CLAUDE.md:

```css
/* PT Management System — Spotify-inspired dark theme */
/* Follows CLAUDE.md design system */

@import url('https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.min.css');

:root {
  --bg: #121212;
  --surface: #181818;
  --surface-hover: #1f1f1f;
  --surface-card: #252525;
  --accent: #FF5E00;
  --accent-border: #E65500;
  --text: #ffffff;
  --text-secondary: #b3b3b3;
  --text-near-white: #cbcbcb;
  --border: #4d4d4d;
  --border-light: #7c7c7c;
  --negative: #f3727f;
  --warning: #ffa42b;
  --info: #539df5;
  --success: #22c55e;
  --shadow-heavy: rgba(0,0,0,0.5) 0px 8px 24px;
  --shadow-medium: rgba(0,0,0,0.3) 0px 8px 8px;
  --radius-subtle: 4px;
  --radius-standard: 6px;
  --radius-comfortable: 8px;
  --radius-pill: 500px;
  --radius-full-pill: 9999px;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
  font-family: 'Pretendard Variable', Pretendard, -apple-system, BlinkMacSystemFont,
    system-ui, Roboto, 'Helvetica Neue', 'Segoe UI', 'Apple SD Gothic Neo',
    'Noto Sans KR', 'Malgun Gothic', sans-serif;
  background: var(--bg);
  color: var(--text);
  font-size: 14px;
  font-weight: 400;
  line-height: normal;
  min-height: 100vh;
}

/* Layout */
.app-layout {
  display: flex;
  min-height: 100vh;
}

.sidebar {
  width: 220px;
  background: var(--bg);
  border-right: 1px solid rgba(255,255,255,0.06);
  padding: 24px 0;
  flex-shrink: 0;
  position: fixed;
  top: 0;
  left: 0;
  bottom: 0;
  overflow-y: auto;
}

.sidebar-logo {
  padding: 0 20px 24px;
  font-size: 18px;
  font-weight: 700;
  color: var(--accent);
  letter-spacing: 1px;
}

.sidebar-nav a {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 20px;
  color: var(--text-secondary);
  text-decoration: none;
  font-size: 14px;
  font-weight: 400;
  transition: all 0.2s;
}

.sidebar-nav a:hover {
  color: var(--text);
  background: rgba(255,255,255,0.04);
}

.sidebar-nav a.active {
  color: var(--text);
  font-weight: 700;
}

.main-content {
  margin-left: 220px;
  flex: 1;
  padding: 32px;
  min-height: 100vh;
}

.topbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 32px;
}

.topbar-user {
  display: flex;
  align-items: center;
  gap: 12px;
  color: var(--text-secondary);
  font-size: 13px;
}

/* Page header */
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
}

.page-title {
  font-size: 24px;
  font-weight: 700;
}

/* Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 8px 20px;
  border: none;
  border-radius: var(--radius-full-pill);
  font-family: inherit;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s;
  text-transform: uppercase;
  letter-spacing: 1.4px;
}

.btn-primary {
  background: var(--accent);
  color: #000;
}
.btn-primary:hover { filter: brightness(1.1); }

.btn-secondary {
  background: var(--surface-hover);
  color: var(--text);
}
.btn-secondary:hover { background: #2a2a2a; }

.btn-outline {
  background: transparent;
  color: var(--text);
  border: 1px solid var(--border-light);
}
.btn-outline:hover { border-color: var(--text); }

.btn-danger {
  background: var(--negative);
  color: #000;
}

.btn-small {
  padding: 4px 14px;
  font-size: 12px;
}

/* Cards */
.card {
  background: var(--surface);
  border-radius: var(--radius-comfortable);
  padding: 20px;
}

.card-elevated {
  box-shadow: var(--shadow-medium);
}

/* Tables */
.data-table {
  width: 100%;
  border-collapse: collapse;
}

.data-table th {
  text-align: left;
  padding: 10px 14px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  color: var(--text-secondary);
  border-bottom: 1px solid rgba(255,255,255,0.06);
}

.data-table td {
  padding: 12px 14px;
  font-size: 14px;
  border-bottom: 1px solid rgba(255,255,255,0.04);
}

.data-table tr:hover td {
  background: rgba(255,255,255,0.02);
}

/* Status badges */
.badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: var(--radius-full-pill);
  font-size: 10.5px;
  font-weight: 600;
  text-transform: capitalize;
}

.badge-진행중 { background: rgba(34,197,94,0.15); color: var(--success); }
.badge-진행예정 { background: rgba(83,157,245,0.15); color: var(--info); }
.badge-매칭대기 { background: rgba(255,94,0,0.15); color: var(--accent); }
.badge-매칭완료 { background: rgba(83,157,245,0.15); color: var(--info); }
.badge-연기 { background: rgba(255,164,43,0.15); color: var(--warning); }
.badge-중단, .badge-환불 { background: rgba(243,114,127,0.15); color: var(--negative); }
.badge-종료 { background: rgba(255,255,255,0.06); color: var(--text-secondary); }
.badge-active { background: rgba(34,197,94,0.15); color: var(--success); }
.badge-inactive { background: rgba(255,255,255,0.06); color: var(--text-secondary); }

/* Forms */
.form-group {
  margin-bottom: 16px;
}

.form-label {
  display: block;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.5px;
  color: var(--text-secondary);
  margin-bottom: 6px;
}

.form-input, .form-select, .form-textarea {
  width: 100%;
  padding: 10px 14px;
  background: var(--surface-hover);
  border: 1px solid var(--border);
  border-radius: var(--radius-subtle);
  color: var(--text);
  font-family: inherit;
  font-size: 14px;
  outline: none;
  transition: border-color 0.2s;
}

.form-input:focus, .form-select:focus, .form-textarea:focus {
  border-color: var(--accent);
}

.form-textarea { resize: vertical; min-height: 80px; }

.form-select {
  appearance: none;
  cursor: pointer;
}

/* Search */
.search-input {
  padding: 10px 16px;
  background: var(--surface-hover);
  border: none;
  border-radius: var(--radius-pill);
  color: var(--text);
  font-family: inherit;
  font-size: 14px;
  outline: none;
  width: 300px;
  box-shadow: rgb(18,18,18) 0px 1px 0px, rgb(124,124,124) 0px 0px 0px 1px inset;
}

.search-input:focus { box-shadow: 0 0 0 1px var(--accent); }

/* Filters */
.filters {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.filter-pill {
  padding: 6px 16px;
  background: var(--surface-hover);
  border: none;
  border-radius: var(--radius-full-pill);
  color: var(--text-secondary);
  font-family: inherit;
  font-size: 13px;
  cursor: pointer;
  outline: none;
  appearance: none;
}
.filter-pill:focus { box-shadow: 0 0 0 1px var(--accent); }

/* Tabs */
.tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 20px;
  border-bottom: 1px solid rgba(255,255,255,0.06);
}

.tab-btn {
  padding: 10px 18px;
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  color: var(--text-secondary);
  font-family: inherit;
  font-size: 14px;
  font-weight: 400;
  cursor: pointer;
  transition: all 0.2s;
}

.tab-btn:hover { color: var(--text); }
.tab-btn.active {
  color: var(--text);
  font-weight: 700;
  border-bottom-color: var(--accent);
}

.tab-content { display: none; }
.tab-content.active { display: block; }

/* Modal */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal {
  background: var(--surface);
  border-radius: var(--radius-comfortable);
  padding: 28px;
  width: min(500px, 90vw);
  max-height: 80vh;
  overflow-y: auto;
  box-shadow: var(--shadow-heavy);
}

.modal-title {
  font-size: 18px;
  font-weight: 700;
  margin-bottom: 20px;
}

.modal-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: 24px;
}

/* Progress bar */
.progress-bar {
  height: 6px;
  background: rgba(255,255,255,0.06);
  border-radius: 3px;
  overflow: hidden;
  margin: 8px 0;
}

.progress-fill {
  height: 100%;
  background: var(--accent);
  border-radius: 3px;
  transition: width 0.3s;
}

/* Session checklist */
.session-list { list-style: none; }
.session-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
  border-bottom: 1px solid rgba(255,255,255,0.04);
  font-size: 13px;
}
.session-check {
  width: 20px;
  height: 20px;
  border-radius: 4px;
  border: 1px solid var(--border);
  background: transparent;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--success);
  font-size: 14px;
  flex-shrink: 0;
}
.session-check.done {
  background: rgba(34,197,94,0.15);
  border-color: var(--success);
}
.session-date { color: var(--text-secondary); font-size: 12px; }

/* Info section (member chart header) */
.info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 16px;
}
.info-item-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  color: var(--text-secondary);
  margin-bottom: 4px;
}
.info-item-value {
  font-size: 15px;
  font-weight: 500;
}

/* PT progress card */
.pt-progress-card {
  background: var(--surface-card);
  border-radius: var(--radius-comfortable);
  padding: 16px;
  margin-bottom: 12px;
}
.pt-progress-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}
.pt-progress-title { font-weight: 700; font-size: 14px; }
.pt-progress-coach { color: var(--text-secondary); font-size: 13px; }
.pt-progress-meta { color: var(--text-secondary); font-size: 12px; margin-bottom: 4px; }

/* Empty state */
.empty-state {
  text-align: center;
  padding: 48px 20px;
  color: var(--text-secondary);
  font-size: 14px;
}

/* Loading */
.loading {
  text-align: center;
  padding: 32px;
  color: var(--text-secondary);
}

/* Login page */
.login-wrapper {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: var(--bg);
}

.login-card {
  background: var(--surface);
  border-radius: var(--radius-comfortable);
  padding: 40px;
  width: min(400px, 90vw);
  box-shadow: var(--shadow-heavy);
  text-align: center;
}

.login-logo {
  font-size: 24px;
  font-weight: 700;
  color: var(--accent);
  margin-bottom: 8px;
}

.login-subtitle {
  color: var(--text-secondary);
  font-size: 13px;
  margin-bottom: 28px;
}

.login-error {
  color: var(--negative);
  font-size: 13px;
  margin-bottom: 12px;
  display: none;
}

/* Responsive */
@media (max-width: 768px) {
  .sidebar { display: none; }
  .main-content { margin-left: 0; padding: 16px; }
  .search-input { width: 100%; }
  .info-grid { grid-template-columns: 1fr 1fr; }
}
```

- [ ] **Step 8: Create .gitignore**

Create `.gitignore` in project root:

```
.db_credentials
uploads/imports/*
!uploads/imports/.gitkeep
```

Create empty keep file:
```bash
touch /var/www/html/_______site_SORITUNECOM_PT/public_html/uploads/imports/.gitkeep
```

- [ ] **Step 9: Set SELinux context on uploads directory**

```bash
chcon -R -t httpd_sys_rw_content_t /var/www/html/_______site_SORITUNECOM_PT/public_html/uploads/
```

- [ ] **Step 10: Verify DB tables**

```bash
mysql -u SORITUNECOM_PT -p$(grep DB_PASS /var/www/html/_______site_SORITUNECOM_PT/.db_credentials | cut -d= -f2) SORITUNECOM_PT -e "SHOW TABLES;"
```

Expected: admins, change_logs, coach_assignments, coaches, member_accounts, member_notes, members, merge_logs, migration_logs, order_sessions, orders, test_results

- [ ] **Step 11: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add schema.sql .gitignore public_html/includes/ public_html/assets/ public_html/uploads/imports/.gitkeep
git commit -m "feat: project scaffolding — DB schema, shared includes, design system CSS"
```

---

## Task 2: Authentication System

Build login/logout for admin and coach with session-based auth.

**Files:**
- Create: `public_html/includes/auth.php`
- Create: `public_html/api/auth.php`

- [ ] **Step 1: Create auth.php middleware**

Create `public_html/includes/auth.php`:

```php
<?php
/**
 * Authentication middleware
 */

function startAuthSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('PT_SESSION');
        session_set_cookie_params([
            'lifetime' => 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function getCurrentUser(): ?array {
    startAuthSession();
    if (empty($_SESSION['pt_user'])) return null;
    return $_SESSION['pt_user'];
}

function requireAdmin(): array {
    $user = getCurrentUser();
    if (!$user || $user['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => '관리자 로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $user;
}

function requireCoach(): array {
    $user = getCurrentUser();
    if (!$user || $user['role'] !== 'coach') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => '코치 로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $user;
}

function requireAnyAuth(): array {
    $user = getCurrentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => '로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $user;
}
```

- [ ] **Step 2: Create auth API**

Create `public_html/api/auth.php`:

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        $input = getJsonInput();
        $loginId = trim($input['login_id'] ?? '');
        $password = $input['password'] ?? '';
        $role = $input['role'] ?? 'admin'; // 'admin' or 'coach'

        if (!$loginId || !$password) jsonError('ID와 비밀번호를 입력하세요');
        if (!in_array($role, ['admin', 'coach'])) jsonError('올바르지 않은 역할입니다');

        $db = getDB();
        $table = $role === 'admin' ? 'admins' : 'coaches';
        $nameCol = $role === 'admin' ? 'name' : 'coach_name';

        $stmt = $db->prepare("SELECT id, login_id, password_hash, {$nameCol} as display_name, status FROM {$table} WHERE login_id = ?");
        $stmt->execute([$loginId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            jsonError('ID 또는 비밀번호가 올바르지 않습니다', 401);
        }
        if ($user['status'] !== 'active') {
            jsonError('비활성 계정입니다', 403);
        }

        startAuthSession();
        $_SESSION['pt_user'] = [
            'id' => (int)$user['id'],
            'login_id' => $user['login_id'],
            'name' => $user['display_name'],
            'role' => $role,
        ];

        jsonSuccess(['user' => $_SESSION['pt_user']], '로그인 성공');

    case 'logout':
        startAuthSession();
        session_destroy();
        jsonSuccess([], '로그아웃 완료');

    case 'me':
        $user = getCurrentUser();
        if (!$user) jsonError('로그인이 필요합니다', 401);
        jsonSuccess(['user' => $user]);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 3: Verify auth works**

```bash
# Login as admin
curl -s -c /tmp/pt_cookie -X POST https://pt.soritune.com/api/auth.php?action=login \
  -H 'Content-Type: application/json' \
  -d '{"login_id":"admin","password":"soritune!","role":"admin"}'
```

Expected: `{"ok":true,"message":"로그인 성공","data":{"user":{"id":1,...}}}`

```bash
# Check session
curl -s -b /tmp/pt_cookie https://pt.soritune.com/api/auth.php?action=me
```

Expected: `{"ok":true,...,"data":{"user":{"id":1,"login_id":"admin","name":"관리자","role":"admin"}}}`

- [ ] **Step 4: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/includes/auth.php public_html/api/auth.php
git commit -m "feat: authentication system — admin/coach login with session"
```

---

## Task 3: Coach CRUD API + Admin Coach Page

**Files:**
- Create: `public_html/api/coaches.php`
- Create: `public_html/admin/index.php`
- Create: `public_html/admin/js/app.js`
- Create: `public_html/admin/js/pages/coaches.js`

- [ ] **Step 1: Create coaches API**

Create `public_html/api/coaches.php`:

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $stmt = $db->query("
            SELECT c.*,
              (SELECT COUNT(*) FROM coach_assignments ca
               WHERE ca.coach_id = c.id AND ca.released_at IS NULL) AS current_count
            FROM coaches c
            ORDER BY c.status ASC, c.coach_name ASC
        ");
        jsonSuccess(['coaches' => $stmt->fetchAll()]);

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $stmt = $db->prepare("SELECT * FROM coaches WHERE id = ?");
        $stmt->execute([$id]);
        $coach = $stmt->fetch();
        if (!$coach) jsonError('코치를 찾을 수 없습니다', 404);
        jsonSuccess(['coach' => $coach]);

    case 'create':
        $input = getJsonInput();
        $loginId = trim($input['login_id'] ?? '');
        $password = $input['password'] ?? '';
        $coachName = trim($input['coach_name'] ?? '');

        if (!$loginId || !$password || !$coachName) jsonError('필수 항목을 입력하세요');

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO coaches (login_id, password_hash, coach_name, status, available, max_capacity, memo)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        try {
            $stmt->execute([
                $loginId, $hash, $coachName,
                $input['status'] ?? 'active',
                $input['available'] ?? 1,
                $input['max_capacity'] ?? 0,
                $input['memo'] ?? null,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) jsonError('이미 사용 중인 로그인 ID입니다');
            throw $e;
        }
        jsonSuccess(['id' => (int)$db->lastInsertId()], '코치가 등록되었습니다');

    case 'update':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $input = getJsonInput();

        $fields = [];
        $params = [];
        foreach (['coach_name','status','available','max_capacity','memo'] as $f) {
            if (array_key_exists($f, $input)) {
                $fields[] = "{$f} = ?";
                $params[] = $input[$f];
            }
        }
        if (!empty($input['password'])) {
            $fields[] = "password_hash = ?";
            $params[] = password_hash($input['password'], PASSWORD_BCRYPT);
        }
        if (empty($fields)) jsonError('변경할 항목이 없습니다');

        $params[] = $id;
        $db->prepare("UPDATE coaches SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        jsonSuccess([], '코치 정보가 수정되었습니다');

    case 'delete':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        // Check for active assignments
        $stmt = $db->prepare("SELECT COUNT(*) FROM coach_assignments WHERE coach_id = ? AND released_at IS NULL");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            jsonError('현재 담당 회원이 있는 코치는 삭제할 수 없습니다');
        }
        $db->prepare("DELETE FROM coaches WHERE id = ?")->execute([$id]);
        jsonSuccess([], '코치가 삭제되었습니다');

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 2: Create admin SPA shell**

Create `public_html/admin/index.php`:

```php
<?php
require_once __DIR__ . '/../includes/auth.php';
$user = getCurrentUser();
$isLoggedIn = $user && $user['role'] === 'admin';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SoriTune PT — Admin</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<?php if (!$isLoggedIn): ?>
<div class="login-wrapper">
  <div class="login-card">
    <div class="login-logo">SoriTune PT</div>
    <div class="login-subtitle">관리자 로그인</div>
    <div class="login-error" id="loginError"></div>
    <form id="loginForm">
      <div class="form-group">
        <input type="text" class="form-input" id="loginId" placeholder="아이디" autocomplete="username">
      </div>
      <div class="form-group">
        <input type="password" class="form-input" id="loginPw" placeholder="비밀번호" autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px">LOGIN</button>
    </form>
  </div>
</div>
<script>
document.getElementById('loginForm').addEventListener('submit', async e => {
  e.preventDefault();
  const err = document.getElementById('loginError');
  err.style.display = 'none';
  const res = await fetch('/api/auth.php?action=login', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
      login_id: document.getElementById('loginId').value,
      password: document.getElementById('loginPw').value,
      role: 'admin'
    })
  });
  const data = await res.json();
  if (data.ok) { location.reload(); }
  else { err.textContent = data.message; err.style.display = 'block'; }
});
</script>

<?php else: ?>
<div class="app-layout">
  <aside class="sidebar">
    <div class="sidebar-logo">SoriTune PT</div>
    <nav class="sidebar-nav">
      <a href="#members" data-page="members">회원관리</a>
      <a href="#coaches" data-page="coaches">코치관리</a>
      <a href="#merge" data-page="merge">동일인관리</a>
      <a href="#import" data-page="import">데이터관리</a>
    </nav>
  </aside>
  <main class="main-content">
    <div class="topbar">
      <div></div>
      <div class="topbar-user">
        <span><?= htmlspecialchars($user['name']) ?></span>
        <button class="btn btn-small btn-outline" onclick="logout()">LOGOUT</button>
      </div>
    </div>
    <div id="pageContent"></div>
  </main>
</div>

<script src="/admin/js/app.js"></script>
<script src="/admin/js/pages/coaches.js"></script>
<script src="/admin/js/pages/members.js"></script>
<script src="/admin/js/pages/member-chart.js"></script>
<script src="/admin/js/pages/merge.js"></script>
<script src="/admin/js/pages/import.js"></script>
<script>App.init();</script>
<?php endif; ?>

</body>
</html>
```

- [ ] **Step 3: Create admin app.js (router + API client)**

Create `public_html/admin/js/app.js`:

```js
/**
 * Admin SPA — Router + API client + shared utilities
 */
const App = {
  currentPage: null,
  pages: {},

  init() {
    window.addEventListener('hashchange', () => this.route());
    this.route();
  },

  route() {
    const hash = location.hash.slice(1) || 'members';
    const [page, ...params] = hash.split('/');

    // Update sidebar active state
    document.querySelectorAll('.sidebar-nav a').forEach(a => {
      a.classList.toggle('active', a.dataset.page === page);
    });

    this.currentPage = page;
    const handler = this.pages[page];
    if (handler) {
      handler.render(params);
    } else {
      document.getElementById('pageContent').innerHTML =
        '<div class="empty-state">페이지를 찾을 수 없습니다</div>';
    }
  },

  registerPage(name, handler) {
    this.pages[name] = handler;
  },
};

/**
 * API client
 */
const API = {
  async request(url, options = {}) {
    const res = await fetch(url, {
      headers: { 'Content-Type': 'application/json', ...options.headers },
      ...options,
    });
    const data = await res.json();
    if (!data.ok && res.status === 401) {
      location.reload(); // Session expired
    }
    return data;
  },

  get(url) { return this.request(url); },

  post(url, body) {
    return this.request(url, { method: 'POST', body: JSON.stringify(body) });
  },

  put(url, body) {
    return this.request(url, { method: 'POST', body: JSON.stringify(body) });
  },

  delete(url) {
    return this.request(url, { method: 'POST', body: JSON.stringify({ _method: 'DELETE' }) });
  },

  upload(url, formData) {
    return fetch(url, { method: 'POST', body: formData }).then(r => r.json());
  },
};

/**
 * UI Helpers
 */
const UI = {
  $(id) { return document.getElementById(id); },

  statusBadge(status) {
    return `<span class="badge badge-${status}">${status}</span>`;
  },

  formatDate(dateStr) {
    if (!dateStr) return '-';
    return dateStr.split(' ')[0]; // YYYY-MM-DD
  },

  formatMoney(amount) {
    return Number(amount || 0).toLocaleString() + '원';
  },

  showModal(html) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `<div class="modal">${html}</div>`;
    overlay.addEventListener('click', e => {
      if (e.target === overlay) overlay.remove();
    });
    document.body.appendChild(overlay);
    return overlay;
  },

  closeModal() {
    document.querySelector('.modal-overlay')?.remove();
  },

  confirm(message) {
    return window.confirm(message);
  },

  toast(message) {
    // Simple toast — could be enhanced later
    alert(message);
  },
};

async function logout() {
  await API.post('/api/auth.php?action=logout');
  location.reload();
}
```

- [ ] **Step 4: Create coaches page**

Create `public_html/admin/js/pages/coaches.js`:

```js
/**
 * Coach Management Page
 */
App.registerPage('coaches', {
  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header">
        <h1 class="page-title">코치관리</h1>
        <button class="btn btn-primary" onclick="App.pages.coaches.showForm()">+ 코치 추가</button>
      </div>
      <div id="coachList"><div class="loading">불러오는 중...</div></div>
    `;
    await this.loadList();
  },

  async loadList() {
    const res = await API.get('/api/coaches.php?action=list');
    if (!res.ok) return;
    const coaches = res.data.coaches;

    if (coaches.length === 0) {
      document.getElementById('coachList').innerHTML =
        '<div class="empty-state">등록된 코치가 없습니다</div>';
      return;
    }

    document.getElementById('coachList').innerHTML = `
      <table class="data-table">
        <thead>
          <tr>
            <th>이름</th>
            <th>상태</th>
            <th>배정</th>
            <th>담당수</th>
            <th>최대인원</th>
            <th>메모</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          ${coaches.map(c => `
            <tr>
              <td>${c.coach_name}</td>
              <td>${UI.statusBadge(c.status)}</td>
              <td>${c.available == 1 ? '<span style="color:var(--success)">가능</span>' : '<span style="color:var(--text-secondary)">불가</span>'}</td>
              <td>${c.current_count}</td>
              <td>${c.max_capacity}</td>
              <td style="color:var(--text-secondary);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${c.memo || '-'}</td>
              <td>
                <button class="btn btn-small btn-secondary" onclick="App.pages.coaches.showForm(${c.id})">편집</button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  },

  async showForm(coachId = null) {
    let coach = { login_id: '', coach_name: '', status: 'active', available: 1, max_capacity: 0, memo: '' };
    if (coachId) {
      const res = await API.get(`/api/coaches.php?action=get&id=${coachId}`);
      if (res.ok) coach = res.data.coach;
    }

    const isEdit = !!coachId;
    UI.showModal(`
      <div class="modal-title">${isEdit ? '코치 수정' : '코치 추가'}</div>
      <form id="coachForm">
        <div class="form-group">
          <label class="form-label">로그인 ID</label>
          <input class="form-input" name="login_id" value="${coach.login_id}" ${isEdit ? 'readonly style="opacity:0.5"' : ''} required>
        </div>
        <div class="form-group">
          <label class="form-label">${isEdit ? '비밀번호 (변경 시에만 입력)' : '비밀번호'}</label>
          <input class="form-input" type="password" name="password" ${isEdit ? '' : 'required'}>
        </div>
        <div class="form-group">
          <label class="form-label">코치명 (영문)</label>
          <input class="form-input" name="coach_name" value="${coach.coach_name}" required>
        </div>
        <div class="form-group">
          <label class="form-label">상태</label>
          <select class="form-select" name="status">
            <option value="active" ${coach.status === 'active' ? 'selected' : ''}>활동중</option>
            <option value="inactive" ${coach.status === 'inactive' ? 'selected' : ''}>비활성</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">배정 가능</label>
          <select class="form-select" name="available">
            <option value="1" ${coach.available == 1 ? 'selected' : ''}>가능</option>
            <option value="0" ${coach.available == 0 ? 'selected' : ''}>불가</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">최대 담당 인원</label>
          <input class="form-input" type="number" name="max_capacity" value="${coach.max_capacity}" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">메모</label>
          <textarea class="form-textarea" name="memo">${coach.memo || ''}</textarea>
        </div>
        <div class="modal-actions">
          ${isEdit ? `<button type="button" class="btn btn-danger btn-small" onclick="App.pages.coaches.deleteCoach(${coachId})">삭제</button>` : ''}
          <button type="button" class="btn btn-secondary" onclick="UI.closeModal()">취소</button>
          <button type="submit" class="btn btn-primary">${isEdit ? '저장' : '등록'}</button>
        </div>
      </form>
    `);

    document.getElementById('coachForm').addEventListener('submit', async e => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const body = Object.fromEntries(fd);
      body.available = parseInt(body.available);
      body.max_capacity = parseInt(body.max_capacity);
      if (isEdit && !body.password) delete body.password;

      const url = isEdit
        ? `/api/coaches.php?action=update&id=${coachId}`
        : '/api/coaches.php?action=create';
      const res = await API.post(url, body);
      if (res.ok) {
        UI.closeModal();
        await this.loadList();
      } else {
        alert(res.message);
      }
    });
  },

  async deleteCoach(id) {
    if (!UI.confirm('이 코치를 삭제하시겠습니까?')) return;
    const res = await API.post(`/api/coaches.php?action=delete&id=${id}`);
    if (res.ok) {
      UI.closeModal();
      await this.loadList();
    } else {
      alert(res.message);
    }
  },
});
```

- [ ] **Step 5: Create placeholder pages for remaining sections**

Create `public_html/admin/js/pages/members.js`:
```js
App.registerPage('members', {
  render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">회원관리</h1></div>
      <div class="empty-state">Task 4에서 구현 예정</div>
    `;
  }
});
```

Create `public_html/admin/js/pages/member-chart.js`:
```js
// Member chart detail — implemented in Task 4-6
```

Create `public_html/admin/js/pages/merge.js`:
```js
App.registerPage('merge', {
  render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">동일인관리</h1></div>
      <div class="empty-state">Task 7에서 구현 예정</div>
    `;
  }
});
```

Create `public_html/admin/js/pages/import.js`:
```js
App.registerPage('import', {
  render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">데이터관리</h1></div>
      <div class="empty-state">Task 8에서 구현 예정</div>
    `;
  }
});
```

- [ ] **Step 6: Verify in browser**

Open https://pt.soritune.com/admin/ — should show login form. Log in with admin/soritune! — should show sidebar with pages. Navigate to #coaches — should show coach list (empty) with "코치 추가" button. Add a test coach and verify it appears in the list.

- [ ] **Step 7: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/coaches.php public_html/admin/
git commit -m "feat: coach CRUD API + admin SPA shell with coach management page"
```

---

## Task 4: Member CRUD API + Member List + Chart Shell

**Files:**
- Create: `public_html/api/members.php`
- Modify: `public_html/admin/js/pages/members.js`
- Modify: `public_html/admin/js/pages/member-chart.js`

- [ ] **Step 1: Create members API**

Create `public_html/api/members.php`:

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireAnyAuth();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $search = trim($_GET['search'] ?? '');
        $statusFilter = $_GET['status'] ?? '';
        $coachFilter = $_GET['coach_id'] ?? '';

        $statusSQL = memberStatusSQL();

        $where = ["m.merged_into IS NULL"];
        $params = [];

        // Coach role: only show assigned members
        if ($user['role'] === 'coach') {
            $where[] = "EXISTS (SELECT 1 FROM coach_assignments ca WHERE ca.member_id = m.id AND ca.coach_id = ? AND ca.released_at IS NULL)";
            $params[] = $user['id'];
        }

        if ($search !== '') {
            $where[] = "(m.name LIKE ? OR m.phone LIKE ? OR m.email LIKE ?)";
            $like = "%{$search}%";
            $params = array_merge($params, [$like, $like, $like]);
        }

        $havingClauses = [];
        if ($statusFilter !== '') {
            $havingClauses[] = "display_status = ?";
            $params[] = $statusFilter;
        }

        if ($coachFilter !== '') {
            $where[] = "EXISTS (SELECT 1 FROM coach_assignments ca2 WHERE ca2.member_id = m.id AND ca2.coach_id = ? AND ca2.released_at IS NULL)";
            $params[] = (int)$coachFilter;
        }

        $whereSQL = implode(' AND ', $where);
        $havingSQL = $havingClauses ? 'HAVING ' . implode(' AND ', $havingClauses) : '';

        $sql = "
            SELECT m.*,
              {$statusSQL} AS display_status,
              (SELECT GROUP_CONCAT(DISTINCT c.coach_name SEPARATOR ', ')
               FROM coach_assignments ca
               JOIN coaches c ON c.id = ca.coach_id
               WHERE ca.member_id = m.id AND ca.released_at IS NULL) AS current_coaches,
              (SELECT COUNT(*) FROM orders o WHERE o.member_id = m.id) AS order_count,
              (SELECT ma.source_id FROM member_accounts ma
               WHERE ma.member_id = m.id AND ma.source = 'soritune' LIMIT 1) AS soritune_id
            FROM members m
            WHERE {$whereSQL}
            {$havingSQL}
            ORDER BY m.updated_at DESC
            LIMIT 100
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonSuccess(['members' => $stmt->fetchAll()]);

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');

        // Coach role: verify access
        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM coach_assignments WHERE member_id = ? AND coach_id = ? AND released_at IS NULL");
            $stmt->execute([$id, $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);
        }

        $statusSQL = memberStatusSQL();
        $stmt = $db->prepare("
            SELECT m.*,
              {$statusSQL} AS display_status,
              (SELECT ma.source_id FROM member_accounts ma WHERE ma.member_id = m.id AND ma.source = 'soritune' LIMIT 1) AS soritune_id
            FROM members m WHERE m.id = ?
        ");
        $stmt->execute([$id]);
        $member = $stmt->fetch();
        if (!$member) jsonError('회원을 찾을 수 없습니다', 404);

        // Current coaches
        $stmt = $db->prepare("
            SELECT ca.*, c.coach_name
            FROM coach_assignments ca
            JOIN coaches c ON c.id = ca.coach_id
            WHERE ca.member_id = ? AND ca.released_at IS NULL
        ");
        $stmt->execute([$id]);
        $member['current_coaches'] = $stmt->fetchAll();

        // Linked accounts
        $stmt = $db->prepare("SELECT * FROM member_accounts WHERE member_id = ? ORDER BY is_primary DESC");
        $stmt->execute([$id]);
        $member['accounts'] = $stmt->fetchAll();

        jsonSuccess(['member' => $member]);

    case 'create':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $input = getJsonInput();
        $name = trim($input['name'] ?? '');
        if (!$name) jsonError('이름을 입력하세요');

        $phone = normalizePhone($input['phone'] ?? null);
        $email = trim($input['email'] ?? '') ?: null;

        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO members (name, phone, email, memo) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $phone, $email, $input['memo'] ?? null]);
        $memberId = (int)$db->lastInsertId();

        // Create primary account
        $stmt = $db->prepare("INSERT INTO member_accounts (member_id, source, source_id, name, phone, email, is_primary)
            VALUES (?, 'manual', NULL, ?, ?, ?, 1)");
        $stmt->execute([$memberId, $name, $phone, $email]);

        // If soritune_id provided, add soritune account
        $sorituneId = trim($input['soritune_id'] ?? '');
        if ($sorituneId) {
            $stmt = $db->prepare("INSERT INTO member_accounts (member_id, source, source_id, name, phone, email, is_primary)
                VALUES (?, 'soritune', ?, ?, ?, ?, 0)");
            $stmt->execute([$memberId, $sorituneId, $name, $phone, $email]);
        }

        $db->commit();
        jsonSuccess(['id' => $memberId], '회원이 등록되었습니다');

    case 'update':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $input = getJsonInput();

        $fields = [];
        $params = [];
        if (array_key_exists('name', $input)) { $fields[] = 'name = ?'; $params[] = trim($input['name']); }
        if (array_key_exists('phone', $input)) { $fields[] = 'phone = ?'; $params[] = normalizePhone($input['phone']); }
        if (array_key_exists('email', $input)) { $fields[] = 'email = ?'; $params[] = trim($input['email']) ?: null; }
        if (array_key_exists('memo', $input)) { $fields[] = 'memo = ?'; $params[] = $input['memo']; }

        if (empty($fields)) jsonError('변경할 항목이 없습니다');
        $params[] = $id;

        // Log change
        $stmt = $db->prepare("SELECT name, phone, email, memo FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $oldData = $stmt->fetch();

        $db->prepare("UPDATE members SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

        $stmt = $db->prepare("SELECT name, phone, email, memo FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $newData = $stmt->fetch();

        logChange($db, 'member', $id, 'info_update', $oldData, $newData, $user['role'], $user['id']);

        jsonSuccess([], '회원 정보가 수정되었습니다');

    case 'delete':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $db->prepare("DELETE FROM members WHERE id = ?")->execute([$id]);
        jsonSuccess([], '회원이 삭제되었습니다');

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 2: Rewrite members.js with search/filter/list**

Rewrite `public_html/admin/js/pages/members.js`:

```js
/**
 * Member List Page
 */
App.registerPage('members', {
  async render() {
    const coachRes = await API.get('/api/coaches.php?action=list');
    const coaches = coachRes.ok ? coachRes.data.coaches : [];

    document.getElementById('pageContent').innerHTML = `
      <div class="page-header">
        <h1 class="page-title">회원관리</h1>
        <button class="btn btn-primary" onclick="App.pages.members.showCreateForm()">+ 회원 추가</button>
      </div>
      <div class="filters">
        <input class="search-input" id="memberSearch" placeholder="이름, 전화번호, 이메일 검색" oninput="App.pages.members.search()">
        <select class="filter-pill" id="statusFilter" onchange="App.pages.members.search()">
          <option value="">전체 상태</option>
          <option value="진행중">진행중</option>
          <option value="진행예정">진행예정</option>
          <option value="매칭대기">매칭대기</option>
          <option value="연기">연기</option>
          <option value="중단">중단</option>
          <option value="환불">환불</option>
          <option value="종료">종료</option>
        </select>
        <select class="filter-pill" id="coachFilter" onchange="App.pages.members.search()">
          <option value="">전체 코치</option>
          ${coaches.map(c => `<option value="${c.id}">${c.coach_name}</option>`).join('')}
        </select>
      </div>
      <div id="memberList"><div class="loading">불러오는 중...</div></div>
    `;
    await this.search();
  },

  _searchTimer: null,
  search() {
    clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => this.loadList(), 300);
  },

  async loadList() {
    const search = document.getElementById('memberSearch')?.value || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const coach = document.getElementById('coachFilter')?.value || '';

    const params = new URLSearchParams({ action: 'list' });
    if (search) params.set('search', search);
    if (status) params.set('status', status);
    if (coach) params.set('coach_id', coach);

    const res = await API.get(`/api/members.php?${params}`);
    if (!res.ok) return;
    const members = res.data.members;

    if (members.length === 0) {
      document.getElementById('memberList').innerHTML =
        '<div class="empty-state">조건에 맞는 회원이 없습니다</div>';
      return;
    }

    document.getElementById('memberList').innerHTML = `
      <table class="data-table">
        <thead>
          <tr>
            <th>이름</th>
            <th>전화번호</th>
            <th>담당코치</th>
            <th>상태</th>
            <th>PT건수</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          ${members.map(m => `
            <tr style="cursor:pointer" onclick="location.hash='member-chart/${m.id}'">
              <td>${m.name}</td>
              <td style="color:var(--text-secondary)">${m.phone || '-'}</td>
              <td>${m.current_coaches || '-'}</td>
              <td>${UI.statusBadge(m.display_status)}</td>
              <td>${m.order_count}</td>
              <td><span style="color:var(--text-secondary)">→</span></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  },

  showCreateForm() {
    UI.showModal(`
      <div class="modal-title">회원 추가</div>
      <form id="memberCreateForm">
        <div class="form-group">
          <label class="form-label">이름</label>
          <input class="form-input" name="name" required>
        </div>
        <div class="form-group">
          <label class="form-label">전화번호</label>
          <input class="form-input" name="phone" placeholder="010-0000-0000">
        </div>
        <div class="form-group">
          <label class="form-label">이메일</label>
          <input class="form-input" name="email" type="email">
        </div>
        <div class="form-group">
          <label class="form-label">Soritune ID</label>
          <input class="form-input" name="soritune_id" placeholder="soritunenglish.com ID">
        </div>
        <div class="form-group">
          <label class="form-label">메모</label>
          <textarea class="form-textarea" name="memo"></textarea>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary" onclick="UI.closeModal()">취소</button>
          <button type="submit" class="btn btn-primary">등록</button>
        </div>
      </form>
    `);

    document.getElementById('memberCreateForm').addEventListener('submit', async e => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target));
      const res = await API.post('/api/members.php?action=create', body);
      if (res.ok) {
        UI.closeModal();
        location.hash = `member-chart/${res.data.id}`;
      } else {
        alert(res.message);
      }
    });
  },
});
```

- [ ] **Step 3: Create member-chart.js (basic structure with info header + tab shell)**

Rewrite `public_html/admin/js/pages/member-chart.js`:

```js
/**
 * Member Chart (Detail) Page
 */
App.registerPage('member-chart', {
  memberId: null,
  member: null,

  async render(params) {
    this.memberId = parseInt(params[0]);
    if (!this.memberId) { location.hash = 'members'; return; }

    document.getElementById('pageContent').innerHTML = '<div class="loading">불러오는 중...</div>';

    const res = await API.get(`/api/members.php?action=get&id=${this.memberId}`);
    if (!res.ok) {
      document.getElementById('pageContent').innerHTML = `<div class="empty-state">${res.message}</div>`;
      return;
    }
    this.member = res.data.member;
    this.renderChart();
  },

  renderChart() {
    const m = this.member;
    const coaches = m.current_coaches?.map(c => c.coach_name).join(', ') || '-';
    const sorituneId = m.soritune_id || '-';

    document.getElementById('pageContent').innerHTML = `
      <div style="margin-bottom:16px">
        <a href="#members" style="color:var(--text-secondary);text-decoration:none;font-size:13px">← 회원목록</a>
      </div>

      <div class="card card-elevated" style="margin-bottom:20px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start">
          <div>
            <h2 style="font-size:20px;font-weight:700;margin-bottom:16px">${m.name}</h2>
            <div class="info-grid">
              <div>
                <div class="info-item-label">전화번호</div>
                <div class="info-item-value">${m.phone || '-'}</div>
              </div>
              <div>
                <div class="info-item-label">이메일</div>
                <div class="info-item-value">${m.email || '-'}</div>
              </div>
              <div>
                <div class="info-item-label">Soritune ID</div>
                <div class="info-item-value">${sorituneId}</div>
              </div>
              <div>
                <div class="info-item-label">대표 상태</div>
                <div class="info-item-value">${UI.statusBadge(m.display_status)}</div>
              </div>
              <div>
                <div class="info-item-label">담당 코치</div>
                <div class="info-item-value">${coaches}</div>
              </div>
            </div>
          </div>
          <button class="btn btn-small btn-secondary" onclick="App.pages['member-chart'].showEditForm()">정보수정</button>
        </div>
      </div>

      <div id="ptProgressSection"></div>

      <div class="tabs" id="chartTabs">
        <button class="tab-btn active" data-tab="orders" onclick="App.pages['member-chart'].switchTab('orders')">PT이력</button>
        <button class="tab-btn" data-tab="coach-history" onclick="App.pages['member-chart'].switchTab('coach-history')">코치이력</button>
        <button class="tab-btn" data-tab="tests" onclick="App.pages['member-chart'].switchTab('tests')">테스트결과</button>
        <button class="tab-btn" data-tab="notes" onclick="App.pages['member-chart'].switchTab('notes')">메모</button>
        <button class="tab-btn" data-tab="logs" onclick="App.pages['member-chart'].switchTab('logs')">변경로그</button>
        <button class="tab-btn" data-tab="merge-info" onclick="App.pages['member-chart'].switchTab('merge-info')">병합정보</button>
      </div>
      <div id="tabContent"><div class="empty-state">PT이력 탭 — Task 5에서 구현</div></div>
    `;

    // Load PT progress section (implemented in Task 5)
    this.loadPtProgress();
    this.switchTab('orders');
  },

  switchTab(tabName) {
    document.querySelectorAll('#chartTabs .tab-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.tab === tabName);
    });
    // Tab content loaders will be added in Tasks 5-7
    const loaders = {
      'orders': () => this.loadOrders(),
      'coach-history': () => this.loadCoachHistory(),
      'tests': () => this.loadTests(),
      'notes': () => this.loadNotes(),
      'logs': () => this.loadLogs(),
      'merge-info': () => this.loadMergeInfo(),
    };
    if (loaders[tabName]) loaders[tabName]();
  },

  async loadPtProgress() {
    // Placeholder — implemented in Task 5
    document.getElementById('ptProgressSection').innerHTML = '';
  },

  async loadOrders() {
    document.getElementById('tabContent').innerHTML = '<div class="empty-state">Task 5에서 구현</div>';
  },

  async loadCoachHistory() {
    document.getElementById('tabContent').innerHTML = '<div class="empty-state">Task 6에서 구현</div>';
  },

  async loadTests() {
    document.getElementById('tabContent').innerHTML = '<div class="empty-state">Task 6에서 구현</div>';
  },

  async loadNotes() {
    document.getElementById('tabContent').innerHTML = '<div class="empty-state">Task 6에서 구현</div>';
  },

  async loadLogs() {
    document.getElementById('tabContent').innerHTML = '<div class="empty-state">Task 6에서 구현</div>';
  },

  async loadMergeInfo() {
    document.getElementById('tabContent').innerHTML = '<div class="empty-state">Task 7에서 구현</div>';
  },

  async showEditForm() {
    const m = this.member;
    UI.showModal(`
      <div class="modal-title">회원 정보 수정</div>
      <form id="memberEditForm">
        <div class="form-group">
          <label class="form-label">이름</label>
          <input class="form-input" name="name" value="${m.name}" required>
        </div>
        <div class="form-group">
          <label class="form-label">전화번호</label>
          <input class="form-input" name="phone" value="${m.phone || ''}">
        </div>
        <div class="form-group">
          <label class="form-label">이메일</label>
          <input class="form-input" name="email" value="${m.email || ''}" type="email">
        </div>
        <div class="form-group">
          <label class="form-label">메모</label>
          <textarea class="form-textarea" name="memo">${m.memo || ''}</textarea>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-danger btn-small" onclick="App.pages['member-chart'].deleteMember()">회원 삭제</button>
          <button type="button" class="btn btn-secondary" onclick="UI.closeModal()">취소</button>
          <button type="submit" class="btn btn-primary">저장</button>
        </div>
      </form>
    `);

    document.getElementById('memberEditForm').addEventListener('submit', async e => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target));
      const res = await API.post(`/api/members.php?action=update&id=${this.memberId}`, body);
      if (res.ok) {
        UI.closeModal();
        await this.render([this.memberId]);
      } else {
        alert(res.message);
      }
    });
  },

  async deleteMember() {
    if (!UI.confirm('이 회원을 삭제하시겠습니까? 모든 이력이 삭제됩니다.')) return;
    const res = await API.post(`/api/members.php?action=delete&id=${this.memberId}`);
    if (res.ok) {
      UI.closeModal();
      location.hash = 'members';
    } else {
      alert(res.message);
    }
  },
});
```

- [ ] **Step 4: Verify in browser**

Open https://pt.soritune.com/admin/#members — should show member list (empty), search input, filters. Click "회원 추가" — add a test member. Click the member row — should navigate to member chart with info header and tabs.

- [ ] **Step 5: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/members.php public_html/admin/js/pages/members.js public_html/admin/js/pages/member-chart.js
git commit -m "feat: member CRUD API + member list with search/filter + chart shell"
```

---

## Task 5: Orders API + PT Progress + Session Tracking

**Files:**
- Create: `public_html/api/orders.php`
- Modify: `public_html/admin/js/pages/member-chart.js` (loadOrders, loadPtProgress methods)

- [ ] **Step 1: Create orders API**

Create `public_html/api/orders.php`:

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireAnyAuth();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $memberId = (int)($_GET['member_id'] ?? 0);
        if (!$memberId) jsonError('member_id가 필요합니다');

        // Coach access check
        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM coach_assignments WHERE member_id = ? AND coach_id = ? AND released_at IS NULL");
            $stmt->execute([$memberId, $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);
        }

        $stmt = $db->prepare("
            SELECT o.*,
              c.coach_name,
              (SELECT COUNT(*) FROM order_sessions os WHERE os.order_id = o.id AND os.completed_at IS NOT NULL) AS used_sessions
            FROM orders o
            LEFT JOIN coaches c ON c.id = o.coach_id
            WHERE o.member_id = ?
            ORDER BY o.start_date DESC
        ");
        $stmt->execute([$memberId]);
        jsonSuccess(['orders' => $stmt->fetchAll()]);

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');

        $stmt = $db->prepare("
            SELECT o.*, c.coach_name,
              (SELECT COUNT(*) FROM order_sessions os WHERE os.order_id = o.id AND os.completed_at IS NOT NULL) AS used_sessions
            FROM orders o
            LEFT JOIN coaches c ON c.id = o.coach_id
            WHERE o.id = ?
        ");
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) jsonError('주문을 찾을 수 없습니다', 404);

        // Load sessions for count type
        if ($order['product_type'] === 'count') {
            $stmt = $db->prepare("SELECT * FROM order_sessions WHERE order_id = ? ORDER BY session_number");
            $stmt->execute([$id]);
            $order['sessions'] = $stmt->fetchAll();
        }

        jsonSuccess(['order' => $order]);

    case 'create':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $input = getJsonInput();
        $memberId = (int)($input['member_id'] ?? 0);
        $productName = trim($input['product_name'] ?? '');
        $productType = $input['product_type'] ?? '';
        $startDate = $input['start_date'] ?? '';
        $endDate = $input['end_date'] ?? '';

        if (!$memberId || !$productName || !$productType || !$startDate || !$endDate) {
            jsonError('필수 항목을 입력하세요');
        }
        if (!in_array($productType, ['period', 'count'])) jsonError('올바른 상품 유형을 선택하세요');

        $totalSessions = $productType === 'count' ? (int)($input['total_sessions'] ?? 0) : null;
        if ($productType === 'count' && $totalSessions < 1) jsonError('횟수형은 총 횟수를 입력하세요');

        $coachId = !empty($input['coach_id']) ? (int)$input['coach_id'] : null;
        $status = $input['status'] ?? '매칭대기';

        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO orders
            (member_id, coach_id, product_name, product_type, start_date, end_date, total_sessions, amount, status, memo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $memberId, $coachId, $productName, $productType,
            $startDate, $endDate, $totalSessions,
            (int)($input['amount'] ?? 0), $status, $input['memo'] ?? null,
        ]);
        $orderId = (int)$db->lastInsertId();

        // Create session rows for count type
        if ($productType === 'count' && $totalSessions > 0) {
            $stmt = $db->prepare("INSERT INTO order_sessions (order_id, session_number) VALUES (?, ?)");
            for ($i = 1; $i <= $totalSessions; $i++) {
                $stmt->execute([$orderId, $i]);
            }
        }

        // If coach assigned, create coach_assignment
        if ($coachId) {
            $db->prepare("INSERT INTO coach_assignments (member_id, coach_id, order_id) VALUES (?, ?, ?)")
                ->execute([$memberId, $coachId, $orderId]);
            logChange($db, 'coach_assignment', $orderId, 'coach_assigned',
                null, ['coach_id' => $coachId], $user['role'], $user['id']);
        }

        $db->commit();
        jsonSuccess(['id' => $orderId], 'PT 이력이 추가되었습니다');

    case 'update':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $input = getJsonInput();

        // Get current order
        $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$id]);
        $oldOrder = $stmt->fetch();
        if (!$oldOrder) jsonError('주문을 찾을 수 없습니다', 404);

        // Coach can only update status
        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM coach_assignments WHERE member_id = ? AND coach_id = ? AND released_at IS NULL");
            $stmt->execute([$oldOrder['member_id'], $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);

            $allowedFields = ['status'];
            $input = array_intersect_key($input, array_flip($allowedFields));
        }

        $db->beginTransaction();

        $fields = [];
        $params = [];
        foreach (['product_name','product_type','start_date','end_date','total_sessions','amount','status','memo'] as $f) {
            if (array_key_exists($f, $input)) {
                $fields[] = "{$f} = ?";
                $params[] = $input[$f];
            }
        }

        // Coach change
        $newCoachId = array_key_exists('coach_id', $input) ? ($input['coach_id'] ?: null) : null;
        $coachChanged = array_key_exists('coach_id', $input) && (int)$input['coach_id'] !== (int)$oldOrder['coach_id'];

        if (array_key_exists('coach_id', $input)) {
            $fields[] = "coach_id = ?";
            $params[] = $newCoachId;
        }

        if (!empty($fields)) {
            $params[] = $id;
            $db->prepare("UPDATE orders SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        }

        // Handle coach change in coach_assignments
        if ($coachChanged) {
            // Release old assignment
            $db->prepare("UPDATE coach_assignments SET released_at = NOW(), reason = '코치 변경'
                WHERE order_id = ? AND released_at IS NULL")->execute([$id]);
            // Create new assignment
            if ($newCoachId) {
                $db->prepare("INSERT INTO coach_assignments (member_id, coach_id, order_id) VALUES (?, ?, ?)")
                    ->execute([$oldOrder['member_id'], $newCoachId, $id]);
            }
            logChange($db, 'coach_assignment', $id, 'coach_change',
                ['coach_id' => $oldOrder['coach_id']], ['coach_id' => $newCoachId],
                $user['role'], $user['id']);
        }

        // Log status change
        if (array_key_exists('status', $input) && $input['status'] !== $oldOrder['status']) {
            logChange($db, 'order', $id, 'status_change',
                ['status' => $oldOrder['status']], ['status' => $input['status']],
                $user['role'], $user['id']);
        }

        $db->commit();
        jsonSuccess([], 'PT 이력이 수정되었습니다');

    case 'delete':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        // Release coach assignments
        $db->prepare("UPDATE coach_assignments SET released_at = NOW(), reason = '주문 삭제' WHERE order_id = ? AND released_at IS NULL")->execute([$id]);
        $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$id]);
        jsonSuccess([], 'PT 이력이 삭제되었습니다');

    case 'complete_session':
        $sessionId = (int)($_GET['session_id'] ?? 0);
        if (!$sessionId) jsonError('session_id가 필요합니다');

        $stmt = $db->prepare("
            SELECT os.*, o.member_id, o.coach_id
            FROM order_sessions os
            JOIN orders o ON o.id = os.order_id
            WHERE os.id = ?
        ");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();
        if (!$session) jsonError('세션을 찾을 수 없습니다', 404);

        // Coach access check
        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM coach_assignments WHERE member_id = ? AND coach_id = ? AND released_at IS NULL");
            $stmt->execute([$session['member_id'], $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);
        }

        // Toggle completion
        if ($session['completed_at']) {
            $db->prepare("UPDATE order_sessions SET completed_at = NULL WHERE id = ?")->execute([$sessionId]);
            jsonSuccess(['completed' => false], '회차 완료가 취소되었습니다');
        } else {
            $db->prepare("UPDATE order_sessions SET completed_at = NOW() WHERE id = ?")->execute([$sessionId]);
            jsonSuccess(['completed' => true], '회차가 완료 처리되었습니다');
        }

    case 'active':
        // Get active (진행중) orders for a member — used for PT progress section
        $memberId = (int)($_GET['member_id'] ?? 0);
        if (!$memberId) jsonError('member_id가 필요합니다');

        $stmt = $db->prepare("
            SELECT o.*, c.coach_name,
              (SELECT COUNT(*) FROM order_sessions os WHERE os.order_id = o.id AND os.completed_at IS NOT NULL) AS used_sessions
            FROM orders o
            LEFT JOIN coaches c ON c.id = o.coach_id
            WHERE o.member_id = ? AND o.status IN ('진행중', '매칭완료')
            ORDER BY o.start_date DESC
        ");
        $stmt->execute([$memberId]);
        $orders = $stmt->fetchAll();

        // Load sessions for count type orders
        foreach ($orders as &$order) {
            if ($order['product_type'] === 'count') {
                $stmt = $db->prepare("SELECT * FROM order_sessions WHERE order_id = ? ORDER BY session_number");
                $stmt->execute([$order['id']]);
                $order['sessions'] = $stmt->fetchAll();
            }
        }
        unset($order);

        jsonSuccess(['orders' => $orders]);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 2: Add loadPtProgress and loadOrders to member-chart.js**

Add these methods to the member-chart page object in `public_html/admin/js/pages/member-chart.js`. Replace the placeholder `loadPtProgress` and `loadOrders` methods:

```js
  // Replace the loadPtProgress method:
  async loadPtProgress() {
    const res = await API.get(`/api/orders.php?action=active&member_id=${this.memberId}`);
    if (!res.ok || res.data.orders.length === 0) {
      document.getElementById('ptProgressSection').innerHTML = '';
      return;
    }

    const orders = res.data.orders;
    document.getElementById('ptProgressSection').innerHTML = `
      <div class="card" style="margin-bottom:20px;background:var(--surface-card)">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">진행 중인 PT</h3>
        ${orders.map(o => this.renderPtProgressCard(o)).join('')}
      </div>
    `;
  },

  renderPtProgressCard(order) {
    const today = new Date();
    const start = new Date(order.start_date);
    const end = new Date(order.end_date);

    if (order.product_type === 'period') {
      const totalDays = Math.max(1, (end - start) / 86400000);
      const elapsed = Math.max(0, (today - start) / 86400000);
      const pct = Math.min(100, Math.round((elapsed / totalDays) * 100));
      const remaining = Math.max(0, Math.ceil((end - today) / 86400000));

      return `
        <div class="pt-progress-card">
          <div class="pt-progress-header">
            <span class="pt-progress-title">${order.product_name} (기간형)</span>
            <span class="pt-progress-coach">${order.coach_name || '-'}</span>
          </div>
          <div class="pt-progress-meta">${order.start_date} ~ ${order.end_date} | 남은 일수: ${remaining}일</div>
          <div class="progress-bar"><div class="progress-fill" style="width:${pct}%"></div></div>
          <div style="font-size:11px;color:var(--text-secondary);text-align:right">${pct}%</div>
        </div>
      `;
    }

    // Count type
    const used = parseInt(order.used_sessions) || 0;
    const total = parseInt(order.total_sessions) || 1;
    const pct = Math.round((used / total) * 100);
    const sessions = order.sessions || [];

    return `
      <div class="pt-progress-card">
        <div class="pt-progress-header">
          <span class="pt-progress-title">${order.product_name} (횟수형)</span>
          <span class="pt-progress-coach">${order.coach_name || '-'}</span>
        </div>
        <div class="pt-progress-meta">${order.start_date} ~ ${order.end_date} | ${used} / ${total}회</div>
        <div class="progress-bar"><div class="progress-fill" style="width:${pct}%"></div></div>
        <ul class="session-list">
          ${sessions.map(s => `
            <li class="session-item">
              <button class="session-check ${s.completed_at ? 'done' : ''}"
                onclick="App.pages['member-chart'].toggleSession(${s.id}, event)">
                ${s.completed_at ? '&#10003;' : ''}
              </button>
              <span>${s.session_number}회차</span>
              <span class="session-date">${s.completed_at ? UI.formatDate(s.completed_at) + ' 완료' : '-'}</span>
            </li>
          `).join('')}
        </ul>
      </div>
    `;
  },

  async toggleSession(sessionId, event) {
    const res = await API.post(`/api/orders.php?action=complete_session&session_id=${sessionId}`);
    if (res.ok) {
      await this.render([this.memberId]); // Reload full chart
    } else {
      alert(res.message);
    }
  },

  // Replace the loadOrders method:
  async loadOrders() {
    const res = await API.get(`/api/orders.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const orders = res.data.orders;

    const isAdmin = true; // Admin page always admin context

    document.getElementById('tabContent').innerHTML = `
      ${isAdmin ? `<div style="margin-bottom:12px;text-align:right">
        <button class="btn btn-small btn-primary" onclick="App.pages['member-chart'].showOrderForm()">+ PT이력 추가</button>
      </div>` : ''}
      ${orders.length === 0 ? '<div class="empty-state">PT 이력이 없습니다</div>' : `
        <table class="data-table">
          <thead><tr>
            <th>상품명</th><th>유형</th><th>코치</th><th>기간</th><th>진행</th><th>금액</th><th>상태</th><th></th>
          </tr></thead>
          <tbody>
            ${orders.map(o => `
              <tr>
                <td>${o.product_name}</td>
                <td>${o.product_type === 'period' ? '기간형' : '횟수형'}</td>
                <td>${o.coach_name || '-'}</td>
                <td style="font-size:12px;color:var(--text-secondary)">${o.start_date} ~ ${o.end_date}</td>
                <td>${o.product_type === 'count' ? `${o.used_sessions}/${o.total_sessions}` : '-'}</td>
                <td>${UI.formatMoney(o.amount)}</td>
                <td>${UI.statusBadge(o.status)}</td>
                <td>${isAdmin ? `<button class="btn btn-small btn-secondary" onclick="App.pages['member-chart'].showOrderForm(${o.id})">편집</button>` : ''}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `}
    `;
  },

  async showOrderForm(orderId = null) {
    let order = { product_name:'', product_type:'period', start_date:'', end_date:'', total_sessions:'', amount:0, status:'매칭대기', coach_id:'', memo:'' };
    if (orderId) {
      const res = await API.get(`/api/orders.php?action=get&id=${orderId}`);
      if (res.ok) order = res.data.order;
    }

    const coachRes = await API.get('/api/coaches.php?action=list');
    const coaches = coachRes.ok ? coachRes.data.coaches.filter(c => c.status === 'active') : [];
    const isEdit = !!orderId;

    UI.showModal(`
      <div class="modal-title">${isEdit ? 'PT이력 수정' : 'PT이력 추가'}</div>
      <form id="orderForm">
        <div class="form-group">
          <label class="form-label">상품명</label>
          <input class="form-input" name="product_name" value="${order.product_name}" required>
        </div>
        <div class="form-group">
          <label class="form-label">상품 유형</label>
          <select class="form-select" name="product_type" onchange="document.getElementById('sessionFields').style.display=this.value==='count'?'block':'none'">
            <option value="period" ${order.product_type==='period'?'selected':''}>기간형</option>
            <option value="count" ${order.product_type==='count'?'selected':''}>횟수형</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">담당 코치</label>
          <select class="form-select" name="coach_id">
            <option value="">미배정</option>
            ${coaches.map(c => `<option value="${c.id}" ${order.coach_id==c.id?'selected':''}>${c.coach_name}</option>`).join('')}
          </select>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group">
            <label class="form-label">시작일</label>
            <input class="form-input" type="date" name="start_date" value="${order.start_date}" required>
          </div>
          <div class="form-group">
            <label class="form-label">종료일</label>
            <input class="form-input" type="date" name="end_date" value="${order.end_date}" required>
          </div>
        </div>
        <div id="sessionFields" style="display:${order.product_type==='count'?'block':'none'}">
          <div class="form-group">
            <label class="form-label">총 횟수</label>
            <input class="form-input" type="number" name="total_sessions" value="${order.total_sessions||''}" min="1">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">금액</label>
          <input class="form-input" type="number" name="amount" value="${order.amount||0}" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">상태</label>
          <select class="form-select" name="status">
            ${['매칭대기','매칭완료','진행중','연기','중단','환불','종료'].map(s =>
              `<option value="${s}" ${order.status===s?'selected':''}>${s}</option>`
            ).join('')}
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">메모</label>
          <textarea class="form-textarea" name="memo">${order.memo||''}</textarea>
        </div>
        <div class="modal-actions">
          ${isEdit ? `<button type="button" class="btn btn-danger btn-small" onclick="App.pages['member-chart'].deleteOrder(${orderId})">삭제</button>` : ''}
          <button type="button" class="btn btn-secondary" onclick="UI.closeModal()">취소</button>
          <button type="submit" class="btn btn-primary">${isEdit ? '저장' : '추가'}</button>
        </div>
      </form>
    `);

    document.getElementById('orderForm').addEventListener('submit', async e => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target));
      body.member_id = this.memberId;
      body.amount = parseInt(body.amount) || 0;
      body.total_sessions = parseInt(body.total_sessions) || null;
      body.coach_id = parseInt(body.coach_id) || null;

      const url = isEdit
        ? `/api/orders.php?action=update&id=${orderId}`
        : '/api/orders.php?action=create';
      const res = await API.post(url, body);
      if (res.ok) {
        UI.closeModal();
        await this.render([this.memberId]);
      } else {
        alert(res.message);
      }
    });
  },

  async deleteOrder(id) {
    if (!UI.confirm('이 PT이력을 삭제하시겠습니까?')) return;
    const res = await API.post(`/api/orders.php?action=delete&id=${id}`);
    if (res.ok) {
      UI.closeModal();
      await this.render([this.memberId]);
    } else {
      alert(res.message);
    }
  },
```

- [ ] **Step 3: Verify in browser**

Navigate to a member chart. Add a period-type PT order and a count-type PT order. Verify:
- PT progress section shows with progress bars
- Count-type shows session checkboxes
- Clicking a session checkbox marks it complete
- PT이력 tab shows all orders in a table

- [ ] **Step 4: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/orders.php public_html/admin/js/pages/member-chart.js
git commit -m "feat: orders API + PT progress display + session completion tracking"
```

---

## Task 6: Notes, Tests, Coach History, Change Logs

**Files:**
- Create: `public_html/api/notes.php`
- Create: `public_html/api/tests.php`
- Create: `public_html/api/logs.php`
- Modify: `public_html/admin/js/pages/member-chart.js` (remaining tab methods)

- [ ] **Step 1: Create notes API**

Create `public_html/api/notes.php`:

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
$user = requireAnyAuth();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $memberId = (int)($_GET['member_id'] ?? 0);
        if (!$memberId) jsonError('member_id가 필요합니다');

        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM coach_assignments WHERE member_id = ? AND coach_id = ? AND released_at IS NULL");
            $stmt->execute([$memberId, $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);
        }

        $stmt = $db->prepare("
            SELECT mn.*,
              CASE mn.author_type
                WHEN 'admin' THEN (SELECT name FROM admins WHERE id = mn.author_id)
                WHEN 'coach' THEN (SELECT coach_name FROM coaches WHERE id = mn.author_id)
              END AS author_name
            FROM member_notes mn
            WHERE mn.member_id = ?
            ORDER BY mn.created_at DESC
        ");
        $stmt->execute([$memberId]);
        jsonSuccess(['notes' => $stmt->fetchAll()]);

    case 'create':
        $input = getJsonInput();
        $memberId = (int)($input['member_id'] ?? 0);
        $content = trim($input['content'] ?? '');
        if (!$memberId || !$content) jsonError('내용을 입력하세요');

        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM coach_assignments WHERE member_id = ? AND coach_id = ? AND released_at IS NULL");
            $stmt->execute([$memberId, $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);
        }

        $stmt = $db->prepare("INSERT INTO member_notes (member_id, author_type, author_id, content) VALUES (?, ?, ?, ?)");
        $stmt->execute([$memberId, $user['role'], $user['id'], $content]);
        jsonSuccess(['id' => (int)$db->lastInsertId()], '메모가 추가되었습니다');

    case 'delete':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $id = (int)($_GET['id'] ?? 0);
        $db->prepare("DELETE FROM member_notes WHERE id = ?")->execute([$id]);
        jsonSuccess([], '메모가 삭제되었습니다');

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 2: Create tests API**

Create `public_html/api/tests.php`:

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
$user = requireAnyAuth();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $memberId = (int)($_GET['member_id'] ?? 0);
        if (!$memberId) jsonError('member_id가 필요합니다');
        $stmt = $db->prepare("SELECT * FROM test_results WHERE member_id = ? ORDER BY tested_at DESC");
        $stmt->execute([$memberId]);
        jsonSuccess(['results' => $stmt->fetchAll()]);

    case 'create':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $input = getJsonInput();
        $memberId = (int)($input['member_id'] ?? 0);
        $testType = $input['test_type'] ?? '';
        $testedAt = $input['tested_at'] ?? '';

        if (!$memberId || !in_array($testType, ['disc','sensory']) || !$testedAt) {
            jsonError('필수 항목을 입력하세요');
        }

        $stmt = $db->prepare("INSERT INTO test_results (member_id, test_type, result_data, tested_at, memo)
            VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $memberId, $testType,
            json_encode($input['result_data'] ?? [], JSON_UNESCAPED_UNICODE),
            $testedAt, $input['memo'] ?? null,
        ]);
        jsonSuccess(['id' => (int)$db->lastInsertId()], '테스트 결과가 저장되었습니다');

    case 'delete':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $id = (int)($_GET['id'] ?? 0);
        $db->prepare("DELETE FROM test_results WHERE id = ?")->execute([$id]);
        jsonSuccess([], '테스트 결과가 삭제되었습니다');

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 3: Create logs API**

Create `public_html/api/logs.php`:

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
$user = requireAnyAuth();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $targetType = $_GET['target_type'] ?? '';
        $targetId = (int)($_GET['target_id'] ?? 0);
        $memberId = (int)($_GET['member_id'] ?? 0);

        if ($memberId) {
            // Get all logs related to a member (member + their orders + their assignments)
            $stmt = $db->prepare("
                SELECT cl.*,
                  CASE cl.actor_type
                    WHEN 'admin' THEN (SELECT name FROM admins WHERE id = cl.actor_id)
                    WHEN 'coach' THEN (SELECT coach_name FROM coaches WHERE id = cl.actor_id)
                    ELSE 'system'
                  END AS actor_name
                FROM change_logs cl
                WHERE (cl.target_type = 'member' AND cl.target_id = ?)
                   OR (cl.target_type = 'order' AND cl.target_id IN (SELECT id FROM orders WHERE member_id = ?))
                   OR (cl.target_type = 'coach_assignment' AND cl.target_id IN (SELECT id FROM orders WHERE member_id = ?))
                   OR (cl.target_type = 'merge' AND cl.target_id = ?)
                ORDER BY cl.created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$memberId, $memberId, $memberId, $memberId]);
        } else {
            $stmt = $db->prepare("
                SELECT cl.* FROM change_logs cl
                WHERE cl.target_type = ? AND cl.target_id = ?
                ORDER BY cl.created_at DESC LIMIT 50
            ");
            $stmt->execute([$targetType, $targetId]);
        }
        jsonSuccess(['logs' => $stmt->fetchAll()]);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 4: Add tab content loaders to member-chart.js**

Add/replace these methods in the member-chart page object:

```js
  async loadCoachHistory() {
    const res = await API.get(`/api/orders.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;

    // Build coach history from orders + assignments perspective
    const stmt = await API.get(`/api/members.php?action=get&id=${this.memberId}`);
    const assignments = stmt.ok ? stmt.data.member.current_coaches : [];

    // Get all coach assignments via logs
    const logRes = await API.get(`/api/logs.php?action=list&member_id=${this.memberId}`);
    const coachLogs = logRes.ok
      ? logRes.data.logs.filter(l => l.action === 'coach_assigned' || l.action === 'coach_change')
      : [];

    document.getElementById('tabContent').innerHTML = `
      <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">현재 담당 코치</h3>
      ${assignments.length === 0
        ? '<div style="color:var(--text-secondary);margin-bottom:20px">담당 코치 없음</div>'
        : `<div style="margin-bottom:20px">${assignments.map(a =>
            `<span class="badge badge-active" style="margin-right:8px">${a.coach_name}</span>`
          ).join('')}</div>`
      }
      <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">변경 이력</h3>
      ${coachLogs.length === 0 ? '<div class="empty-state">코치 변경 이력이 없습니다</div>' : `
        <table class="data-table">
          <thead><tr><th>일시</th><th>변경 내용</th><th>변경자</th></tr></thead>
          <tbody>
            ${coachLogs.map(l => {
              const oldVal = JSON.parse(l.old_value || '{}');
              const newVal = JSON.parse(l.new_value || '{}');
              return `<tr>
                <td style="font-size:12px;color:var(--text-secondary)">${UI.formatDate(l.created_at)}</td>
                <td>${l.action === 'coach_assigned' ? '코치 배정' : '코치 변경'}: ${JSON.stringify(newVal)}</td>
                <td style="font-size:12px">${l.actor_name || l.actor_type}</td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      `}
    `;
  },

  async loadTests() {
    const res = await API.get(`/api/tests.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const results = res.data.results;

    const discResults = results.filter(r => r.test_type === 'disc');
    const sensoryResults = results.filter(r => r.test_type === 'sensory');

    document.getElementById('tabContent').innerHTML = `
      <div style="margin-bottom:12px;text-align:right">
        <button class="btn btn-small btn-primary" onclick="App.pages['member-chart'].showTestForm()">+ 결과 추가</button>
      </div>
      <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">DISC 진단</h3>
      ${discResults.length === 0 ? '<div style="color:var(--text-secondary);margin-bottom:20px">결과 없음</div>' :
        discResults.map(r => `
          <div class="card" style="margin-bottom:8px;padding:12px;background:var(--surface-card)">
            <div style="display:flex;justify-content:space-between;align-items:center">
              <div>
                <span style="font-size:12px;color:var(--text-secondary)">${r.tested_at}</span>
                <div style="margin-top:4px">${this.formatTestData(r.result_data)}</div>
                ${r.memo ? `<div style="font-size:12px;color:var(--text-secondary);margin-top:4px">${r.memo}</div>` : ''}
              </div>
              <button class="btn btn-small btn-outline" onclick="App.pages['member-chart'].deleteTest(${r.id})">삭제</button>
            </div>
          </div>
        `).join('')
      }
      <h3 style="font-size:14px;font-weight:700;margin:20px 0 12px">오감각 테스트</h3>
      ${sensoryResults.length === 0 ? '<div style="color:var(--text-secondary)">결과 없음</div>' :
        sensoryResults.map(r => `
          <div class="card" style="margin-bottom:8px;padding:12px;background:var(--surface-card)">
            <div style="display:flex;justify-content:space-between;align-items:center">
              <div>
                <span style="font-size:12px;color:var(--text-secondary)">${r.tested_at}</span>
                <div style="margin-top:4px">${this.formatTestData(r.result_data)}</div>
                ${r.memo ? `<div style="font-size:12px;color:var(--text-secondary);margin-top:4px">${r.memo}</div>` : ''}
              </div>
              <button class="btn btn-small btn-outline" onclick="App.pages['member-chart'].deleteTest(${r.id})">삭제</button>
            </div>
          </div>
        `).join('')
      }
    `;
  },

  formatTestData(data) {
    try {
      const parsed = typeof data === 'string' ? JSON.parse(data) : data;
      if (Array.isArray(parsed)) return parsed.join(', ');
      if (typeof parsed === 'object') {
        return Object.entries(parsed).map(([k,v]) => `${k}: ${v}`).join(' | ');
      }
      return String(parsed);
    } catch { return String(data || '-'); }
  },

  async showTestForm() {
    UI.showModal(`
      <div class="modal-title">테스트 결과 추가</div>
      <form id="testForm">
        <div class="form-group">
          <label class="form-label">테스트 유형</label>
          <select class="form-select" name="test_type" required>
            <option value="disc">DISC 진단</option>
            <option value="sensory">오감각 테스트</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">테스트 일자</label>
          <input class="form-input" type="date" name="tested_at" required>
        </div>
        <div class="form-group">
          <label class="form-label">결과 (JSON 또는 텍스트)</label>
          <textarea class="form-textarea" name="result_data" placeholder='예: {"D":35,"I":25,"S":20,"C":20}'></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">메모</label>
          <textarea class="form-textarea" name="memo"></textarea>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary" onclick="UI.closeModal()">취소</button>
          <button type="submit" class="btn btn-primary">저장</button>
        </div>
      </form>
    `);

    document.getElementById('testForm').addEventListener('submit', async e => {
      e.preventDefault();
      const fd = Object.fromEntries(new FormData(e.target));
      fd.member_id = this.memberId;
      try { fd.result_data = JSON.parse(fd.result_data); } catch { fd.result_data = fd.result_data; }
      const res = await API.post('/api/tests.php?action=create', fd);
      if (res.ok) { UI.closeModal(); this.switchTab('tests'); } else { alert(res.message); }
    });
  },

  async deleteTest(id) {
    if (!UI.confirm('이 테스트 결과를 삭제하시겠습니까?')) return;
    const res = await API.post(`/api/tests.php?action=delete&id=${id}`);
    if (res.ok) this.switchTab('tests'); else alert(res.message);
  },

  async loadNotes() {
    const res = await API.get(`/api/notes.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const notes = res.data.notes;

    document.getElementById('tabContent').innerHTML = `
      <div style="margin-bottom:16px">
        <form id="noteForm" style="display:flex;gap:10px">
          <input class="form-input" name="content" placeholder="메모를 입력하세요" style="flex:1" required>
          <button type="submit" class="btn btn-primary btn-small">추가</button>
        </form>
      </div>
      ${notes.length === 0 ? '<div class="empty-state">메모가 없습니다</div>' : notes.map(n => `
        <div style="padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.04)">
          <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
              <span class="badge badge-${n.author_type === 'admin' ? 'active' : '진행예정'}" style="margin-right:8px">${n.author_type === 'admin' ? '관리자' : '코치'}</span>
              <span style="font-size:12px;color:var(--text-secondary)">${n.author_name} | ${UI.formatDate(n.created_at)}</span>
            </div>
            <button class="btn btn-small btn-outline" onclick="App.pages['member-chart'].deleteNote(${n.id})">삭제</button>
          </div>
          <div style="margin-top:8px;font-size:14px">${n.content}</div>
        </div>
      `).join('')}
    `;

    document.getElementById('noteForm').addEventListener('submit', async e => {
      e.preventDefault();
      const content = new FormData(e.target).get('content');
      const res = await API.post('/api/notes.php?action=create', { member_id: this.memberId, content });
      if (res.ok) this.switchTab('notes'); else alert(res.message);
    });
  },

  async deleteNote(id) {
    if (!UI.confirm('이 메모를 삭제하시겠습니까?')) return;
    const res = await API.post(`/api/notes.php?action=delete&id=${id}`);
    if (res.ok) this.switchTab('notes'); else alert(res.message);
  },

  async loadLogs() {
    const res = await API.get(`/api/logs.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const logs = res.data.logs;

    document.getElementById('tabContent').innerHTML = logs.length === 0
      ? '<div class="empty-state">변경 이력이 없습니다</div>'
      : `<table class="data-table">
          <thead><tr><th>일시</th><th>대상</th><th>변경</th><th>이전</th><th>이후</th><th>변경자</th></tr></thead>
          <tbody>
            ${logs.map(l => `
              <tr>
                <td style="font-size:11px;color:var(--text-secondary);white-space:nowrap">${l.created_at}</td>
                <td style="font-size:12px">${l.target_type}</td>
                <td style="font-size:12px">${l.action}</td>
                <td style="font-size:11px;color:var(--text-secondary);max-width:150px;overflow:hidden;text-overflow:ellipsis">${l.old_value || '-'}</td>
                <td style="font-size:11px;max-width:150px;overflow:hidden;text-overflow:ellipsis">${l.new_value || '-'}</td>
                <td style="font-size:12px">${l.actor_name || l.actor_type}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>`;
  },
```

- [ ] **Step 5: Verify all tabs in browser**

Navigate to a member chart with orders. Check each tab:
- 코치이력: shows current coaches and change history
- 테스트결과: can add DISC/sensory results and they display
- 메모: can add notes, shows author and date
- 변경로그: shows status changes, coach changes from previous actions

- [ ] **Step 6: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/notes.php public_html/api/tests.php public_html/api/logs.php public_html/admin/js/pages/member-chart.js
git commit -m "feat: notes, test results, coach history, change logs — all chart tabs complete"
```

---

## Task 7: Merge (Member Consolidation)

**Files:**
- Create: `public_html/api/merge.php`
- Modify: `public_html/admin/js/pages/merge.js`
- Modify: `public_html/admin/js/pages/member-chart.js` (loadMergeInfo method)

- [ ] **Step 1: Create merge API**

Create `public_html/api/merge.php`:

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'suspects':
        // Find duplicate suspects by phone or email
        $stmt = $db->query("
            SELECT phone AS match_value, 'phone' AS match_type, GROUP_CONCAT(id) AS member_ids, COUNT(*) AS cnt
            FROM members
            WHERE phone IS NOT NULL AND phone != '' AND merged_into IS NULL
            GROUP BY phone HAVING cnt > 1
            UNION ALL
            SELECT email AS match_value, 'email' AS match_type, GROUP_CONCAT(id) AS member_ids, COUNT(*) AS cnt
            FROM members
            WHERE email IS NOT NULL AND email != '' AND merged_into IS NULL
            GROUP BY email HAVING cnt > 1
        ");
        $suspects = $stmt->fetchAll();

        // Deduplicate and enrich with member data
        $groups = [];
        $seen = [];
        foreach ($suspects as $s) {
            $ids = array_map('intval', explode(',', $s['member_ids']));
            sort($ids);
            $key = implode('-', $ids);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT id, name, phone, email FROM members WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $groups[] = [
                'match_type' => $s['match_type'],
                'match_value' => $s['match_value'],
                'members' => $stmt->fetchAll(),
            ];
        }
        jsonSuccess(['groups' => $groups]);

    case 'preview':
        $input = getJsonInput();
        $memberIds = $input['member_ids'] ?? [];
        $primaryId = (int)($input['primary_id'] ?? 0);

        if (count($memberIds) < 2 || !$primaryId) jsonError('2명 이상 선택하고 대표 계정을 지정하세요');
        if (!in_array($primaryId, $memberIds)) jsonError('대표 계정은 선택 목록에 포함되어야 합니다');

        $mergedIds = array_filter($memberIds, fn($id) => (int)$id !== $primaryId);
        $preview = ['primary' => null, 'absorbed' => [], 'data_counts' => []];

        // Primary member info
        $stmt = $db->prepare("SELECT id, name, phone, email FROM members WHERE id = ?");
        $stmt->execute([$primaryId]);
        $preview['primary'] = $stmt->fetch();

        foreach ($mergedIds as $mid) {
            $mid = (int)$mid;
            $stmt = $db->prepare("SELECT id, name, phone, email FROM members WHERE id = ?");
            $stmt->execute([$mid]);
            $member = $stmt->fetch();

            $counts = [];
            foreach (['orders','coach_assignments','test_results','member_notes','member_accounts'] as $table) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE member_id = ?");
                $stmt->execute([$mid]);
                $counts[$table] = (int)$stmt->fetchColumn();
            }
            $preview['absorbed'][] = ['member' => $member, 'counts' => $counts];
        }
        jsonSuccess(['preview' => $preview]);

    case 'execute':
        $input = getJsonInput();
        $primaryId = (int)($input['primary_id'] ?? 0);
        $mergedIds = array_map('intval', $input['merged_ids'] ?? []);

        if (!$primaryId || empty($mergedIds)) jsonError('대표 계정과 병합 대상을 지정하세요');

        $db->beginTransaction();

        foreach ($mergedIds as $mid) {
            // Collect moved record IDs
            $moved = [];
            $tables = ['orders','coach_assignments','test_results','member_notes','member_accounts'];
            foreach ($tables as $table) {
                $stmt = $db->prepare("SELECT id FROM {$table} WHERE member_id = ?");
                $stmt->execute([$mid]);
                $moved[$table] = array_column($stmt->fetchAll(), 'id');
            }

            // Get absorbed member data
            $stmt = $db->prepare("SELECT id, name, phone, email, memo FROM members WHERE id = ?");
            $stmt->execute([$mid]);
            $absorbedData = $stmt->fetch();

            // Move records
            foreach ($tables as $table) {
                if (!empty($moved[$table])) {
                    $db->prepare("UPDATE {$table} SET member_id = ? WHERE member_id = ?")->execute([$primaryId, $mid]);
                }
            }

            // Mark as merged
            $db->prepare("UPDATE members SET merged_into = ? WHERE id = ?")->execute([$primaryId, $mid]);

            // Log
            $db->prepare("INSERT INTO merge_logs (primary_member_id, merged_member_id, absorbed_member_data, moved_records, admin_id)
                VALUES (?, ?, ?, ?, ?)")->execute([
                $primaryId, $mid,
                json_encode($absorbedData, JSON_UNESCAPED_UNICODE),
                json_encode($moved, JSON_UNESCAPED_UNICODE),
                $admin['id'],
            ]);

            logChange($db, 'merge', $primaryId, 'member_merged',
                ['merged_member_id' => $mid], ['primary_member_id' => $primaryId],
                'admin', $admin['id']);
        }

        $db->commit();
        jsonSuccess([], count($mergedIds) . '명이 병합되었습니다');

    case 'history':
        $memberId = (int)($_GET['member_id'] ?? 0);
        if (!$memberId) jsonError('member_id가 필요합니다');
        $stmt = $db->prepare("
            SELECT ml.*, a.name AS admin_name
            FROM merge_logs ml
            JOIN admins a ON a.id = ml.admin_id
            WHERE ml.primary_member_id = ? OR ml.merged_member_id = ?
            ORDER BY ml.merged_at DESC
        ");
        $stmt->execute([$memberId, $memberId]);
        jsonSuccess(['history' => $stmt->fetchAll()]);

    case 'undo':
        $mergeLogId = (int)($_GET['id'] ?? 0);
        if (!$mergeLogId) jsonError('ID가 필요합니다');

        $stmt = $db->prepare("SELECT * FROM merge_logs WHERE id = ? AND unmerged_at IS NULL");
        $stmt->execute([$mergeLogId]);
        $log = $stmt->fetch();
        if (!$log) jsonError('병합 이력을 찾을 수 없습니다', 404);

        $movedRecords = json_decode($log['moved_records'], true);
        $mergedMemberId = (int)$log['merged_member_id'];
        $primaryMemberId = (int)$log['primary_member_id'];

        // Check for post-merge data
        $postMergeCount = 0;
        $tables = ['orders','coach_assignments','test_results','member_notes','member_accounts'];
        foreach ($tables as $table) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE member_id = ? AND created_at > ?");
            $stmt->execute([$primaryMemberId, $log['merged_at']]);
            $postMergeCount += (int)$stmt->fetchColumn();
        }

        if ($postMergeCount > 0 && empty($_GET['force'])) {
            jsonSuccess([
                'warning' => true,
                'post_merge_count' => $postMergeCount,
                'message' => "병합 후 추가된 데이터 {$postMergeCount}건이 있습니다. 이 데이터는 대표 회원에 유지됩니다.",
            ]);
            return;
        }

        $db->beginTransaction();

        // Move records back
        foreach ($tables as $table) {
            $ids = $movedRecords[$table] ?? [];
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge([$mergedMemberId], $ids);
                $db->prepare("UPDATE {$table} SET member_id = ? WHERE id IN ({$placeholders})")->execute($params);
            }
        }

        // Restore member
        $db->prepare("UPDATE members SET merged_into = NULL WHERE id = ?")->execute([$mergedMemberId]);
        $db->prepare("UPDATE merge_logs SET unmerged_at = NOW() WHERE id = ?")->execute([$mergeLogId]);

        logChange($db, 'merge', $primaryMemberId, 'member_unmerged',
            ['merged_member_id' => $mergedMemberId], null,
            'admin', $admin['id']);

        $db->commit();
        jsonSuccess([], '병합이 해제되었습니다');

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 2: Rewrite merge.js**

Rewrite `public_html/admin/js/pages/merge.js`:

```js
App.registerPage('merge', {
  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header">
        <h1 class="page-title">동일인관리</h1>
      </div>
      <div id="mergeList"><div class="loading">불러오는 중...</div></div>
    `;
    await this.loadSuspects();
  },

  async loadSuspects() {
    const res = await API.get('/api/merge.php?action=suspects');
    if (!res.ok) return;
    const groups = res.data.groups;

    if (groups.length === 0) {
      document.getElementById('mergeList').innerHTML =
        '<div class="empty-state">동일인 의심 건이 없습니다</div>';
      return;
    }

    document.getElementById('mergeList').innerHTML = groups.map((g, gi) => `
      <div class="card" style="margin-bottom:16px">
        <div style="margin-bottom:12px">
          <span class="badge badge-매칭대기">${g.match_type === 'phone' ? '전화번호' : '이메일'}</span>
          <span style="margin-left:8px;color:var(--text-secondary)">${g.match_value}</span>
        </div>
        <form id="mergeGroup${gi}">
          ${g.members.map(m => `
            <label style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.04);cursor:pointer">
              <input type="checkbox" name="member_ids" value="${m.id}" checked>
              <input type="radio" name="primary_id" value="${m.id}">
              <span>${m.name}</span>
              <span style="color:var(--text-secondary);font-size:12px">${m.phone || '-'} | ${m.email || '-'}</span>
              <span style="font-size:11px;color:var(--text-secondary)">ID:${m.id}</span>
            </label>
          `).join('')}
          <div style="margin-top:12px;display:flex;gap:10px;align-items:center">
            <span style="font-size:12px;color:var(--text-secondary)">대표 계정을 선택(라디오) 후</span>
            <button type="button" class="btn btn-small btn-primary" onclick="App.pages.merge.doMerge(${gi})">병합</button>
          </div>
        </form>
      </div>
    `).join('');
  },

  async doMerge(groupIdx) {
    const form = document.getElementById(`mergeGroup${groupIdx}`);
    const checked = [...form.querySelectorAll('input[name="member_ids"]:checked')].map(i => parseInt(i.value));
    const primary = form.querySelector('input[name="primary_id"]:checked');

    if (checked.length < 2) { alert('2명 이상 선택하세요'); return; }
    if (!primary) { alert('대표 계정을 선택하세요'); return; }

    const primaryId = parseInt(primary.value);

    // Preview
    const preview = await API.post('/api/merge.php?action=preview', { member_ids: checked, primary_id: primaryId });
    if (!preview.ok) { alert(preview.message); return; }

    const p = preview.data.preview;
    const absorbedInfo = p.absorbed.map(a => {
      const c = a.counts;
      const total = Object.values(c).reduce((s,v) => s+v, 0);
      return `${a.member.name} (데이터 ${total}건)`;
    }).join(', ');

    if (!UI.confirm(`대표: ${p.primary.name}\n흡수: ${absorbedInfo}\n\n병합하시겠습니까?`)) return;

    const mergedIds = checked.filter(id => id !== primaryId);
    const res = await API.post('/api/merge.php?action=execute', { primary_id: primaryId, merged_ids: mergedIds });
    if (res.ok) {
      alert(res.message);
      await this.loadSuspects();
    } else {
      alert(res.message);
    }
  },
});
```

- [ ] **Step 3: Add loadMergeInfo to member-chart.js**

Replace the `loadMergeInfo` method:

```js
  async loadMergeInfo() {
    const [historyRes, memberRes] = await Promise.all([
      API.get(`/api/merge.php?action=history&member_id=${this.memberId}`),
      API.get(`/api/members.php?action=get&id=${this.memberId}`),
    ]);

    const history = historyRes.ok ? historyRes.data.history : [];
    const accounts = memberRes.ok ? memberRes.data.member.accounts : [];

    document.getElementById('tabContent').innerHTML = `
      <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">연결 계정</h3>
      ${accounts.length === 0 ? '<div style="color:var(--text-secondary);margin-bottom:20px">연결 계정 없음</div>' : `
        <table class="data-table" style="margin-bottom:24px">
          <thead><tr><th>출처</th><th>ID</th><th>이름</th><th>전화</th><th>이메일</th><th>대표</th></tr></thead>
          <tbody>
            ${accounts.map(a => `
              <tr>
                <td>${a.source}</td>
                <td style="color:var(--text-secondary)">${a.source_id || '-'}</td>
                <td>${a.name || '-'}</td>
                <td style="color:var(--text-secondary)">${a.phone || '-'}</td>
                <td style="color:var(--text-secondary)">${a.email || '-'}</td>
                <td>${a.is_primary ? '<span style="color:var(--accent)">대표</span>' : ''}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `}

      <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">병합 이력</h3>
      ${history.length === 0 ? '<div class="empty-state">병합 이력이 없습니다</div>' : `
        <table class="data-table">
          <thead><tr><th>일시</th><th>유형</th><th>대상</th><th>관리자</th><th>상태</th><th></th></tr></thead>
          <tbody>
            ${history.map(h => {
              const absorbed = JSON.parse(h.absorbed_member_data || '{}');
              const isMerged = !h.unmerged_at;
              return `
                <tr>
                  <td style="font-size:12px;color:var(--text-secondary)">${UI.formatDate(h.merged_at)}</td>
                  <td>${h.primary_member_id == this.memberId ? '흡수' : '흡수됨'}</td>
                  <td>${absorbed.name || '?'} (ID:${h.merged_member_id})</td>
                  <td style="font-size:12px">${h.admin_name}</td>
                  <td>${isMerged ? UI.statusBadge('진행중') : '<span style="color:var(--text-secondary)">해제됨</span>'}</td>
                  <td>${isMerged && h.primary_member_id == this.memberId
                    ? `<button class="btn btn-small btn-outline" onclick="App.pages['member-chart'].undoMerge(${h.id})">해제</button>`
                    : ''}</td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      `}
    `;
  },

  async undoMerge(mergeLogId) {
    // First check for warnings
    const check = await API.get(`/api/merge.php?action=undo&id=${mergeLogId}`);
    if (check.ok && check.data.warning) {
      if (!UI.confirm(check.data.message + '\n\n계속하시겠습니까?')) return;
      // Force undo
      const res = await API.get(`/api/merge.php?action=undo&id=${mergeLogId}&force=1`);
      if (res.ok) { alert(res.message); await this.render([this.memberId]); }
      else alert(res.message);
    } else if (check.ok) {
      alert(check.message);
      await this.render([this.memberId]);
    } else {
      alert(check.message);
    }
  },
```

- [ ] **Step 4: Verify merge flow in browser**

Create 2 test members with the same phone number. Navigate to #merge — should show them as suspects. Select both, choose primary, click merge. Verify merged member disappears from member list. Check merge info tab on the primary member. Test undo.

- [ ] **Step 5: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/merge.php public_html/admin/js/pages/merge.js public_html/admin/js/pages/member-chart.js
git commit -m "feat: member merge — duplicate detection, merge/undo, merge info tab"
```

---

## Task 8: Spreadsheet Import

**Files:**
- Create: `public_html/api/import.php`
- Modify: `public_html/admin/js/pages/import.js`

- [ ] **Step 1: Create import API**

Create `public_html/api/import.php`:

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'upload':
        if (empty($_FILES['file'])) jsonError('파일을 선택하세요');
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'tsv', 'txt'])) jsonError('CSV 또는 TSV 파일만 지원합니다');

        $batchId = date('Ymd-His') . '-' . substr(uniqid(), -6);
        $destDir = __DIR__ . '/../uploads/imports/';
        $destPath = $destDir . $batchId . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            jsonError('파일 업로드에 실패했습니다');
        }

        // Parse file
        $rows = [];
        $handle = fopen($destPath, 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $delimiter = $ext === 'tsv' ? "\t" : ',';
        $headers = fgetcsv($handle, 0, $delimiter);
        $headers = array_map('trim', $headers);

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) === count($headers)) {
                $rows[] = array_combine($headers, array_map('trim', $row));
            }
        }
        fclose($handle);

        jsonSuccess([
            'batch_id' => $batchId,
            'headers' => $headers,
            'row_count' => count($rows),
            'sample' => array_slice($rows, 0, 5),
        ], '파일이 업로드되었습니다');

    case 'import_members':
        $input = getJsonInput();
        $batchId = $input['batch_id'] ?? '';
        if (!$batchId) jsonError('batch_id가 필요합니다');

        // Check duplicate batch
        $stmt = $db->prepare("SELECT 1 FROM migration_logs WHERE batch_id = ? LIMIT 1");
        $stmt->execute([$batchId]);
        if ($stmt->fetch()) jsonError('이미 처리된 배치입니다');

        // Re-read file
        $files = glob(__DIR__ . "/../uploads/imports/{$batchId}.*");
        if (empty($files)) jsonError('파일을 찾을 수 없습니다');
        $filePath = $files[0];
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $delimiter = $ext === 'tsv' ? "\t" : ',';

        $handle = fopen($filePath, 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);
        $headers = array_map('trim', fgetcsv($handle, 0, $delimiter));

        $stats = ['success' => 0, 'skipped' => 0, 'error' => 0];
        $rowNum = 1;

        while (($raw = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;
            if (count($raw) !== count($headers)) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, '컬럼 수 불일치']);
                $stats['error']++;
                continue;
            }
            $row = array_combine($headers, array_map('trim', $raw));

            $name = $row['이름'] ?? $row['name'] ?? '';
            if (!$name) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, '이름 누락']);
                $stats['error']++;
                continue;
            }

            $phone = normalizePhone($row['전화번호'] ?? $row['phone'] ?? null);
            $email = $row['이메일'] ?? $row['email'] ?? null;
            $sorituneId = $row['soritune_id'] ?? $row['Soritune ID'] ?? '';
            $memo = $row['메모'] ?? $row['memo'] ?? '';

            // Check for existing member (natural key: soritune_id or name+phone)
            $existingId = null;
            if ($sorituneId) {
                $stmt = $db->prepare("SELECT ma.member_id FROM member_accounts ma WHERE ma.source = 'soritune' AND ma.source_id = ?");
                $stmt->execute([$sorituneId]);
                $existingId = $stmt->fetchColumn() ?: null;
            }
            if (!$existingId && $phone && $name) {
                $stmt = $db->prepare("SELECT id FROM members WHERE name = ? AND phone = ? AND merged_into IS NULL");
                $stmt->execute([$name, $phone]);
                $existingId = $stmt->fetchColumn() ?: null;
            }

            if ($existingId) {
                // Add account to existing member
                $db->prepare("INSERT INTO member_accounts (member_id, source, source_id, name, phone, email)
                    VALUES (?, 'import', ?, ?, ?, ?)")->execute([$existingId, $sorituneId ?: null, $name, $phone, $email]);
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, target_table, target_id, status, message)
                    VALUES (?, 'spreadsheet', ?, 'member_accounts', ?, 'success', ?)")
                    ->execute([$batchId, $rowNum, (int)$db->lastInsertId(), '기존 회원에 계정 추가']);
                $stats['success']++;
                continue;
            }

            // Create new member
            $db->prepare("INSERT INTO members (name, phone, email, memo) VALUES (?, ?, ?, ?)")
                ->execute([$name, $phone, $email ?: null, $memo ?: null]);
            $memberId = (int)$db->lastInsertId();

            // Primary account
            $db->prepare("INSERT INTO member_accounts (member_id, source, source_id, name, phone, email, is_primary)
                VALUES (?, 'import', ?, ?, ?, ?, 1)")
                ->execute([$memberId, $sorituneId ?: null, $name, $phone, $email]);

            // Soritune account if ID provided
            if ($sorituneId) {
                $db->prepare("INSERT INTO member_accounts (member_id, source, source_id, name, phone, email)
                    VALUES (?, 'soritune', ?, ?, ?, ?)")
                    ->execute([$memberId, $sorituneId, $name, $phone, $email]);
            }

            $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, target_table, target_id, status, message)
                VALUES (?, 'spreadsheet', ?, 'members', ?, 'success', ?)")
                ->execute([$batchId, $rowNum, $memberId, '신규 회원 생성']);
            $stats['success']++;
        }
        fclose($handle);

        jsonSuccess(['stats' => $stats], "처리 완료: 성공 {$stats['success']} / 스킵 {$stats['skipped']} / 에러 {$stats['error']}");

    case 'import_orders':
        $input = getJsonInput();
        $batchId = $input['batch_id'] ?? '';
        if (!$batchId) jsonError('batch_id가 필요합니다');

        $stmt = $db->prepare("SELECT 1 FROM migration_logs WHERE batch_id = ? LIMIT 1");
        $stmt->execute([$batchId]);
        if ($stmt->fetch()) jsonError('이미 처리된 배치입니다');

        $files = glob(__DIR__ . "/../uploads/imports/{$batchId}.*");
        if (empty($files)) jsonError('파일을 찾을 수 없습니다');
        $filePath = $files[0];
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $delimiter = $ext === 'tsv' ? "\t" : ',';

        $handle = fopen($filePath, 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);
        $headers = array_map('trim', fgetcsv($handle, 0, $delimiter));

        $stats = ['success' => 0, 'skipped' => 0, 'error' => 0];
        $rowNum = 1;

        while (($raw = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;
            if (count($raw) !== count($headers)) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, '컬럼 수 불일치']);
                $stats['error']++;
                continue;
            }
            $row = array_combine($headers, array_map('trim', $raw));

            $memberName = $row['회원이름'] ?? $row['member_name'] ?? '';
            $memberPhone = normalizePhone($row['전화번호'] ?? $row['phone'] ?? null);
            $productName = $row['상품명'] ?? $row['product_name'] ?? '';
            $startDate = $row['시작일'] ?? $row['start_date'] ?? '';

            if (!$memberName || !$productName || !$startDate) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, '필수 필드 누락']);
                $stats['error']++;
                continue;
            }

            // Match member
            $memberId = null;
            if ($memberPhone) {
                $stmt = $db->prepare("SELECT id FROM members WHERE phone = ? AND merged_into IS NULL LIMIT 1");
                $stmt->execute([$memberPhone]);
                $memberId = $stmt->fetchColumn() ?: null;
            }
            if (!$memberId) {
                $stmt = $db->prepare("SELECT id FROM members WHERE name = ? AND merged_into IS NULL LIMIT 1");
                $stmt->execute([$memberName]);
                $memberId = $stmt->fetchColumn() ?: null;
            }
            if (!$memberId) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, "회원 매칭 실패: {$memberName}"]);
                $stats['error']++;
                continue;
            }

            // Check duplicate (natural key: member_id + product_name + start_date)
            $stmt = $db->prepare("SELECT 1 FROM orders WHERE member_id = ? AND product_name = ? AND start_date = ?");
            $stmt->execute([$memberId, $productName, $startDate]);
            if ($stmt->fetch()) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'skipped', ?)")->execute([$batchId, $rowNum, '중복 주문']);
                $stats['skipped']++;
                continue;
            }

            // Match coach
            $coachName = $row['코치명(영문)'] ?? $row['coach_name'] ?? '';
            $coachId = null;
            if ($coachName) {
                $stmt = $db->prepare("SELECT id FROM coaches WHERE coach_name = ?");
                $stmt->execute([$coachName]);
                $coachId = $stmt->fetchColumn() ?: null;
                if (!$coachId) {
                    $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                        VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, "코치 매칭 실패: {$coachName}"]);
                    $stats['error']++;
                    continue;
                }
            }

            $productTypeRaw = $row['상품유형(기간/횟수)'] ?? $row['product_type'] ?? '기간';
            $productType = (str_contains($productTypeRaw, '횟수') || $productTypeRaw === 'count') ? 'count' : 'period';
            $endDate = $row['종료일'] ?? $row['end_date'] ?? $startDate;
            $totalSessions = $productType === 'count' ? (int)($row['총횟수'] ?? $row['total_sessions'] ?? 0) : null;
            $amount = (int)str_replace([',', '원', ' '], '', $row['금액'] ?? $row['amount'] ?? '0');
            $statusRaw = $row['상태'] ?? $row['status'] ?? '매칭대기';
            $validStatuses = ['매칭대기','매칭완료','진행중','연기','중단','환불','종료'];
            $status = in_array($statusRaw, $validStatuses) ? $statusRaw : '매칭대기';
            $memo = $row['메모'] ?? $row['memo'] ?? '';

            // Validate dates
            if (!strtotime($startDate) || !strtotime($endDate)) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, '날짜 형식 오류']);
                $stats['error']++;
                continue;
            }

            $db->prepare("INSERT INTO orders (member_id, coach_id, product_name, product_type, start_date, end_date, total_sessions, amount, status, memo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$memberId, $coachId, $productName, $productType, $startDate, $endDate, $totalSessions, $amount, $status, $memo ?: null]);
            $orderId = (int)$db->lastInsertId();

            // Create sessions for count type
            if ($productType === 'count' && $totalSessions > 0) {
                $usedSessions = (int)($row['소진횟수'] ?? $row['used_sessions'] ?? 0);
                $stmtSession = $db->prepare("INSERT INTO order_sessions (order_id, session_number, completed_at) VALUES (?, ?, ?)");
                for ($i = 1; $i <= $totalSessions; $i++) {
                    $completedAt = $i <= $usedSessions ? date('Y-m-d H:i:s') : null;
                    $stmtSession->execute([$orderId, $i, $completedAt]);
                }
            }

            // Create coach assignment
            if ($coachId) {
                $db->prepare("INSERT INTO coach_assignments (member_id, coach_id, order_id) VALUES (?, ?, ?)")
                    ->execute([$memberId, $coachId, $orderId]);
            }

            $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, target_table, target_id, status, message)
                VALUES (?, 'spreadsheet', ?, 'orders', ?, 'success', ?)")
                ->execute([$batchId, $rowNum, $orderId, '주문 생성']);
            $stats['success']++;
        }
        fclose($handle);

        jsonSuccess(['stats' => $stats], "처리 완료: 성공 {$stats['success']} / 스킵 {$stats['skipped']} / 에러 {$stats['error']}");

    case 'batches':
        $stmt = $db->query("
            SELECT batch_id,
              MIN(created_at) AS imported_at,
              SUM(status = 'success') AS success_count,
              SUM(status = 'skipped') AS skipped_count,
              SUM(status = 'error') AS error_count,
              COUNT(*) AS total_count
            FROM migration_logs
            GROUP BY batch_id
            ORDER BY MIN(created_at) DESC
        ");
        jsonSuccess(['batches' => $stmt->fetchAll()]);

    case 'batch_errors':
        $batchId = $_GET['batch_id'] ?? '';
        $stmt = $db->prepare("SELECT * FROM migration_logs WHERE batch_id = ? AND status IN ('error','skipped') ORDER BY source_row");
        $stmt->execute([$batchId]);
        jsonSuccess(['errors' => $stmt->fetchAll()]);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 2: Rewrite import.js**

Rewrite `public_html/admin/js/pages/import.js`:

```js
App.registerPage('import', {
  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">데이터관리</h1></div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:32px">
        <div class="card">
          <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">회원 Import</h3>
          <p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px">CSV/TSV 파일. 컬럼: 이름, 전화번호, 이메일, soritune_id, 메모</p>
          <input type="file" id="memberFile" accept=".csv,.tsv,.txt" style="display:none" onchange="App.pages.import.uploadFile('members')">
          <button class="btn btn-primary btn-small" onclick="document.getElementById('memberFile').click()">파일 선택</button>
          <div id="memberUploadResult"></div>
        </div>
        <div class="card">
          <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">PT이력 Import</h3>
          <p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px">CSV/TSV 파일. 컬럼: 회원이름, 전화번호, 상품명, 상품유형, 코치명(영문), 시작일, 종료일, 총횟수, 소진횟수, 금액, 상태, 메모</p>
          <input type="file" id="orderFile" accept=".csv,.tsv,.txt" style="display:none" onchange="App.pages.import.uploadFile('orders')">
          <button class="btn btn-primary btn-small" onclick="document.getElementById('orderFile').click()">파일 선택</button>
          <div id="orderUploadResult"></div>
        </div>
      </div>

      <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">Import 기록</h3>
      <div id="batchList"><div class="loading">불러오는 중...</div></div>
    `;
    await this.loadBatches();
  },

  async uploadFile(type) {
    const fileInput = type === 'members' ? document.getElementById('memberFile') : document.getElementById('orderFile');
    const resultDiv = type === 'members' ? document.getElementById('memberUploadResult') : document.getElementById('orderUploadResult');
    const file = fileInput.files[0];
    if (!file) return;

    const fd = new FormData();
    fd.append('file', file);

    resultDiv.innerHTML = '<div class="loading">업로드 중...</div>';
    const res = await API.upload('/api/import.php?action=upload', fd);
    if (!res.ok) { resultDiv.innerHTML = `<div style="color:var(--negative);margin-top:8px">${res.message}</div>`; return; }

    const d = res.data;
    resultDiv.innerHTML = `
      <div style="margin-top:12px">
        <div style="font-size:12px;color:var(--text-secondary)">컬럼: ${d.headers.join(', ')}</div>
        <div style="font-size:13px;margin:8px 0">${d.row_count}행 감지</div>
        <button class="btn btn-primary btn-small" onclick="App.pages.import.executeImport('${type}', '${d.batch_id}')">
          IMPORT 실행
        </button>
      </div>
    `;
    fileInput.value = '';
  },

  async executeImport(type, batchId) {
    if (!UI.confirm(`${type === 'members' ? '회원' : 'PT이력'} 데이터를 import하시겠습니까?`)) return;

    const action = type === 'members' ? 'import_members' : 'import_orders';
    const res = await API.post(`/api/import.php?action=${action}`, { batch_id: batchId });

    if (res.ok) {
      const s = res.data.stats;
      alert(`처리 완료\n성공: ${s.success}\n스킵: ${s.skipped}\n에러: ${s.error}`);
      await this.render();
    } else {
      alert(res.message);
    }
  },

  async loadBatches() {
    const res = await API.get('/api/import.php?action=batches');
    if (!res.ok) return;
    const batches = res.data.batches;

    if (batches.length === 0) {
      document.getElementById('batchList').innerHTML = '<div class="empty-state">아직 import 기록이 없습니다</div>';
      return;
    }

    document.getElementById('batchList').innerHTML = `
      <table class="data-table">
        <thead><tr><th>Batch ID</th><th>일시</th><th>성공</th><th>스킵</th><th>에러</th><th></th></tr></thead>
        <tbody>
          ${batches.map(b => `
            <tr>
              <td style="font-size:12px">${b.batch_id}</td>
              <td style="font-size:12px;color:var(--text-secondary)">${UI.formatDate(b.imported_at)}</td>
              <td style="color:var(--success)">${b.success_count}</td>
              <td style="color:var(--warning)">${b.skipped_count}</td>
              <td style="color:${b.error_count > 0 ? 'var(--negative)' : 'var(--text-secondary)'}">${b.error_count}</td>
              <td>${b.error_count > 0 || b.skipped_count > 0
                ? `<button class="btn btn-small btn-outline" onclick="App.pages.import.showErrors('${b.batch_id}')">상세</button>` : ''}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  },

  async showErrors(batchId) {
    const res = await API.get(`/api/import.php?action=batch_errors&batch_id=${batchId}`);
    if (!res.ok) return;
    const errors = res.data.errors;

    UI.showModal(`
      <div class="modal-title">Import 상세 — ${batchId}</div>
      <div style="max-height:400px;overflow-y:auto">
        <table class="data-table">
          <thead><tr><th>행</th><th>상태</th><th>사유</th></tr></thead>
          <tbody>
            ${errors.map(e => `
              <tr>
                <td>${e.source_row}</td>
                <td>${UI.statusBadge(e.status === 'error' ? '환불' : '연기')}</td>
                <td style="font-size:12px">${e.message}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
      <div class="modal-actions">
        <button class="btn btn-secondary" onclick="UI.closeModal()">닫기</button>
      </div>
    `);
  },
});
```

- [ ] **Step 3: Verify import in browser**

Create a test CSV file, upload as member import, verify members appear. Create a test orders CSV, import, verify orders appear in member charts.

- [ ] **Step 4: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/import.php public_html/admin/js/pages/import.js
git commit -m "feat: spreadsheet import — member and order import with dedup and error logging"
```

---

## Task 9: Coach Frontend

**Files:**
- Create: `public_html/coach/index.php`
- Create: `public_html/coach/js/app.js`
- Create: `public_html/coach/js/pages/my-members.js`
- Create: `public_html/coach/js/pages/member-chart.js`

- [ ] **Step 1: Create coach SPA shell**

Create `public_html/coach/index.php`:

```php
<?php
require_once __DIR__ . '/../includes/auth.php';
$user = getCurrentUser();
$isLoggedIn = $user && $user['role'] === 'coach';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SoriTune PT — Coach</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<?php if (!$isLoggedIn): ?>
<div class="login-wrapper">
  <div class="login-card">
    <div class="login-logo">SoriTune PT</div>
    <div class="login-subtitle">코치 로그인</div>
    <div class="login-error" id="loginError"></div>
    <form id="loginForm">
      <div class="form-group">
        <input type="text" class="form-input" id="loginId" placeholder="아이디" autocomplete="username">
      </div>
      <div class="form-group">
        <input type="password" class="form-input" id="loginPw" placeholder="비밀번호" autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px">LOGIN</button>
    </form>
  </div>
</div>
<script>
document.getElementById('loginForm').addEventListener('submit', async e => {
  e.preventDefault();
  const err = document.getElementById('loginError');
  err.style.display = 'none';
  const res = await fetch('/api/auth.php?action=login', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
      login_id: document.getElementById('loginId').value,
      password: document.getElementById('loginPw').value,
      role: 'coach'
    })
  });
  const data = await res.json();
  if (data.ok) { location.reload(); }
  else { err.textContent = data.message; err.style.display = 'block'; }
});
</script>

<?php else: ?>
<div class="app-layout">
  <aside class="sidebar">
    <div class="sidebar-logo">SoriTune PT</div>
    <nav class="sidebar-nav">
      <a href="#my-members" data-page="my-members">내 회원</a>
    </nav>
  </aside>
  <main class="main-content">
    <div class="topbar">
      <div></div>
      <div class="topbar-user">
        <span><?= htmlspecialchars($user['name']) ?> (코치)</span>
        <button class="btn btn-small btn-outline" onclick="logout()">LOGOUT</button>
      </div>
    </div>
    <div id="pageContent"></div>
  </main>
</div>

<script src="/coach/js/app.js"></script>
<script src="/coach/js/pages/my-members.js"></script>
<script src="/coach/js/pages/member-chart.js"></script>
<script>CoachApp.init();</script>
<?php endif; ?>

</body>
</html>
```

- [ ] **Step 2: Create coach app.js**

Create `public_html/coach/js/app.js`:

```js
/**
 * Coach SPA — reuses API/UI helpers pattern from admin
 */
const CoachApp = {
  currentPage: null,
  pages: {},
  init() {
    window.addEventListener('hashchange', () => this.route());
    this.route();
  },
  route() {
    const hash = location.hash.slice(1) || 'my-members';
    const [page, ...params] = hash.split('/');
    document.querySelectorAll('.sidebar-nav a').forEach(a => {
      a.classList.toggle('active', a.dataset.page === page);
    });
    this.currentPage = page;
    const handler = this.pages[page];
    if (handler) handler.render(params);
    else document.getElementById('pageContent').innerHTML = '<div class="empty-state">페이지를 찾을 수 없습니다</div>';
  },
  registerPage(name, handler) { this.pages[name] = handler; },
};

// Reuse API and UI helpers (same as admin)
const API = {
  async request(url, options = {}) {
    const res = await fetch(url, { headers: { 'Content-Type': 'application/json', ...options.headers }, ...options });
    const data = await res.json();
    if (!data.ok && res.status === 401) location.reload();
    return data;
  },
  get(url) { return this.request(url); },
  post(url, body) { return this.request(url, { method: 'POST', body: JSON.stringify(body) }); },
};

const UI = {
  statusBadge(s) { return `<span class="badge badge-${s}">${s}</span>`; },
  formatDate(d) { return d ? d.split(' ')[0] : '-'; },
  formatMoney(a) { return Number(a||0).toLocaleString() + '원'; },
  showModal(html) {
    const o = document.createElement('div'); o.className = 'modal-overlay';
    o.innerHTML = `<div class="modal">${html}</div>`;
    o.addEventListener('click', e => { if (e.target === o) o.remove(); });
    document.body.appendChild(o); return o;
  },
  closeModal() { document.querySelector('.modal-overlay')?.remove(); },
  confirm(m) { return window.confirm(m); },
};

async function logout() {
  await API.post('/api/auth.php?action=logout');
  location.reload();
}
```

- [ ] **Step 3: Create my-members.js**

Create `public_html/coach/js/pages/my-members.js`:

```js
CoachApp.registerPage('my-members', {
  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">내 회원</h1></div>
      <div id="myMemberList"><div class="loading">불러오는 중...</div></div>
    `;

    const res = await API.get('/api/members.php?action=list');
    if (!res.ok) return;
    const members = res.data.members;

    if (members.length === 0) {
      document.getElementById('myMemberList').innerHTML = '<div class="empty-state">현재 담당 회원이 없습니다</div>';
      return;
    }

    document.getElementById('myMemberList').innerHTML = `
      <table class="data-table">
        <thead><tr><th>이름</th><th>전화번호</th><th>상태</th><th>PT건수</th><th></th></tr></thead>
        <tbody>
          ${members.map(m => `
            <tr style="cursor:pointer" onclick="location.hash='member-chart/${m.id}'">
              <td>${m.name}</td>
              <td style="color:var(--text-secondary)">${m.phone || '-'}</td>
              <td>${UI.statusBadge(m.display_status)}</td>
              <td>${m.order_count}</td>
              <td><span style="color:var(--text-secondary)">→</span></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  },
});
```

- [ ] **Step 4: Create coach member-chart.js**

Create `public_html/coach/js/pages/member-chart.js`:

```js
/**
 * Coach Member Chart — restricted version
 */
CoachApp.registerPage('member-chart', {
  memberId: null,
  member: null,

  async render(params) {
    this.memberId = parseInt(params[0]);
    if (!this.memberId) { location.hash = 'my-members'; return; }

    document.getElementById('pageContent').innerHTML = '<div class="loading">불러오는 중...</div>';
    const res = await API.get(`/api/members.php?action=get&id=${this.memberId}`);
    if (!res.ok) {
      document.getElementById('pageContent').innerHTML = `<div class="empty-state">${res.message}</div>`;
      return;
    }
    this.member = res.data.member;
    this.renderChart();
  },

  renderChart() {
    const m = this.member;
    const coaches = m.current_coaches?.map(c => c.coach_name).join(', ') || '-';

    document.getElementById('pageContent').innerHTML = `
      <div style="margin-bottom:16px">
        <a href="#my-members" style="color:var(--text-secondary);text-decoration:none;font-size:13px">← 내 회원</a>
      </div>

      <div class="card card-elevated" style="margin-bottom:20px">
        <h2 style="font-size:20px;font-weight:700;margin-bottom:16px">${m.name}</h2>
        <div class="info-grid">
          <div><div class="info-item-label">전화번호</div><div class="info-item-value">${m.phone||'-'}</div></div>
          <div><div class="info-item-label">이메일</div><div class="info-item-value">${m.email||'-'}</div></div>
          <div><div class="info-item-label">상태</div><div class="info-item-value">${UI.statusBadge(m.display_status)}</div></div>
          <div><div class="info-item-label">담당 코치</div><div class="info-item-value">${coaches}</div></div>
        </div>
      </div>

      <div id="ptProgressSection"></div>

      <div class="tabs">
        <button class="tab-btn active" onclick="CoachApp.pages['member-chart'].loadOrders()">PT이력</button>
        <button class="tab-btn" onclick="CoachApp.pages['member-chart'].loadNotes()">메모</button>
        <button class="tab-btn" onclick="CoachApp.pages['member-chart'].loadTests()">테스트결과</button>
        <button class="tab-btn" onclick="CoachApp.pages['member-chart'].loadLogs()">변경로그</button>
      </div>
      <div id="tabContent"></div>
    `;

    this.loadPtProgress();
    this.loadOrders();
  },

  async loadPtProgress() {
    const res = await API.get(`/api/orders.php?action=active&member_id=${this.memberId}`);
    if (!res.ok || res.data.orders.length === 0) {
      document.getElementById('ptProgressSection').innerHTML = '';
      return;
    }
    const orders = res.data.orders;
    document.getElementById('ptProgressSection').innerHTML = `
      <div class="card" style="margin-bottom:20px;background:var(--surface-card)">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">진행 중인 PT</h3>
        ${orders.map(o => this.renderProgress(o)).join('')}
      </div>
    `;
  },

  renderProgress(o) {
    if (o.product_type === 'period') {
      const today = new Date(), start = new Date(o.start_date), end = new Date(o.end_date);
      const pct = Math.min(100, Math.round(((today-start)/(end-start))*100));
      const rem = Math.max(0, Math.ceil((end-today)/86400000));
      return `<div class="pt-progress-card">
        <div class="pt-progress-header"><span class="pt-progress-title">${o.product_name} (기간형)</span><span class="pt-progress-coach">${o.coach_name||'-'}</span></div>
        <div class="pt-progress-meta">${o.start_date} ~ ${o.end_date} | 남은 일수: ${rem}일</div>
        <div class="progress-bar"><div class="progress-fill" style="width:${pct}%"></div></div>
      </div>`;
    }
    const used = parseInt(o.used_sessions)||0, total = parseInt(o.total_sessions)||1;
    const sessions = o.sessions||[];
    return `<div class="pt-progress-card">
      <div class="pt-progress-header"><span class="pt-progress-title">${o.product_name} (횟수형)</span><span class="pt-progress-coach">${o.coach_name||'-'}</span></div>
      <div class="pt-progress-meta">${o.start_date} ~ ${o.end_date} | ${used}/${total}회</div>
      <div class="progress-bar"><div class="progress-fill" style="width:${Math.round(used/total*100)}%"></div></div>
      <ul class="session-list">${sessions.map(s => `
        <li class="session-item">
          <button class="session-check ${s.completed_at?'done':''}" onclick="CoachApp.pages['member-chart'].toggleSession(${s.id})">${s.completed_at?'&#10003;':''}</button>
          <span>${s.session_number}회차</span>
          <span class="session-date">${s.completed_at ? UI.formatDate(s.completed_at)+' 완료' : '-'}</span>
        </li>`).join('')}</ul>
    </div>`;
  },

  async toggleSession(sessionId) {
    const res = await API.post(`/api/orders.php?action=complete_session&session_id=${sessionId}`);
    if (res.ok) await this.render([this.memberId]);
    else alert(res.message);
  },

  async loadOrders() {
    document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.toggle('active', i===0));
    const res = await API.get(`/api/orders.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const orders = res.data.orders;
    document.getElementById('tabContent').innerHTML = orders.length === 0
      ? '<div class="empty-state">PT 이력이 없습니다</div>'
      : `<table class="data-table"><thead><tr><th>상품명</th><th>유형</th><th>코치</th><th>기간</th><th>상태</th></tr></thead><tbody>
          ${orders.map(o => `<tr><td>${o.product_name}</td><td>${o.product_type==='period'?'기간형':'횟수형'}</td><td>${o.coach_name||'-'}</td>
          <td style="font-size:12px;color:var(--text-secondary)">${o.start_date}~${o.end_date}</td><td>${UI.statusBadge(o.status)}</td></tr>`).join('')}
        </tbody></table>`;
  },

  async loadNotes() {
    document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.toggle('active', i===1));
    const res = await API.get(`/api/notes.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const notes = res.data.notes;
    document.getElementById('tabContent').innerHTML = `
      <div style="margin-bottom:16px">
        <form id="noteForm" style="display:flex;gap:10px">
          <input class="form-input" name="content" placeholder="메모를 입력하세요" style="flex:1" required>
          <button type="submit" class="btn btn-primary btn-small">추가</button>
        </form>
      </div>
      ${notes.length === 0 ? '<div class="empty-state">메모가 없습니다</div>' : notes.map(n => `
        <div style="padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.04)">
          <span class="badge badge-${n.author_type==='admin'?'active':'진행예정'}">${n.author_type==='admin'?'관리자':'코치'}</span>
          <span style="font-size:12px;color:var(--text-secondary);margin-left:8px">${n.author_name} | ${UI.formatDate(n.created_at)}</span>
          <div style="margin-top:8px">${n.content}</div>
        </div>
      `).join('')}`;
    document.getElementById('noteForm')?.addEventListener('submit', async e => {
      e.preventDefault();
      const content = new FormData(e.target).get('content');
      const res = await API.post('/api/notes.php?action=create', { member_id: this.memberId, content });
      if (res.ok) this.loadNotes(); else alert(res.message);
    });
  },

  async loadTests() {
    document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.toggle('active', i===2));
    const res = await API.get(`/api/tests.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const results = res.data.results;
    document.getElementById('tabContent').innerHTML = results.length === 0
      ? '<div class="empty-state">테스트 결과가 없습니다</div>'
      : results.map(r => `
        <div class="card" style="margin-bottom:8px;padding:12px;background:var(--surface-card)">
          <span class="badge badge-${r.test_type==='disc'?'진행예정':'매칭대기'}">${r.test_type==='disc'?'DISC':'오감각'}</span>
          <span style="font-size:12px;color:var(--text-secondary);margin-left:8px">${r.tested_at}</span>
          <div style="margin-top:8px">${JSON.stringify(JSON.parse(r.result_data || '{}'))}</div>
        </div>
      `).join('');
  },

  async loadLogs() {
    document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.toggle('active', i===3));
    const res = await API.get(`/api/logs.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const logs = res.data.logs;
    document.getElementById('tabContent').innerHTML = logs.length === 0
      ? '<div class="empty-state">변경 이력이 없습니다</div>'
      : `<table class="data-table"><thead><tr><th>일시</th><th>변경</th><th>변경자</th></tr></thead><tbody>
          ${logs.map(l => `<tr><td style="font-size:11px;color:var(--text-secondary)">${l.created_at}</td>
          <td style="font-size:12px">${l.action}</td><td style="font-size:12px">${l.actor_name||l.actor_type}</td></tr>`).join('')}
        </tbody></table>`;
  },
});
```

- [ ] **Step 5: Verify coach page in browser**

1. In admin, create a coach with login credentials
2. Create a member with an order assigned to that coach
3. Open https://pt.soritune.com/coach/ — log in as the coach
4. Verify: only assigned members show, can complete sessions, can add notes, cannot edit member info or create orders

- [ ] **Step 6: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/coach/
git commit -m "feat: coach frontend — restricted member chart with session completion and notes"
```

---

## Task 10: Routing + Final Wiring

Wire up `public_html/index.php` to redirect to admin or coach pages.

**Files:**
- Modify: `public_html/index.php`

- [ ] **Step 1: Replace index.php with router**

Replace the placeholder `public_html/index.php` with:

```php
<?php
/**
 * PT Management System — Entry Point
 * Redirects to admin or coach page based on session.
 */
require_once __DIR__ . '/includes/auth.php';

$user = getCurrentUser();

if ($user) {
    if ($user['role'] === 'admin') {
        header('Location: /admin/');
    } else {
        header('Location: /coach/');
    }
    exit;
}

// Default: show choice page
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SoriTune PT</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="login-wrapper">
  <div class="login-card">
    <div class="login-logo">SoriTune PT</div>
    <div class="login-subtitle" style="margin-bottom:24px">로그인 유형을 선택하세요</div>
    <div style="display:flex;flex-direction:column;gap:12px">
      <a href="/admin/" class="btn btn-primary" style="text-decoration:none;text-align:center">관리자</a>
      <a href="/coach/" class="btn btn-secondary" style="text-decoration:none;text-align:center">코치</a>
    </div>
  </div>
</div>
</body>
</html>
```

- [ ] **Step 2: Verify full flow**

1. Open https://pt.soritune.com/ — should show login type selection
2. Click 관리자 → admin login → full admin flow
3. Logout → Click 코치 → coach login → restricted flow

- [ ] **Step 3: Commit**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/index.php
git commit -m "feat: entry point router — redirects to admin/coach based on session"
```

- [ ] **Step 4: Final commit — add all remaining files**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add -A
git status
git commit -m "chore: ensure all project files are tracked"
```
