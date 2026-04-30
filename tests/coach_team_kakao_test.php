<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/coach_team.php';

t_section('normalizeKakaoRoomUrl — null/empty 정규화');
t_assert_eq(null, normalizeKakaoRoomUrl(null), 'null → null');
t_assert_eq(null, normalizeKakaoRoomUrl(''), '"" → null');
t_assert_eq(null, normalizeKakaoRoomUrl('   '), '공백만 → null');

t_section('normalizeKakaoRoomUrl — 정상 URL 통과');
t_assert_eq(
    'https://open.kakao.com/o/sz1en1ag',
    normalizeKakaoRoomUrl('https://open.kakao.com/o/sz1en1ag'),
    'open.kakao.com/o/...'
);
t_assert_eq(
    'https://open.kakao.com/me/raina',
    normalizeKakaoRoomUrl('https://open.kakao.com/me/raina'),
    'open.kakao.com/me/...'
);
t_assert_eq(
    'https://open.kakao.com/o/sBcGGboi',
    normalizeKakaoRoomUrl('  https://open.kakao.com/o/sBcGGboi  '),
    '앞뒤 공백은 trim'
);

t_section('normalizeKakaoRoomUrl — 잘못된 URL 거부');
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('http://open.kakao.com/o/abc'),
    InvalidArgumentException::class,
    'http:// 거부'
);
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('https://kakao.com/o/abc'),
    InvalidArgumentException::class,
    'open. 누락 거부'
);
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('https://open.kakao.com/x/abc'),
    InvalidArgumentException::class,
    '/o/ 또는 /me/ 외 path 거부'
);
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('https://open.kakao.com/o/<script>'),
    InvalidArgumentException::class,
    '특수문자 거부'
);
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('javascript:alert(1)'),
    InvalidArgumentException::class,
    'javascript: 스킴 거부'
);
