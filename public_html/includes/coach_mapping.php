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
