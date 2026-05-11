'use strict';

const DISC_CATEGORIES = {
  D: {
    title: '주도형',
    subtitle: '도전적이고 결과 지향',
    keywords: ['도전', '결과', '빠른 결정'],
    content: "<ul>\n<li>명확한 목표를 향해 적극적으로 추진하는 스타일</li>\n<li>빠른 결정과 결과 확인을 선호 — 답답한 진행은 지루함</li>\n<li>도전적인 환경과 새로운 시도에서 활기를 얻음</li>\n<li>의견을 직설적으로 표현하고 자기 주장이 분명함</li>\n</ul>",
    learning: "<ul>\n<li>명확한 목표와 기한이 있는 학습 플랜에 강함</li>\n<li>빠른 피드백·결과 확인을 통해 진도 체감</li>\n<li>코치와의 관계는 효율 중심 — 단도직입적이고 명확한 코칭 선호</li>\n<li>답답한 반복보다 새로운 도전 과제로 자극 필요</li>\n</ul>",
  },
  I: {
    title: '사교형',
    subtitle: '설득과 관계 중심',
    keywords: ['설득', '관계', '낙천'],
    content: "<ul>\n<li>말솜씨와 에너지로 사람들을 끌어당기는 스타일</li>\n<li>관계 속에서 학습 효과가 극대화 — 혼자보다 함께 학습이 잘 맞음</li>\n<li>칭찬·인정에 강하게 반응하며 동기부여 받음</li>\n<li>새로운 경험과 다양한 시도를 즐기고 즉흥적</li>\n</ul>",
    learning: "<ul>\n<li>대화·상호작용이 풍부한 코칭에서 빛남</li>\n<li>칭찬과 격려를 적극 받아 자신감 쌓는 학습이 효과적</li>\n<li>코치와의 관계는 친근·열정·재미 — 분위기가 학습 효과를 좌우</li>\n<li>너무 단조롭거나 정적인 학습은 금세 흥미를 잃을 수 있음</li>\n</ul>",
  },
  S: {
    title: '안정형',
    subtitle: '협력과 인내',
    keywords: ['협력', '인내', '일관성'],
    content: "<ul>\n<li>차분하고 신뢰감 있는 태도로 꾸준히 진행하는 스타일</li>\n<li>갑작스러운 변화보다 일관성 있는 흐름을 선호</li>\n<li>깊이 있게 듣고 상대를 배려하는 능력이 강함</li>\n<li>천천히, 그러나 멀리 가는 인내심</li>\n</ul>",
    learning: "<ul>\n<li>단계적·꾸준한 진도 관리가 가장 잘 맞음</li>\n<li>안정감 있는 환경과 예측 가능한 코칭 일정에서 효과 큼</li>\n<li>코치와의 관계는 따뜻함·인내·신뢰 — 충분히 익숙해진 후에 자신감 발휘</li>\n<li>갑작스러운 방식 변화나 급한 진도는 저항감을 만들 수 있음</li>\n</ul>",
  },
  C: {
    title: '신중형',
    subtitle: '분석과 정확성',
    keywords: ['분석', '정확성', '계획'],
    content: "<ul>\n<li>데이터·근거에 기반해 신중하게 판단하는 스타일</li>\n<li>완벽함을 추구하고 세부사항을 점검하는 능력 강함</li>\n<li>충분한 자료와 시간을 가진 후 결정 내림</li>\n<li>객관성을 유지하며 감정에 휘둘리지 않음</li>\n</ul>",
    learning: "<ul>\n<li>체계적이고 논리적인 자료 기반 학습이 효과적</li>\n<li>정확한 피드백과 근거 있는 설명에 동기부여 받음</li>\n<li>코치와의 관계는 준비된 자료·논리 — 충분한 정보 제공이 신뢰의 핵심</li>\n<li>즉흥적·감정적 코칭은 거리감을 만들 수 있음</li>\n</ul>",
  },
};

const MeDiscResultView = {
  render(root, resultData) {
    const cat = DISC_CATEGORIES[resultData.primary] || DISC_CATEGORIES.D;
    const ranks = resultData.ranks || [];
    const maxScore = Math.max(...ranks.map(r => r.score || 0), 1);

    root.innerHTML = `
      <div class="me-shell">
        <header class="me-header">
          <button class="me-btn me-btn-ghost" id="meBackBtn">← 메인으로</button>
        </header>
        <main class="me-result">
          <div class="me-result-top">
            <div class="me-result-top-label">나의 DISC 유형</div>
            <h2>${MeUI.esc(resultData.primary)} ${MeUI.esc(resultData.title || cat.title)}</h2>
            <p>${MeUI.esc(resultData.subtitle || cat.subtitle)}</p>
          </div>
          <div class="me-disc-keywords">
            ${cat.keywords.map(kw => `<span class="me-disc-chip">${MeUI.esc(kw)}</span>`).join('')}
          </div>
          <div class="me-score">
            ${ranks.map(r => this.bar(r, maxScore)).join('')}
          </div>
          <div class="me-divider"></div>
          <section class="me-info">
            <div class="me-info-label">특징</div>
            ${cat.content}
          </section>
          <div class="me-divider"></div>
          <section class="me-info">
            <div class="me-info-label">학습 스타일</div>
            ${cat.learning}
          </section>
          <div class="me-result-actions">
            <button class="me-btn me-btn-outline" id="meRetry">다시 하기</button>
            <button class="me-btn me-btn-primary" id="meBackDash">메인으로</button>
          </div>
        </main>
      </div>
    `;
    document.getElementById('meBackBtn').onclick = () => MeApp.go('dashboard');
    document.getElementById('meBackDash').onclick = () => MeApp.go('dashboard');
    document.getElementById('meRetry').onclick = () => MeApp.go('test', { testType: 'disc' });

    setTimeout(() => {
      document.querySelectorAll('.me-score-fill').forEach(el => {
        el.style.width = el.dataset.width;
      });
    }, 50);
  },

  bar(rankRow, maxScore) {
    const pct = Math.round((rankRow.score / maxScore) * 100);
    const rankLabel = `${rankRow.rank}순위`;
    return `
      <div class="me-score-row">
        <span class="me-score-label">${rankRow.type}</span>
        <div class="me-score-bar"><div class="me-score-fill me-score-disc" data-width="${pct}%" style="width:0%"></div></div>
        <span class="me-score-pct">${rankRow.score}</span>
        <span class="me-disc-rank">${rankLabel}</span>
      </div>
    `;
  },
};
