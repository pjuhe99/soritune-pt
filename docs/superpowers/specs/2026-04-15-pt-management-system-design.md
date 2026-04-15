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
| status | ENUM('active','inactive') | 활동중/비활성 |
| available | TINYINT(1) DEFAULT 1 | 배정 가능 여부 |
| max_capacity | INT DEFAULT 0 | 최대 담당 인원 |
| memo | TEXT | |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### members

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(100) | 이름 |
| phone | VARCHAR(20) | 휴대폰 |
| email | VARCHAR(255) | 이메일 |
| soritune_id | VARCHAR(100) | soritunenglish.com ID |
| current_coach_id | INT FK -> coaches.id NULL | 현재 담당 코치 |
| status | ENUM('매칭대기','진행예정','진행중','연기','중단','환불','종료') DEFAULT '매칭대기' | 대표 상태 |
| memo | TEXT | 특이사항 |
| merged_into | INT FK -> members.id NULL | 병합된 경우 대표 회원 ID |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### member_accounts

| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT PK | |
| member_id | INT FK -> members.id | 소속 회원 |
| source | VARCHAR(50) | 출처 ('soritune', 'manual', 'import') |
| source_id | VARCHAR(100) | 해당 출처에서의 ID |
| name | VARCHAR(100) | 해당 계정의 이름 |
| phone | VARCHAR(20) | 해당 계정의 전화번호 |
| email | VARCHAR(255) | 해당 계정의 이메일 |
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
| used_sessions | INT DEFAULT 0 | 소진 횟수 (횟수형) |
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
- 완료 체크 시 completed_at 기록 + orders.used_sessions 자동 증가

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
| snapshot | JSON | 병합 전 데이터 스냅샷 |
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

회원의 `status`는 주문 상태가 변경될 때마다 재계산한다.

### Priority (highest first)

| Rank | Order Status | -> Member Status |
|------|-------------|-----------------|
| 1 | 진행중 | 진행중 |
| 2 | 매칭완료 | 진행예정 |
| 3 | 매칭대기 | 매칭대기 |
| 4 | 연기 | 연기 |
| 5 | 중단 | 중단 |
| 6 | 환불 | 환불 |
| 7 | 종료 | 종료 |

### current_coach_id Auto-Update

- 진행중 주문이 있으면 -> 해당 주문의 coach_id (여러 개면 가장 최근 시작일)
- 진행중 주문이 없으면 -> NULL

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

- merge_logs에 병합 전 스냅샷(JSON) 저장
- 병합 해제 시 snapshot 기반 복원 + unmerged_at 기록

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
- 중복 방지: 같은 batch_id 재실행 불가
- 매칭 실패 건은 별도 화면에서 수동 매칭

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
- 기본 정보 (이름, 전화, 이메일, soritune_id, 대표상태, 담당코치)
- 정보수정 / 상태변경 버튼

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

현재 담당 중인 회원만 접근 가능. 담당 종료/코치 변경 후 접근 불가.

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
