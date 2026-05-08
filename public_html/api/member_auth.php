<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * 입력값(소리튠 ID 또는 휴대폰) → members row 또는 null.
 * 다중 매칭은 created_at DESC LIMIT 1.
 * merged_into 가 있으면 primary 까지 follow (최대 5단).
 *
 * 별도 함수로 분리한 이유: 단위 테스트 가능 + 향후 다른 곳에서도 재사용.
 */
function lookupMemberByInput(PDO $db, string $raw): ?array
{
    $input = trim($raw);
    if ($input === '') return null;

    $digitsOnly = preg_replace('/\D/', '', $input);
    $isPhoneLike = $digitsOnly !== '' && strlen($digitsOnly) >= 8;

    // phone 정규화 — 기존 helpers.php 의 normalizePhone() 재사용 (+82/82 → 010 처리)
    $phoneCandidate = $isPhoneLike ? normalizePhone($input) : null;

    $bySoritune = function () use ($db, $input): ?array {
        $stmt = $db->prepare(
            "SELECT id, soritune_id, name, phone, merged_into
             FROM members WHERE soritune_id = ?
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$input]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    };

    $byPhone = function () use ($db, $phoneCandidate): ?array {
        if ($phoneCandidate === null) return null;
        $stmt = $db->prepare(
            "SELECT id, soritune_id, name, phone, merged_into
             FROM members WHERE phone = ?
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$phoneCandidate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    };

    $member = $isPhoneLike ? ($byPhone() ?? $bySoritune()) : ($bySoritune() ?? $byPhone());
    if ($member === null) return null;

    // merged_into follow-through (최대 5단 — 무한루프 방지)
    $hops = 0;
    while ($member['merged_into'] !== null && $hops < 5) {
        $stmt = $db->prepare(
            "SELECT id, soritune_id, name, phone, merged_into FROM members WHERE id = ?"
        );
        $stmt->execute([$member['merged_into']]);
        $next = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$next) break;
        $member = $next;
        $hops++;
    }

    return $member;
}

// 테스트에서 require 했을 때는 라우팅 분기 실행 안 함
if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'member_auth.php') {
    return;
}

header('Content-Type: application/json; charset=utf-8');
$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {
    case 'login': {
        $body = getJsonInput();
        $input = (string)($body['input'] ?? '');
        if (trim($input) === '') {
            jsonError('소리튠 아이디 또는 휴대폰번호를 입력해주세요', 400);
        }
        $member = lookupMemberByInput($db, $input);
        if (!$member) {
            http_response_code(401);
            echo json_encode([
                'ok' => false,
                'code' => 'NOT_FOUND',
                'message' => '입력하신 정보로 등록된 회원을 찾을 수 없습니다. 고객센터로 문의해주세요.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        startAuthSession();
        session_regenerate_id(true);
        $_SESSION['pt_user'] = [
            'id'          => (int)$member['id'],
            'role'        => 'member',
            'soritune_id' => $member['soritune_id'],
            'name'        => $member['name'],
        ];
        jsonSuccess([
            'member' => [
                'id'          => (int)$member['id'],
                'soritune_id' => $member['soritune_id'],
                'name'        => $member['name'],
            ],
        ], '로그인 성공');
    }

    case 'logout': {
        startAuthSession();
        $_SESSION = [];
        session_destroy();
        jsonSuccess([], '로그아웃 되었습니다');
    }

    case 'me': {
        $user = getCurrentUser();
        if (!$user || ($user['role'] ?? null) !== 'member') {
            http_response_code(401);
            echo json_encode([
                'ok' => false,
                'code' => 'UNAUTHENTICATED',
                'message' => '회원 로그인이 필요합니다',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        jsonSuccess([
            'member' => [
                'id'          => (int)$user['id'],
                'soritune_id' => $user['soritune_id'] ?? null,
                'name'        => $user['name'] ?? null,
            ],
        ]);
    }

    default:
        jsonError('알 수 없는 액션입니다', 400);
}
