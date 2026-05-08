<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/tests/sensory_meta.php';

/**
 * Submit 본 로직 — 세션·HTTP 우회해서 단위테스트 가능하도록 분리.
 *
 * @param PDO   $db
 * @param array $user  ['id' => int, 'role' => 'member']
 * @param array $input ['test_type' => 'sensory', 'answers' => int[]]
 * @return array       ['result_id' => int, 'result_data' => array]
 * @throws InvalidArgumentException
 */
function memberTestsSubmitImpl(PDO $db, array $user, array $input): array
{
    $testType = $input['test_type'] ?? '';
    if (!in_array($testType, ['sensory'], true)) {
        // DISC 는 별도 spec — 본 spec 에서는 sensory 만
        throw new InvalidArgumentException("test_type must be 'sensory'");
    }

    $answers = $input['answers'] ?? null;
    if (!is_array($answers)) {
        throw new InvalidArgumentException('answers must be an array');
    }
    // 정수 캐스팅 후 Sensory::score 가 길이/값 검증
    $answers = array_map(static fn($a) => is_int($a) ? $a : (is_numeric($a) ? (int)$a : -1), $answers);

    $resultData = Sensory::score($answers); // throws InvalidArgumentException

    $stmt = $db->prepare(
        "INSERT INTO test_results (member_id, test_type, result_data, tested_at, memo)
         VALUES (?, ?, ?, ?, NULL)"
    );
    $stmt->execute([
        (int)$user['id'],
        $testType,
        json_encode($resultData, JSON_UNESCAPED_UNICODE),
        date('Y-m-d'),
    ]);

    return [
        'result_id'   => (int)$db->lastInsertId(),
        'result_data' => $resultData,
    ];
}

/**
 * 회원 본인의 최신 결과 1건 조회.
 *
 * @return array ['result' => array|null]
 */
function memberTestsLatestImpl(PDO $db, array $user, string $testType): array
{
    if (!in_array($testType, ['sensory', 'disc'], true)) {
        throw new InvalidArgumentException("test_type must be sensory|disc");
    }
    $stmt = $db->prepare(
        "SELECT id, member_id, test_type, result_data, tested_at, memo, created_at
         FROM test_results
         WHERE member_id = ? AND test_type = ?
         ORDER BY tested_at DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([(int)$user['id'], $testType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['result' => null];

    $row['result_data'] = json_decode($row['result_data'], true);
    return ['result' => $row];
}

// 테스트가 require 했을 때는 라우팅 실행 안 함
if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'member_tests.php') {
    return;
}

header('Content-Type: application/json; charset=utf-8');
$user = requireMember();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'submit': {
        $input = getJsonInput();
        try {
            $out = memberTestsSubmitImpl($db, $user, $input);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage(), 400);
        }
        jsonSuccess($out, '결과가 저장되었습니다');
    }

    case 'latest': {
        $testType = (string)($_GET['test_type'] ?? '');
        try {
            $out = memberTestsLatestImpl($db, $user, $testType);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage(), 400);
        }
        jsonSuccess($out);
    }

    default:
        jsonError('알 수 없는 액션입니다', 400);
}
