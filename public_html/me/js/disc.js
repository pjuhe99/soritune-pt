'use strict';

/**
 * DISC questions — JS 측. PHP 측: includes/tests/disc_meta.php.
 * 동기화 가드: tests/disc_php_js_parity_test.php
 *
 * 각 항목의 D/I/S/C 단어 매핑 순서 고정 (parity 가드는 텍스트로 파싱).
 * single quote 사용 — 정규식이 single quote 기반.
 */
const DiscData = {
  totalSteps: 10,

  questions: [
    {D:'자기 주장이 강한', I:'즐거운', S:'배려하는', C:'신중한'},
    {D:'단호한', I:'열정적인', S:'너그러운', C:'객관적인'},
    {D:'자신감 있는', I:'낙천적인', S:'인내심 있는', C:'분석적인'},
    {D:'빠르게 결정하는', I:'말솜씨가 있는', S:'잘 들어주는', C:'완벽추구적인'},
    {D:'대담한', I:'유연성이 있는', S:'양보하는', C:'계획적인'},
    {D:'적극적인', I:'호감을 주는', S:'협조하는', C:'자제하는'},
    {D:'경쟁적인', I:'친화력이 좋은', S:'일관성 있는', C:'논리적인'},
    {D:'감정에 둔감한', I:'말이 많은', S:'변화를 꺼리는', C:'위험을 회피하는'},
    {D:'독선적인', I:'충동적인', S:'우유부단한', C:'냉정한'},
    {D:'남의 말을 잘 듣지 못하는', I:'사후관리가 약한', S:'감정에 치우치는', C:'비판적인'},
  ],
};
window.DiscData = DiscData;
