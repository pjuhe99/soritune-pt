<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/helpers.php';
require_once __DIR__ . '/../public_html/api/member_tests.php';

t_section('memberTestsLatestImpl — 결과 없음');
$db = getDB();
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_self_a', '회원A', '01011110001']);
$mid = (int)$db->lastInsertId();
$user = ['id' => $mid, 'role' => 'member'];
$res = memberTestsLatestImpl($db, $user, 'sensory');
t_assert_eq(null, $res['result'], '미응시 → result=null');
$db->rollBack();

t_section('memberTestsLatestImpl — 본인 최신 row');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_self_b', '회원B', '01011110002']);
$mid = (int)$db->lastInsertId();
$user = ['id' => $mid, 'role' => 'member'];

$db->prepare("INSERT INTO test_results (member_id, test_type, result_data, tested_at) VALUES (?, ?, ?, ?)")
   ->execute([$mid, 'sensory', json_encode(['version'=>1,'key'=>'0,0,0','title'=>'균형형']), '2026-04-01']);
$db->prepare("INSERT INTO test_results (member_id, test_type, result_data, tested_at) VALUES (?, ?, ?, ?)")
   ->execute([$mid, 'sensory', json_encode(['version'=>1,'key'=>'1,1,1','title'=>'완전한 멀티 감각형 학습자']), '2026-05-08']);

$res = memberTestsLatestImpl($db, $user, 'sensory');
t_assert_eq('1,1,1', $res['result']['result_data']['key'], 'latest 가 더 최근');
t_assert_eq('2026-05-08', $res['result']['tested_at'], 'tested_at 최신');
$db->rollBack();

t_section('memberTestsLatestImpl — 다른 회원 결과 안 보임');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_self_c1', '회원C1', '01011110003']);
$midC1 = (int)$db->lastInsertId();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_self_c2', '회원C2', '01011110004']);
$midC2 = (int)$db->lastInsertId();

$db->prepare("INSERT INTO test_results (member_id, test_type, result_data, tested_at) VALUES (?, ?, ?, ?)")
   ->execute([$midC2, 'sensory', json_encode(['version'=>1,'key'=>'1,1,1','title'=>'완전한 멀티 감각형 학습자']), '2026-05-08']);

$user = ['id' => $midC1, 'role' => 'member'];
$res = memberTestsLatestImpl($db, $user, 'sensory');
t_assert_eq(null, $res['result'], 'C1 으로 조회 — C2 결과 안 보임');
$db->rollBack();

t_section('memberTestsLatestImpl — sensory/disc 분리');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_self_d', '회원D', '01011110005']);
$mid = (int)$db->lastInsertId();
$user = ['id' => $mid, 'role' => 'member'];
$db->prepare("INSERT INTO test_results (member_id, test_type, result_data, tested_at) VALUES (?, ?, ?, ?)")
   ->execute([$mid, 'sensory', json_encode(['version'=>1,'key'=>'1,1,1','title'=>'X']), '2026-05-08']);

$res = memberTestsLatestImpl($db, $user, 'sensory');
t_assert_true($res['result'] !== null, 'sensory 있음');
$res = memberTestsLatestImpl($db, $user, 'disc');
t_assert_eq(null, $res['result'], 'disc 없음');
$db->rollBack();
