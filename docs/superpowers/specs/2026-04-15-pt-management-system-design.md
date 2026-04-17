# PT Management System Design — pt.soritune.com

## Overview

soritunenglish.com 기반 PT/코치 관리 시스템. 회원차트를 중심으로 PT 구매 이력, 코치 담당 이력, 테스트 결과, 메모, 변경 로그를 통합 관리한다.

### 1차 범위

- 회원차트 DB 설계 및 관리자/코치 화면 구현
- 코치 DB 설계 및 관리 구조 구축
- 마이그레이션(스프레드시트 import) 설계 및 구현
- 회원 병합(동일인 처리) 기능

### 1차 제외

- 자동 매칭
- 코치 등급 시스템
- 정산 자동화
- 사용자용 화면
- DISC/오감각 테스트 응시 기능 (결과 표시만)

---

## Environment

- URL: https://pt.soritune.com
- Directory: `/var/www/html/_______site_SORITUNECOM_PT/`
- DB: `SORITUNECOM_PT` (MariaDB)
- Auth: `.db_credentials` (same directory)
- Git: 초기화 필요 (1차 완성 후 dev/prod 분리 예정)
- Design: CLAUDE.md에 정의된 Spotify-inspired dark theme (Soritune Orange `#FF5E00`)

---

## Tech Stack

PHP + vanilla JS, 빌드 도구 없음.

### Directory Structure

```
public_html/
  index.php              <- 진입점 (라우터)
  admin/                 <- 관리자 페이지
    index.php
    js/app.js
  coach/                 <- 코치 페이지
    index.php
    js/app.js
  api/
    members.php          <- 회원 API
    coaches.php          <- 코치 API
    orders.php           <- PT 이력 API
    merge.php            <- 병합 API
    auth.php             <- 인증 API
    import.php           <- 데이터 import API
  includes/
    db.php               <- DB 연결
    auth.php             <- 인증 미들웨어
    helpers.php          <- 공통 함수
  assets/
    css/style.css
  uploads/
    imports/             <- 업로드된 시트 보관
```

---

## DB Schema

### admins

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| login_id | VARCHAR(50) UNIQUE | 로그인 ID |
| password_hash | VARCHAR(255) | bcrypt |
| name | VARCHAR(50) | 관리자 이름 |
| status | ENUM('active','inactive') | |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### coaches

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| login_id | VARCHAR(50) UNIQUE | 로그인 ID |
| password_hash | VARCHAR(255) | bcrypt |
| coach_name | VARCHAR(100) | 영문 이름 |
| korean_name | VARCHAR(50) NULL | 한글 이름 |
| birthdate | DATE NULL | 생년월일 |
| hired_on | DATE NULL | 입사일 |
| role | ENUM('신규 코치','일반 코치','리드 코치','코칭 마스터 코치','소리 마스터 코치') NULL | 직급 |
| evaluation | ENUM('pass','fail') NULL | 평가 결과 |
| status | ENUM('active','inactive') | 활동중/비활성 |
| available | TINYINT(1) DEFAULT 1 | 배정 가능 여부 |
| max_capacity | INT DEFAULT 0 | 최대 담당 인원 |
| memo | TEXT | |
| overseas | TINYINT(1) DEFAULT 0 | 해외 거주/근무 여부 |
| side_job | TINYINT(1) DEFAULT 0 | 부업 여부 |
| soriblock_basic | TINYINT(1) DEFAULT 0 | 소리블럭 기본 가능 |
| soriblock_advanced | TINYINT(1) DEFAULT 0 | 소리블럭 심화 가능 |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### members

members는 회원차트의 표시 기준(authority)이다. 관리자가 이름/전화/이메일을 수정하면 이 테이블만 업데이트한다.
status는 저장하지 않고, 주문 상태로부터 조회 시 자동 산정한다 (아래 Member Status Auto-Calculation 참조).
현재 담당 코치도 저장하지 않고, coach_assignments(released_at IS NULL) 기준으로 조회한다.

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(100) | 이름 |
| phone | VARCHAR(20) | 휴대폰 |
| email | VARCHAR(255) | 이메일 |
| memo | TEXT | 특이사항 |
| merged_into | INT FK -> members.id NULL | 병합된 경우 대표 회원 ID |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### member_accounts

