<?php
declare(strict_types=1);

t_section('kakao_check smoke');
t_assert_eq(2, 1 + 1, '1+1 == 2');

$db = getDB();

// 이후 태스크들이 여기에 cohorts / list / toggle_join / set_cohort 섹션을 추가한다.
