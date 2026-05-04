<?php
declare(strict_types=1);

/**
 * 코치 교육 회차 정의 (가상 회차 — DB 테이블 없음)
 * 요일이 바뀌면 COACH_TRAINING_DOW 한 줄만 변경.
 */
const COACH_TRAINING_DOW = 4;          // ISO-8601: 1=월 ... 7=일. 4=목.
const COACH_TRAINING_RECENT_COUNT = 4; // 출석율 분모 (직전 N회)

/**
 * KST 기준 직전 N개 교육 일자(목요일)를 DESC로 반환.
 * 오늘이 교육 요일이면 오늘을 첫 번째로 포함.
 *
 * @param DateTimeImmutable $nowKst KST timezone 시각
 * @param int               $n      반환 개수 (>=1)
 * @param int               $dow    ISO-8601 요일 (1~7)
 * @return string[] DESC YYYY-MM-DD ×N (최신이 [0])
 */
function recentTrainingDates(
    DateTimeImmutable $nowKst,
    int $n = COACH_TRAINING_RECENT_COUNT,
    int $dow = COACH_TRAINING_DOW
): array {
    if ($n < 1) {
        throw new InvalidArgumentException('n must be >= 1');
    }
    if ($dow < 1 || $dow > 7) {
        throw new InvalidArgumentException('dow must be 1..7');
    }

    // 오늘 기준 가장 최근의 (오늘 포함) $dow 요일까지 거슬러 올라간다
    $today = $nowKst->setTime(0, 0, 0);
    $todayDow = (int)$today->format('N'); // 1..7
    $diff = ($todayDow - $dow + 7) % 7;    // 0~6
    $latest = $today->modify("-{$diff} days");

    $out = [];
    $cur = $latest;
    for ($i = 0; $i < $n; $i++) {
        $out[] = $cur->format('Y-m-d');
        $cur = $cur->modify('-7 days');
    }
    return $out;
}