member_accounts는 원본 데이터 보관용이다. 각 출처(soritune, import 등)에서 가져온 원본 정보를 그대로 보존한다.
members 테이블의 정보를 수정해도 member_accounts는 변경하지 않는다 (원본 추적 목적).
soritune_id는 이 테이블의 source='soritune', source_id 컬럼으로 관리한다.

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| member_id | INT FK -> members.id | 소속 회원 |
| source | VARCHAR(50) | 출처 ('soritune', 'manual', 'import') |
| source_id | VARCHAR(100) | 해당 출처에서의 ID (soritune_id 등) |
| name | VARCHAR(100) | 해당 계정의 원본 이름 |
| phone | VARCHAR(20) | 해당 계정의 원본 전화번호 |
| email | VARCHAR(255) | 해당 계정의 원본 이메일 |
| is_primary | TINYINT(1) DEFAULT 0 | 대표 계정 여부 |
| created_at | DATETIME | |

### orders

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| member_id | INT FK -> members.id | |
| coach_id | INT FK -> coaches.id NULL | 담당 코치 |
| product_name | VARCHAR(200) | 상품명 |
| product_type | ENUM('period','count') | 기간형/횟수형 |
| start_date | DATE | 시작일 |
| end_date | DATE | 종료일 |
| total_sessions | INT NULL | 총 횟수 (횟수형) |
| amount | INT DEFAULT 0 | 금액 |
| status | ENUM('매칭대기','매칭완료','진행중','연기','중단','환불','종료') DEFAULT '매칭대기' | |
| memo | TEXT | |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### order_sessions

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| order_id | INT FK -> orders.id | |
| session_number | INT | 회차 번호 |
| completed_at | DATETIME NULL | 완료 시각 |
| memo | VARCHAR(255) | 회차별 메모 |
| created_at | DATETIME | |

- 횟수형 주문 생성 시 total_sessions 만큼 행을 미리 생성
- 완료 체크 시 completed_at 기록
- used_sessions는 저장하지 않음. 조회 시 COUNT(completed_at IS NOT NULL)로 파생 계산

### coach_assignments

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| member_id | INT FK -> members.id | |
| coach_id | INT FK -> coaches.id | |
| order_id | INT FK -> orders.id NULL | 관련 주문 |
| assigned_at | DATETIME | 배정일 |
| released_at | DATETIME NULL | 해제일 |
| reason | VARCHAR(255) | 변경 사유 |

### test_results

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| member_id | INT FK -> members.id | |
| test_type | ENUM('disc','sensory') | DISC / 오감각 |
| result_data | JSON | 테스트 결과 데이터 |
| tested_at | DATE | 테스트 일자 |
| memo | TEXT | |
| created_at | DATETIME | |

### member_notes

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| member_id | INT FK -> members.id | |
| author_type | ENUM('admin','coach') | 작성자 유형 |
| author_id | INT | 작성자 ID |
| content | TEXT | 내용 |
| created_at | DATETIME | |

### merge_logs

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| primary_member_id | INT | 대표 회원 |
| merged_member_id | INT | 흡수된 회원 |
| absorbed_member_data | JSON | 흡수 회원의 원본 정보 (이름, 전화, 이메일 등) |
| moved_records | JSON | 테이블별 이동된 레코드 ID 목록 |
| admin_id | INT FK -> admins.id | 실행한 관리자 |
| merged_at | DATETIME | |
| unmerged_at | DATETIME NULL | 병합 해제 시 |

### change_logs

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| target_type | ENUM('member','order','coach_assignment','merge') | 대상 유형 |
| target_id | INT | 대상 ID |
| action | VARCHAR(50) | 변경 내용 ('status_change', 'coach_change' 등) |
| old_value | JSON | 변경 전 |
| new_value | JSON | 변경 후 |
| actor_type | ENUM('admin','coach','system') | 변경 주체 |
| actor_id | INT | |
| created_at | DATETIME | |

