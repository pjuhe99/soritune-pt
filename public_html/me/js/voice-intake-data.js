'use strict';

/**
 * Voice Intake survey questions — JS 측. PHP 측: includes/tests/voice_intake_meta.php.
 * 동기화 가드: tests/voice_intake_php_js_parity_test.php
 *
 * 각 항목의 모든 필드(id, type, allow_other, section, text, options) 가 PHP 와 byte-identical.
 * single quote 사용 — parity 가드 정규식이 single quote 기반.
 */
const VoiceIntakeData = {
  questions: [
    {id:'q1', type:'single', allow_other:false, section:1, short_label:'성별',
     text:'성별을 알려주세요.',
     options:['여성','남성']},
    {id:'q2', type:'single', allow_other:true, section:1, short_label:'연령대',
     text:'연령대를 알려주세요.',
     options:['10대','20대','30대','40대','50대','60대','70대 이상','기타']},
    {id:'q3', type:'single', allow_other:true, section:1, short_label:'거주지역',
     text:'거주지역은 어디신가요?',
     options:['국내','해외','기타']},
    {id:'q4', type:'single', allow_other:true, section:1, short_label:'학습 목표',
     text:'소리튠 영어를 하는 목표를 알려주세요.',
     options:['승진이나 취업을 위해서','업무에 필요해서','유학을 위해서','해외여행을 위해서','영어를 유창하게 하고 싶어서.','일상에서의 원활한 의사소통을 위해서','자연스러운 영어소리와 리스닝 향상을 위해서','영화나 미드를 자막없이 보기 위해서','자기 만족& 자신감을 위해서','기타']},
    {id:'q5', type:'single', allow_other:true, section:1, short_label:'하루 투자 시간',
     text:'소리튠영어 훈련하는데 하루에 투자할 수 있는 시간을 알려주세요.',
     options:['30분 이하','30분~1시간','1시간~2시간','2시간~3시간','3시간~4시간','4시간 이상','기타']},
    {id:'q6', type:'single', allow_other:true, section:1, short_label:'훈련 시간대',
     text:'주로 훈련하는 시간대를 알려주세요. (한국시간 기준)',
     options:['오전(6시~12시)','오후(12시~18시)','저녁(18시~0시)','새벽(0시~6시)','기타']},
    {id:'q7', type:'single', allow_other:true, section:2, short_label:'지속 어려움',
     text:'그동안 영어 공부를 지속하기 어려웠던 상황은 무엇인가요.',
     options:['낮은 영어 훈련 의지','불규칙한 생활 패턴','바쁜 일상으로 훈련 시간 부족','나에게 맞는 훈련법이 없음','기타']},
    {id:'q8', type:'single', allow_other:true, section:2, short_label:'코칭 도움',
     text:'음성코칭서비스를 통해 어떤 도움을 받고 싶나요.',
     options:['꾸준히 하는 훈련 습관 형성','함께 한다는 심리적 지지','끝까지 완주하는 성취감','코치와의 소통','기타']},
    {id:'q9', type:'single', allow_other:true, section:2, short_label:'코치 스타일',
     text:'원하시는 코치 스타일을 알려주세요.',
     options:['타이트하게 끌어주는 코치','상냥하게 밀어주는 코치','상관없음','기타']},
    {id:'q10', type:'multi', allow_other:false, section:2, short_label:'해당 사항',
     text:'해당 사항에 체크해주세요. (복수체크 가능)',
     options:['목소리가 잘 쉰다.','한국어 딕션이 명확하지 않다.','매일 1시간이상 영어훈련에 투자하기 어렵다.','영어공부 꾸준히 해본적이 없다.','음치박치다.','듣고 이해하는 것보다 글을 읽고 이해하는 게 빠르다.','해외 여행시 영어가 두렵다.','말을 할 때 조음기관(입, 혀, 턱)의 움직임이 크지 않다.','해당없음']},
    {id:'q11', type:'single', allow_other:false, section:2, short_label:'자기개방 편안함',
     text:'자신의 이야기를 편안하게 나눌 수 있나요?',
     options:['매우 그렇다','그렇다','그렇지않다','매우그렇지않다']},
  ],
};
window.VoiceIntakeData = VoiceIntakeData;
