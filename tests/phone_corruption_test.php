<?php
declare(strict_types=1);

t_section('isPhoneCorrupted — 손상 패턴 감지');

t_assert_true(isPhoneCorrupted('821047+11'), '821047+11 → 손상');
t_assert_true(isPhoneCorrupted('82104+11'), '82104+11 → 손상');
t_assert_true(isPhoneCorrupted('821089+11'), '821089+11 → 손상');
t_assert_true(isPhoneCorrupted('  821047+11  '), '앞뒤 공백 있어도 손상 감지');

t_assert_eq(false, isPhoneCorrupted('01012345678'), '01012345678 → 정상');
t_assert_eq(false, isPhoneCorrupted('010-1234-5678'), '010-1234-5678 → 정상');
t_assert_eq(false, isPhoneCorrupted('+821012345678'), '+821012345678 → 정상 (+ 시작)');
t_assert_eq(false, isPhoneCorrupted('+17815726907'), '+17815726907 → 정상 (US +1)');
t_assert_eq(false, isPhoneCorrupted('6590087435'), '6590087435 → 정상 (싱가포르)');
t_assert_eq(false, isPhoneCorrupted(''), '빈 문자열 → false');
t_assert_eq(false, isPhoneCorrupted(null), 'null → false');

t_section('normalizePhone — 기존 동작 유지 확인');

t_assert_eq('01012345678', normalizePhone('01012345678'), '010 그대로');
t_assert_eq('01012345678', normalizePhone('010-1234-5678'), '하이픈 제거');
t_assert_eq('01012345678', normalizePhone('+82-10-1234-5678'), '+82 → 010 변환');
t_assert_eq(null, normalizePhone(''), '빈 문자열 → null');
t_assert_eq(null, normalizePhone(null), 'null → null');