### migration_logs

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| batch_id | VARCHAR(50) | 일괄 처리 ID |
| source_type | VARCHAR(50) | 출처 ('spreadsheet') |
| source_row | INT | 원본 행 번호 |
| target_table | VARCHAR(50) | 대상 테이블 |
| target_id | INT | 생성된 레코드 ID |
| status | ENUM('success','skipped','error') | |
| message | TEXT | 오류/스킵 사유 |
| created_at | DATETIME | |

---

## Member Status Auto-Calculation

members 테이블에 status 컬럼은 없다. 회원 대표 상태는 조회 시 해당 회원의 모든 주문 상태로부터 파생 계산한다.
관리자가 회원 상태를 바꾸려면, 개별 주문의 상태를 변경해야 한다. 회원 레벨의 직접 상태 변경은 불가.

### Priority (highest first)

주문 상태(orders.status)에서 회원 대표 상태로의 매핑.
주문 상태와 회원 대표 상태의 용어가 다른 경우 주석으로 표시.

| Rank | Order Status | -> Member Display Status | Note |
|------|-------------|-------------------------|------|
| 1 | 진행중 | 진행중 | |
| 2 | 매칭완료 | 진행예정 | 주문은 코치 배정 완료, 회원 기준으로는 "곧 시작" |
| 3 | 매칭대기 | 매칭대기 | |
| 4 | 연기 | 연기 | |
| 5 | 중단 | 중단 | |
| 6 | 환불 | 환불 | |
| 7 | 종료 | 종료 | |

- 주문이 없는 회원 -> "매칭대기"로 표시

### 계산 SQL 예시

```sql
SELECT m.*,
  COALESCE(
    (SELECT CASE o.status
       WHEN '진행중' THEN '진행중'
       WHEN '매칭완료' THEN '진행예정'
       ELSE o.status
     END
     FROM orders o
     WHERE o.member_id = m.id
     ORDER BY FIELD(o.status, '진행중','매칭완료','매칭대기','연기','중단','환불','종료')
     LIMIT 1),
    '매칭대기'
  ) AS display_status
FROM members m
```

### 현재 담당 코치 판정

members 테이블에 current_coach_id 컬럼은 없다. 현재 담당 코치는 coach_assignments 테이블에서 파생한다.

- 조건: coach_assignments WHERE member_id = ? AND released_at IS NULL
- 여러 건이면 모두 현재 담당 (여러 PT를 다른 코치에게 받는 경우)
- 회원 목록/차트에서 표시 시 JOIN으로 가져옴

### 코치의 접근 권한 판정

코치가 접근 가능한 회원 = coach_assignments WHERE coach_id = ? AND released_at IS NULL

- 매칭완료(아직 시작 전)인 주문의 코치도 접근 가능
- released_at이 채워지면 즉시 접근 불가

---

## Coach Data Sync Rules

코치 관련 데이터가 3곳에 존재하므로 역할을 명확히 구분한다.

| 필드 | 역할 | 동기화 |
|------|------|--------|
| orders.coach_id | 주문별 담당 코치. **진실 원천** | 관리자가 주문 생성/수정 시 직접 설정 |
| coach_assignments | 코치 배정 이력 + 현재 담당 판정 | 주문에 코치를 배정/변경할 때 자동으로 기록 생성 (이전 배정은 released_at 채움) |

### 주문에 코치 배정 시 자동 처리

1. orders.coach_id 설정
2. coach_assignments에 신규 행 INSERT (assigned_at = NOW)
3. 같은 order_id로 released_at IS NULL인 기존 배정이 있으면 -> released_at = NOW, reason 기록
4. change_logs에 코치 변경 기록

---

## Merge (Member Consolidation)

### Auto-Detection

