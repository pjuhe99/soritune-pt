<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/helpers.php';

/**
 * lookupMemberByInput() — api/member_auth.php 내부 함수를 테스트.
 * 호출 가능하도록 require 후 테스트한다.
 */
require_once __DIR__ . '/../public_html/api/member_auth.php';

t_section('lookupMemberByInput — 빈 입력');
$db = getDB();
t_assert_eq(null, lookupMemberByInput($db, ''), '빈 문자열');
t_assert_eq(null, lookupMemberByInput($db, '   '), '공백만');

t_section('lookupMemberByInput — soritune_id 매칭');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_login_a', '회원A', '01011112222']);
$idA = (int)$db->lastInsertId();

$m = lookupMemberByInput($db, 't_login_a');
t_assert_eq($idA, $m['id'] ?? null, 'soritune_id 정확 매칭');
$db->rollBack();

t_section('lookupMemberByInput — phone 정규화');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_login_b', '회원B', '01033334444']);
$idB = (int)$db->lastInsertId();

$m = lookupMemberByInput($db, '010-3333-4444');
t_assert_eq($idB, $m['id'] ?? null, 'phone 하이픈 정규화');

$m = lookupMemberByInput($db, '+82 10 3333 4444');
t_assert_eq($idB, $m['id'] ?? null, 'phone +82 정규화');

$m = lookupMemberByInput($db, '01033334444');
t_assert_eq($idB, $m['id'] ?? null, 'phone digits-only');
$db->rollBack();

t_section('lookupMemberByInput — 매칭 없음');
$db->beginTransaction();
$m = lookupMemberByInput($db, 'nonexistent_user_xyz');
t_assert_eq(null, $m, 'soritune_id 없음');
$m = lookupMemberByInput($db, '01099998888');
t_assert_eq(null, $m, 'phone 없음');
$db->rollBack();

t_section('lookupMemberByInput — 다중 매칭 → created_at DESC LIMIT 1');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone, created_at) VALUES (?, ?, ?, ?)")
   ->execute(['t_dup_old', '오래된회원', '01055556666', '2024-01-01 00:00:00']);
$db->prepare("INSERT INTO members (soritune_id, name, phone, created_at) VALUES (?, ?, ?, ?)")
   ->execute(['t_dup_new', '최신회원',   '01055556666', '2026-01-01 00:00:00']);
$idNew = (int)$db->lastInsertId();

$m = lookupMemberByInput($db, '01055556666');
t_assert_eq($idNew, $m['id'] ?? null, '같은 폰 → 최신 회원 선택');
$db->rollBack();

t_section('lookupMemberByInput — 병합된 회원 follow-through');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_primary', 'Primary회원', '01077778888']);
$idPrimary = (int)$db->lastInsertId();
$db->prepare("INSERT INTO members (soritune_id, name, phone, merged_into) VALUES (?, ?, ?, ?)")
   ->execute(['t_merged', '병합된회원', '01099990000', $idPrimary]);

$m = lookupMemberByInput($db, 't_merged');
t_assert_eq($idPrimary, $m['id'] ?? null, 'merged → primary follow');
$db->rollBack();
