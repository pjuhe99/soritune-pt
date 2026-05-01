<?php
/**
 * 시나리오: 1:1PT 카톡방 입장 리마인드 (음성PT)
 *
 * 매일 19시 KST. 이번 달 cohort 소리튜닝 음성PT 진행중 회원 중 카톡방
 * 미입장자에게 리마인드 알림톡 발송. 입장이 체크되면 다음 발송에서 자동 제외.
 *
 * 운영 적용 가이드:
 *   - is_active=0 기본. 어드민 알림톡 페이지에서 토글 ON.
 *   - cohort_mode='current_month_kst': 매월 자동으로 그 달 cohort로 이동.
 *   - cooldown 23시간: 매일 1회 도배 방지 (cron 분 단위 흔들림 흡수).
 *   - max_attempts 0: 입장할 때까지 무제한 시도.
 */
return [
    'key'         => 'pt_kakao_room_remind',
    'name'        => '카톡방 입장 리마인드 (음성PT)',
    'description' =>
          "매일 19시, 이번 달 cohort 소리튜닝 음성PT 진행중인 회원 중\n"
        . "1:1PT 카톡방 미입장자에게 리마인드 알림톡 발송.\n"
        . "회원 phone, 담당 코치, 코치 카톡방 링크가 모두 있어야 발송.\n"
        . "입장하면 다음 날부터 자동 제외.",

    'source' => [
        'type'              => 'pt_orders_query',
        'product_name'      => '소리튜닝 음성PT',
        'status'            => ['진행중'],
        'kakao_room_joined' => 0,
        'cohort_mode'       => 'current_month_kst',
    ],

    'template' => [
        'templateId'   => 'KA01TP260429031809566vKO0c8WyDAl',
        'fallback_lms' => false,
        'variables' => [
            // notify_functions.php::notifyRenderVariables 형식: '#{변수}' => 'col:컬럼' | 'const:값'
            // 솔라피 템플릿 변수명은 공백 없음(#{담당코치}, #{채팅방링크}). columns 키는 어댑터 내부 표기 유지.
            '#{회원}'      => 'col:회원',
            '#{담당코치}'  => 'col:담당 코치',
            '#{채팅방링크}' => 'col:채팅방 링크',
        ],
    ],

    'schedule'       => '0 19 * * *',  // 매일 19:00 KST
    'cooldown_hours' => 23,
    'max_attempts'   => 0,
];