- 전화번호 완전 일치하는 서로 다른 회원
- 이메일 완전 일치하는 서로 다른 회원
- 이미 병합된 회원(merged_into IS NOT NULL) 제외

### Merge Process

1. 관리자가 동일인 의심 리스트에서 2명 이상 선택
2. 대표 계정 선택
3. 미리보기 표시 (대표 정보, 통합될 데이터 건수)
4. 확인 -> 실행

### Data Handling on Merge

| Table | Action |
|-------|--------|
| orders | member_id -> primary |
| coach_assignments | member_id -> primary |
| test_results | member_id -> primary |
| member_notes | member_id -> primary |
| member_accounts | member_id -> primary |
| members (absorbed) | merged_into = primary ID, 목록에서 숨김 |

### merge_logs 저장 방식

snapshot(JSON) 대신, **이동된 레코드 ID 목록**을 테이블별로 저장한다.

```json
{
  "absorbed_member": { "id": 5, "name": "소리김", "phone": "010-...", "email": "..." },
  "moved_records": {
    "orders": [10, 11],
    "coach_assignments": [7],
    "test_results": [],
    "member_notes": [3, 4],
    "member_accounts": [8]
  }
}
```

### 병합 해제

- merge_logs.moved_records를 기반으로 각 레코드의 member_id를 원래 회원으로 복원
- 병합 이후 대표 회원에 추가된 신규 데이터(병합 시점 이후 created_at)가 있으면, 해제 전 경고 표시: "병합 후 추가된 데이터 N건이 있습니다. 이 데이터는 대표 회원에 유지됩니다."
- 해제 시: moved_records에 기록된 레코드만 원래 회원으로 이동. 병합 후 신규 데이터는 건드리지 않음
- merged_into를 NULL로 복원, merge_logs.unmerged_at 기록

---

## Migration Strategy

### Phases

```
Phase 1: DB 스키마 생성 (빈 테이블)
Phase 2: 코치 등록 (수동 또는 시트)
Phase 3: 회원 import (스프레드시트)
Phase 4: PT 이력 import (스프레드시트, 회원 매칭)
Phase 5: 동일인 검토 및 수동 병합
```

### Spreadsheet Templates

**Members:**

| 이름 | 전화번호 | 이메일 | soritune_id | 메모 |

**Orders (PT History):**

| 회원이름 | 전화번호 | 상품명 | 상품유형(기간/횟수) | 코치명(영문) | 시작일 | 종료일 | 총횟수 | 소진횟수 | 금액 | 상태 | 메모 |

### Import Rules

- 원본 시트를 서버에 보관 (uploads/imports/)
- 매 import마다 고유 batch_id 발급
- 모든 행을 migration_logs에 기록 (success/skipped/error)
- 매칭 실패 건은 별도 화면에서 수동 매칭

### 중복 방지 (Upsert 기준)

같은 파일을 다른 batch_id로 재업로드해도 중복 데이터가 생기지 않도록 자연키 기준을 정의한다.

**회원 import:**
- 자연키: soritune_id (있으면 우선) 또는 (이름 + 전화번호 정규화)
- 자연키가 일치하는 기존 회원이 있으면 -> 새로 생성하지 않고 기존 회원에 member_accounts 추가
- 일치하는 회원이 없으면 -> 신규 생성

**주문 import:**
- 자연키: (member_id + 상품명 + 시작일)
- 자연키가 일치하는 기존 주문이 있으면 -> 스킵 (migration_logs에 'skipped: duplicate' 기록)
- 일치하는 주문이 없으면 -> 신규 생성

**전화번호 정규화:** import 전 하이픈 제거, 공백 제거, 010 prefix 보정 후 비교

### Exception Handling

| Case | Action |
|------|--------|
| 전화번호 형식 불일치 | 자동 정규화 (하이픈 제거, 010 보정) |
| 필수 필드(이름) 누락 | 스킵 -> 에러 로그 |
| 코치명 DB에 없음 | 스킵 -> 에러 로그 |
| 동일 soritune_id 중복 | 기존 회원에 추가 (새로 생성 안 함) |
| 날짜 형식 오류 | 스킵 -> 에러 로그 |

---

## Admin UI

### Layout

Spotify-inspired dark theme. Sidebar + main content.

### Pages

**1. 회원관리 - 목록**
- 검색 (이름/전화/이메일)
- 필터 (상태, 담당코치)
- 테이블: 이름, 전화번호, 담당코치, 대표상태, PT건수

**2. 회원관리 - 회원차트 (상세)**

상단 고정:
- 기본 정보 (이름, 전화, 이메일, 연결 계정의 soritune_id, 대표상태(자동산정), 현재 담당코치(coach_assignments 기반))
- 정보수정 버튼 (이름/전화/이메일/메모만 수정 가능. 대표 상태는 주문 상태에서 자동 산정되므로 직접 변경 불가)

진행 중인 PT 섹션:
- 기간형: 시작~종료 기간, 남은 일수, 진행률 프로그레스바
- 횟수형: 소진/총횟수, 프로그레스바, 회차별 완료 체크리스트

탭 구조:
- PT이력: 주문 목록 + CRUD
- 코치이력: 배정/해제 이력
- 테스트결과: DISC, 오감각 결과 표시 (수동 입력)
- 메모: 관리자/코치 메모 목록 + 추가
- 변경로그: 자동 기록된 변경 이력
- 병합정보: 연결 계정, 병합 이력, 병합 해제

**3. 코치관리**
- 코치 목록 (이름, 상태, 배정가능, 담당수, 최대인원)
- 코치 CRUD

**4. 동일인관리**
- 자동 리스트 (전화/이메일 일치 기준)
- 선택 -> 대표 계정 지정 -> 병합

**5. 데이터관리**
- 시트 업로드 (회원 / PT이력)
- import 기록 목록
- 실패 건 확인 / 매칭 실패 수동 처리

---

## Coach UI

### Scope

coach_assignments WHERE coach_id = 본인 AND released_at IS NULL 기준으로 접근 가능한 회원 결정.
매칭완료(시작 전)~진행중 모두 포함. released_at이 채워지면 즉시 접근 불가.

### Pages

**1. 내 회원 목록**
- 현재 담당 회원만 표시
- 이름, 전화, PT상품, 상태

**2. 회원차트 (제한적)**
- 기본 정보: 열람만
- 진행 중인 PT: 열람 + 횟수형 회차 완료 체크
- PT이력: 열람 + 진행 상태 업데이트 (횟수 소진)
- 코치이력: 열람만
- 테스트결과: 열람만
- 메모: 열람 + 추가
- 변경로그: 열람만

### Restrictions

- 다른 코치의 회원 접근 불가
- 회원 생성/삭제/병합 불가
- 코치 배정 변경 불가
- PT이력 생성/삭제 불가 (상태 업데이트만)
- 데이터 import 불가

---

## Implementation Priority

```
Step 1: 프로젝트 초기화
  - 디렉토리 구조, DB 테이블, Git, 공통 모듈

Step 2: 인증 시스템
  - 관리자/코치 로그인, 세션, 권한 분리

Step 3: 코치 관리 (관리자)
  - 코치 CRUD, 목록/상세

Step 4: 회원 관리 (관리자)
  - 회원 CRUD, 목록(검색/필터), 회원차트 상세

Step 5: PT 이력 관리
  - 주문 CRUD, 진행 중 PT 표시, 회차 체크(order_sessions)
  - 대표 상태 자동 산정

Step 6: 코치이력 / 메모 / 테스트결과 / 변경로그
  - coach_assignments, member_notes, test_results, change_logs

Step 7: 병합 기능
  - 동일인 리스트, 병합/해제, 미리보기

Step 8: 데이터 import
  - 시트 업로드/파싱, 회원/PT이력 import, 매칭 실패 처리

Step 9: 코치 페이지
  - 내 회원 목록, 차트 열람, PT 상태 업데이트, 메모 추가
```
